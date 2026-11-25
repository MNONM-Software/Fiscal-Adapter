<?php

use Illuminate\Support\Facades\Http;
use Mnonm\FiscalAdapter\CallContext;
use Mnonm\FiscalAdapter\CallRecord;
use Mnonm\FiscalAdapter\Tests\Support\WsaaResponses;
use Mnonm\FiscalAdapter\Tests\Support\WscpeResponses as W;

/*
 * The caller's reference travels from the context to every record of the calls it triggers, so
 * the application can tie each logged call to its own request.
 */

it('carries the reference to the WSAA login and to the WSCPE call it triggers', function () {
    $observer = recordingObserver();
    [$authority, $context] = authority(seedTicket: false, observer: $observer);
    Http::fake([
        '*wsaa*' => Http::response(WsaaResponses::loginCms('TOK', 'SIG', '2026-10-08T21:00:00-03:00'), 200),
        '*wscpe*' => Http::response(W::response('ConsultarUltNroOrdenResp', '<nroOrden>418</nroOrden>'), 200),
    ]);

    $authority->lastOrderNumber(new CallContext($context->credential, $context->representedTaxId, $context->environment, 'request-7'), 1);

    expect(array_map(fn (CallRecord $record): string => $record->method, $observer->records))->toBe(['loginCms', 'consultarUltNroOrden'])
        ->and(array_map(fn (CallRecord $record): ?string => $record->reference, $observer->records))->toBe(['request-7', 'request-7']);
});

it('records a null reference when the context has none', function () {
    $observer = recordingObserver();
    [$authority, $context] = authority(observer: $observer);
    fakeWscpe(W::response('ConsultarUltNroOrdenResp', '<nroOrden>418</nroOrden>'));

    $authority->lastOrderNumber($context, 1);

    expect($context->reference)->toBeNull()
        ->and($observer->records[0]->reference)->toBeNull();
});
