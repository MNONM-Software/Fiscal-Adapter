<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mnonm\FiscalAdapter\Tests\Support\WsaaResponses;
use Mnonm\FiscalAdapter\Tests\Support\WscpeResponses as W;

function sentBody(): string
{
    $body = '';
    Http::assertSent(function (Request $request) use (&$body) {
        $body = $request->body();

        return true;
    });

    return $body;
}

describe('catalogs', function () {
    it('lists provinces in response order with trimmed names', function () {
        [$authority, $context] = authority();
        fakeWscpe(W::response('ConsultarProvinciasResp', '<provincia><codigo>0</codigo><descripcion> CABA </descripcion></provincia><provincia><codigo>1</codigo><descripcion>Buenos Aires</descripcion></provincia>'));

        $outcome = $authority->provinces($context);

        expect($outcome->isAuthorized())->toBeTrue()
            ->and($outcome->authorityId)->toBe('consultarProvincias')
            ->and($outcome->data['items'])->toBe([['code' => 0, 'name' => 'CABA'], ['code' => 1, 'name' => 'Buenos Aires']]);
        expect(sentBody())->toContain('<wsc:ConsultarProvinciasReq>')->not->toContain('<solicitud>');
    });

    it('lists localities of a province', function () {
        [$authority, $context] = authority();
        fakeWscpe(W::response('ConsultarLocalidadesPorProvinciaResp', '<localidad><codigo>10</codigo><descripcion>Rosario</descripcion></localidad><localidad><codigo>11</codigo><descripcion>Rufino</descripcion></localidad>'));

        $outcome = $authority->localities($context, 12);

        expect($outcome->isAuthorized())->toBeTrue()
            ->and($outcome->authorityId)->toBe('consultarLocalidadesPorProvincia')
            ->and($outcome->data['items'])->toBe([['code' => 10, 'name' => 'Rosario'], ['code' => 11, 'name' => 'Rufino']]);
        expect(sentBody())->toContain('<wsc:ConsultarLocalidadesPorProvinciaReq>')->toContain('<solicitud><codProvincia>12</codProvincia></solicitud>');
    });

    it('lists grain types', function () {
        [$authority, $context] = authority();
        fakeWscpe(W::response('ConsultarTiposGranoResp', '<grano><codigo>23</codigo><descripcion>Soja</descripcion></grano><grano><codigo>15</codigo><descripcion>Maiz</descripcion></grano>'));

        $outcome = $authority->grainTypes($context);

        expect($outcome->isAuthorized())->toBeTrue()
            ->and($outcome->authorityId)->toBe('consultarTiposGrano')
            ->and($outcome->data['items'])->toBe([['code' => 23, 'name' => 'Soja'], ['code' => 15, 'name' => 'Maiz']]);
        expect(sentBody())->toContain('<wsc:ConsultarTiposGranoReq>');
    });

    it('rejects a catalog that came back with errors', function () {
        [$authority, $context] = authority();
        fakeWscpe(W::response('ConsultarTiposGranoResp', W::error('1000', 'Sin permiso')));

        $outcome = $authority->grainTypes($context);

        expect($outcome->isRejected())->toBeTrue()->and($outcome->reasonCodes())->toBe(['1000']);
    });

    it('treats the ARCA 500 as unknown', function () {
        [$authority, $context] = authority();
        fakeWscpe(W::response('ConsultarProvinciasResp', W::error('500', 'Error interno')));

        expect($authority->provinces($context)->isUnknown())->toBeTrue();
    });
});

describe('last order number', function () {
    it('returns the number and sends branch and type', function () {
        [$authority, $context] = authority();
        fakeWscpe(W::response('ConsultarUltNroOrdenResp', '<nroOrden>418</nroOrden>'));

        $outcome = $authority->lastOrderNumber($context, 3);

        expect($outcome->isAuthorized())->toBeTrue()
            ->and($outcome->authorityId)->toBe('418')
            ->and($outcome->data['last_order_number'])->toBe(418);
        expect(sentBody())->toContain('<wsc:ConsultarUltNroOrdenReq>')->toContain('<solicitud><sucursal>3</sucursal><tipoCPE>74</tipoCPE></solicitud>');
    });

    it('rejects when ARCA answers with errors', function () {
        [$authority, $context] = authority();
        fakeWscpe(W::response('ConsultarUltNroOrdenResp', W::error('2000', 'Sucursal inválida')));

        $outcome = $authority->lastOrderNumber($context, 9);

        expect($outcome->isRejected())->toBeTrue()->and($outcome->reasonCodes())->toBe(['2000']);
    });

    it('is unknown when the response carries no number', function () {
        [$authority, $context] = authority();
        fakeWscpe(W::response('ConsultarUltNroOrdenResp', ''));

        expect($authority->lastOrderNumber($context, 1)->isUnknown())->toBeTrue();
    });
});

describe('forgetTicket', function () {
    it('makes the next request go through WSAA', function () {
        [$authority, $context] = authority();
        Http::fake([
            '*wsaa*' => Http::response(WsaaResponses::loginCms('NEW', 'SIG', '2026-10-09T10:00:00-03:00'), 200),
            '*wscpe*' => Http::response(W::response('ConsultarTiposGranoResp', ''), 200),
        ]);

        $authority->grainTypes($context);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'wsaa'));

        $authority->forgetTicket($context);
        $authority->grainTypes($context);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'wsaa'));
    });
});
