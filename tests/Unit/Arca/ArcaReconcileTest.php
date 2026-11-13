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
