<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mnonm\FiscalAdapter\Arca\Wscpe\AnnulmentReason;
use Mnonm\FiscalAdapter\Arca\Wscpe\ArcaWaybillAnnulment;
use Mnonm\FiscalAdapter\Contracts\Issuable;
use Mnonm\FiscalAdapter\Exceptions\UnsupportedDocument;
use Mnonm\FiscalAdapter\Tests\Support\Fixtures;
use Mnonm\FiscalAdapter\Tests\Support\WscpeResponses as W;

function identityResponse(string $driver = '20123456786', string $plates = '<dominio>AB123CD</dominio>', string $gross = '45000'): string
{
    return W::response('ConsultarCPEAutomotorResp', W::HEADER
        ."<datosCarga><pesoBruto>{$gross}</pesoBruto></datosCarga>"
        ."<transporte>{$plates}<cuitChofer>{$driver}</cuitChofer></transporte>");
}

describe('issue reconciliation', function () {
    it('adopts the waybill when identity matches', function () {
        [$authority, $context] = authority();
        fakeWscpe(W::response('ConsultarCPEAutomotorResp', W::HEADER.W::IDENTITY));

        $outcome = $authority->reconcile($context, Fixtures::waybill(['plates' => ['AB123CD']]));

        expect($outcome->isAuthorized())->toBeTrue()->and($outcome->authorityId)->toBe('101234567890');
    });

    it('matches plates in any order and case', function () {
        [$authority, $context] = authority();
        fakeWscpe(identityResponse(plates: '<dominio>AC456DE</dominio><dominio>AB123CD</dominio>'));

        $outcome = $authority->reconcile($context, Fixtures::waybill(['plates' => ['ab123cd', 'ac456de']]));

        expect($outcome->isAuthorized())->toBeTrue();
    });

    it('rejects when another truck holds the number', function () {
        [$authority, $context] = authority();
        fakeWscpe(identityResponse('20301234563', '<dominio>ZZ999ZZ</dominio>', '44000'));

        $outcome = $authority->reconcile($context, Fixtures::waybill(['plates' => ['AB123CD']]));

        expect($outcome->isRejected())->toBeTrue()->and($outcome->reasonCodes())->toBe(['order_number_taken']);
    });

    it('not found means safe to resend', function () {
        [$authority, $context] = authority();
        fakeWscpe(W::response('ConsultarCPEAutomotorResp', W::error('2010', 'No existe')));

        $outcome = $authority->reconcile($context, Fixtures::waybill());

        expect($outcome->isUnavailable())->toBeTrue()->and($outcome->reasonCodes())->toBe(['not_found']);
    });

    it('includes the ARCA errors in the not found message', function () {
        [$authority, $context] = authority();
        fakeWscpe(W::response('ConsultarCPEAutomotorResp', W::error('2010', 'No existe')));

        $outcome = $authority->reconcile($context, Fixtures::waybill());

        expect($outcome->reasons[0]->code)->toBe('not_found')->and($outcome->reasons[0]->message)->toContain('ARCA respondió: 2010 No existe');
    });

    it('missing identity fields do not match', function () {
        [$authority, $context] = authority();
        fakeWscpe(W::response('ConsultarCPEAutomotorResp', W::HEADER));

        $outcome = $authority->reconcile($context, Fixtures::waybill(['plates' => ['AB123CD']]));

        expect($outcome->isRejected())->toBeTrue()->and($outcome->reasonCodes())->toBe(['order_number_taken']);
    });

    it('rejects foreign documents', function () {
        [$authority, $context] = authority();

        $authority->reconcile($context, new class implements Issuable {});
    })->throws(UnsupportedDocument::class);
});

describe('annulment reconciliation', function () {
    it('annulment reconciles by ctg when known', function (string $state, string $expected) {
        [$authority, $context] = authority();
        fakeWscpe(W::response('ConsultarCPEAutomotorResp', str_replace('<estado>AC', "<estado>{$state}", W::HEADER)));

        $outcome = $authority->reconcile($context, new ArcaWaybillAnnulment(1, 419, AnnulmentReason::Other, 'x', '101234567890'));

        expect($outcome->{$expected}())->toBeTrue();
        Http::assertSent(fn (Request $request) => str_contains($request->body(), '<nroCTG>101234567890</nroCTG>'));
    })->with([['AN', 'isAuthorized'], ['AC', 'isUnavailable']]);

    it('keeps not_annulled reason when still active', function () {
        [$authority, $context] = authority();
        fakeWscpe(W::response('ConsultarCPEAutomotorResp', W::HEADER));

        $outcome = $authority->reconcile($context, new ArcaWaybillAnnulment(1, 419, AnnulmentReason::Other, 'x'));

        expect($outcome->reasonCodes())->toBe(['not_annulled']);
        Http::assertSent(fn (Request $request) => ! str_contains($request->body(), '<nroCTG>') && str_contains($request->body(), '<nroOrden>419</nroOrden>'));
    });
});

it('internal error during reconcile stays unknown', function (string $kind) {
    [$authority, $context] = authority();
    fakeWscpe(W::response('ConsultarCPEAutomotorResp', W::error('500')));
    $document = $kind === 'issue' ? Fixtures::waybill() : annulment();

    $outcome = $authority->reconcile($context, $document);

    expect($outcome->isUnknown())->toBeTrue()->and($outcome->reasonCodes())->toBe(['internal_error']);
})->with(['issue', 'annulment']);

describe('lookup failures', function () {
    it('a WSCPE client fault during reconcile is unknown, never rejected', function (string $kind) {
        [$authority, $context] = authority();
        Http::fake(['*wscpe*' => Http::response(soapClientFault('Token expirado'), 500)]);
        $document = $kind === 'issue' ? Fixtures::waybill() : annulment();

        $outcome = $authority->reconcile($context, $document);

        expect($outcome->isUnknown())->toBeTrue()
            ->and($outcome->reasonCodes())->toBe(['reconcile_failed'])
            ->and($outcome->reasons[0]->message)->toContain('soap_client_fault')->toContain('Token expirado');
    })->with(['issue', 'annulment']);

    it('a WSAA client fault during reconcile is never rejected', function (string $kind) {
        [$authority, $context] = authority(seedTicket: false);
        Http::fake(['*wsaa*' => Http::response(soapClientFault('Certificado expirado'), 500)]);
        $document = $kind === 'issue' ? Fixtures::waybill() : annulment();

        $outcome = $authority->reconcile($context, $document);

        expect($outcome->isRejected())->toBeFalse();
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'wscpe'));
    })->with(['issue', 'annulment']);
});
