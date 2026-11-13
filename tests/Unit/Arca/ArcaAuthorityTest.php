<?php

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mnonm\FiscalAdapter\Arca\ArcaAuthority;
use Mnonm\FiscalAdapter\Arca\ArcaEndpoints;
use Mnonm\FiscalAdapter\Arca\Soap\SoapTransport;
use Mnonm\FiscalAdapter\Arca\Wsaa\AccessTicket;
use Mnonm\FiscalAdapter\Arca\Wsaa\AccessTicketProvider;
use Mnonm\FiscalAdapter\Arca\Wsaa\InMemoryTicketStore;
use Mnonm\FiscalAdapter\Arca\Wsaa\TicketKey;
use Mnonm\FiscalAdapter\Arca\Wsaa\WsaaClient;
use Mnonm\FiscalAdapter\Arca\Wscpe\AnnulmentReason;
use Mnonm\FiscalAdapter\Arca\Wscpe\ArcaWaybillAnnulment;
use Mnonm\FiscalAdapter\Arca\Wscpe\ArcaWaybillQuery;
use Mnonm\FiscalAdapter\CallContext;
use Mnonm\FiscalAdapter\CallObserver;
use Mnonm\FiscalAdapter\CallRecord;
use Mnonm\FiscalAdapter\Contracts\Issuable;
use Mnonm\FiscalAdapter\Credential;
use Mnonm\FiscalAdapter\Environment;
use Mnonm\FiscalAdapter\Exceptions\UnsupportedDocument;
use Mnonm\FiscalAdapter\Tests\Support\Fixtures;
use Mnonm\FiscalAdapter\Tests\Support\TestCertificate;
use Mnonm\FiscalAdapter\Tests\Support\WsaaResponses;
use Mnonm\FiscalAdapter\Tests\Support\WscpeResponses as W;

function authorityCredential(): Credential
{
    $dir = sys_get_temp_dir().'/auth-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $files = TestCertificate::create($dir);
    $credential = Credential::fromFiles($files['certificate'], $files['private_key']);
    array_map('unlink', glob($dir.'/*'));
    rmdir($dir);

    return $credential;
}

/**
 * @return array{0: ArcaAuthority, 1: CallContext}
 */
function authority(bool $seedTicket = true): array
{
    Carbon::setTestNow('2026-10-08T12:00:00Z');
    Http::swap(new Factory);
    $store = new InMemoryTicketStore;
    $observer = new class implements CallObserver
    {
        public function record(CallRecord $record): void {}
    };
    $transport = new SoapTransport($observer);
    $endpoints = new ArcaEndpoints;
    $context = new CallContext(authorityCredential(), '30712345671', Environment::Homologation);

    if ($seedTicket) {
        $store->put(new TicketKey($context->credential->fingerprint(), 'wscpe', $context->environment), new AccessTicket('TOK', 'SIG', CarbonImmutable::parse('2026-10-08T18:00:00Z')));
    }

    $tickets = new AccessTicketProvider(new WsaaClient($transport, $endpoints), $store);

    return [new ArcaAuthority($tickets, $transport, $endpoints), $context];
}

function fakeWscpe(string $body): void
{
    Http::fake(['*wscpe*' => Http::response($body, 200)]);
}

afterEach(fn () => Carbon::setTestNow());

function annulment(): ArcaWaybillAnnulment
{
    return new ArcaWaybillAnnulment(1, 419, AnnulmentReason::Other, 'x');
}

describe('issue', function () {
    it('authorized', function () {
        [$authority, $context] = authority();
        fakeWscpe(W::response('AutorizarCPEAutomotorResp', W::HEADER.'<pdf>JVBERi0=</pdf>'));

        $outcome = $authority->issue($context, Fixtures::waybill());

        expect($outcome->isAuthorized())->toBeTrue()
            ->and($outcome->authorityId)->toBe('101234567890')
            ->and($outcome->data['state'])->toBe('AC')
            ->and($outcome->data['pdf'])->toBe('JVBERi0=')
            ->and($outcome->data['issued_at']->toIso8601String())->toBe('2026-10-08T10:15:00-03:00')
            ->and($outcome->data['expires_at']->toIso8601String())->toBe('2026-10-13T10:15:00-03:00');
    });

    it('550 is authorized without pdf', function () {
        [$authority, $context] = authority();
        fakeWscpe(W::response('AutorizarCPEAutomotorResp', W::HEADER.W::error('550', 'No se pudo generar el PDF')));

        $outcome = $authority->issue($context, Fixtures::waybill());

        expect($outcome->isAuthorized())->toBeTrue()
            ->and($outcome->data['pdf'])->toBeNull()
            ->and($outcome->reasonCodes())->toBe(['550']);
    });

    it('rejected keeps code and message', function () {
        [$authority, $context] = authority();
        fakeWscpe(W::response('AutorizarCPEAutomotorResp', W::error('2002', 'Tiene cartas de porte vencidas pendientes')));

        $outcome = $authority->issue($context, Fixtures::waybill());

        expect($outcome->isRejected())->toBeTrue()
            ->and($outcome->reasons[0]->code)->toBe('2002')
            ->and($outcome->reasons[0]->message)->toBe('Tiene cartas de porte vencidas pendientes');
    });

    it('961 is rejected', function () {
        [$authority, $context] = authority();
        fakeWscpe(W::response('AutorizarCPEAutomotorResp', W::error('961')));

        $outcome = $authority->issue($context, Fixtures::waybill());

        expect($outcome->isRejected())->toBeTrue()->and($outcome->reasonCodes())->toBe(['961']);
    });

    it('internal error and already issued are unknown', function (string $code, string $reason) {
        [$authority, $context] = authority();
        fakeWscpe(W::response('AutorizarCPEAutomotorResp', W::error($code)));

        $outcome = $authority->issue($context, Fixtures::waybill());

        expect($outcome->isUnknown())->toBeTrue()->and($outcome->reasonCodes())->toBe([$reason]);
    })->with([['500', 'internal_error'], ['2241', 'already_issued']]);

    it('without ctg nor errors is rejected', function () {
        [$authority, $context] = authority();
        fakeWscpe(W::response('AutorizarCPEAutomotorResp', ''));

        $outcome = $authority->issue($context, Fixtures::waybill());

        expect($outcome->isRejected())->toBeTrue()->and($outcome->reasonCodes())->toBe(['no_ctg']);
    });

    it('missing respuesta is unknown', function () {
        [$authority, $context] = authority();
        fakeWscpe('<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body/></soap:Envelope>');

        $outcome = $authority->issue($context, Fixtures::waybill());

        expect($outcome->isUnknown())->toBeTrue()->and($outcome->reasonCodes())->toBe(['malformed_response']);
    });
});

describe('cancel', function () {
    it('annulled', function () {
        [$authority, $context] = authority();
        fakeWscpe(W::response('AnularCPEResp', str_replace('<estado>AC', '<estado>AN', W::HEADER)));

        $outcome = $authority->cancel($context, annulment());

        expect($outcome->isAuthorized())->toBeTrue()->and($outcome->data['state'])->toBe('AN');
    });

    it('annulled with 550 stays authorized', function () {
        [$authority, $context] = authority();
        fakeWscpe(W::response('AnularCPEResp', str_replace('<estado>AC', '<estado>AN', W::HEADER).W::error('550')));

        $outcome = $authority->cancel($context, annulment());

        expect($outcome->isAuthorized())->toBeTrue()->and($outcome->reasonCodes())->toBe(['550']);
    });

    it('out of time is rejected', function () {
        [$authority, $context] = authority();
        fakeWscpe(W::response('AnularCPEResp', W::error('2121', 'Plazo vencido')));

        $outcome = $authority->cancel($context, annulment());

        expect($outcome->isRejected())->toBeTrue()->and($outcome->reasonCodes())->toBe(['2121']);
    });

    it('still active is rejected', function () {
        [$authority, $context] = authority();
        fakeWscpe(W::response('AnularCPEResp', W::HEADER));

        $outcome = $authority->cancel($context, annulment());

        expect($outcome->isRejected())->toBeTrue()->and($outcome->reasonCodes())->toBe(['not_annulled']);
    });

    it('internal error is unknown', function () {
        [$authority, $context] = authority();
        fakeWscpe(W::response('AnularCPEResp', W::error('500')));

        expect($authority->cancel($context, annulment())->isUnknown())->toBeTrue();
    });
});

describe('query', function () {
    it('by ctg', function () {
        [$authority, $context] = authority();
        fakeWscpe(W::response('ConsultarCPEAutomotorResp', W::HEADER.W::IDENTITY.'<pdf>x</pdf>'));

        $outcome = $authority->query($context, ArcaWaybillQuery::byCtg('101234567890'));

        expect($outcome->isAuthorized())->toBeTrue()
            ->and($outcome->authorityId)->toBe('101234567890')
            ->and($outcome->data['driver_cuit'])->toBe('20123456786')
            ->and($outcome->data['plates'])->toBe(['AB123CD'])
            ->and($outcome->data['gross_kg'])->toBe(45000);
    });

    it('not found is rejected with the errors', function () {
        [$authority, $context] = authority();
        fakeWscpe(W::response('ConsultarCPEAutomotorResp', W::error('2000', 'No existe')));

        $outcome = $authority->query($context, ArcaWaybillQuery::byOrderNumber(1, 419));

        expect($outcome->isRejected())->toBeTrue()->and($outcome->reasonCodes())->toBe(['2000']);
    });
});

describe('wiring', function () {
    it('represents the context CUIT, not the certificate one', function () {
        [$authority, $context] = authority();
        fakeWscpe(W::response('AutorizarCPEAutomotorResp', W::HEADER));

        $authority->issue($context, Fixtures::waybill());

        Http::assertSent(fn (Request $request) => str_contains($request->body(), '<cuitRepresentada>30712345671</cuitRepresentada>')
            && $request->header('SOAPAction')[0] === 'https://serviciosjava.afip.gob.ar/wscpe/autorizarCPEAutomotor');
    });

    it('propagates ticket failures without calling WSCPE', function () {
        [$authority, $context] = authority(seedTicket: false);
        Http::fake(['*' => Http::response(WsaaResponses::alreadyHasTicket(), 500)]);

        $outcome = $authority->issue($context, Fixtures::waybill());

        expect($outcome->isUnavailable())->toBeTrue()->and($outcome->reasonCodes())->toBe(['ticket_already_held']);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'wscpe'));
    });

    it('returns transport failures as they are', function () {
        [$authority, $context] = authority();
        Http::fake(['*wscpe*' => Http::response('', 500)]);

        expect($authority->issue($context, Fixtures::waybill())->isUnknown())->toBeTrue();
    });

    it('rejects foreign documents', function () {
        [$authority, $context] = authority();

        expect(fn () => $authority->issue($context, new class implements Issuable {}))->toThrow(UnsupportedDocument::class);
    });

    it('supports only the three waybill documents', function () {
        [$authority] = authority();

        expect($authority->name())->toBe('arca')
            ->and($authority->supports(Fixtures::waybill()))->toBeTrue()
            ->and($authority->supports(annulment()))->toBeTrue()
            ->and($authority->supports(ArcaWaybillQuery::byCtg('1')))->toBeTrue()
            ->and($authority->supports(new stdClass))->toBeFalse();
    });
});
