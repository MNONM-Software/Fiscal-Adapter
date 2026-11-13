<?php

use Mnonm\FiscalAdapter\Arca\ArcaEndpoints;
use Mnonm\FiscalAdapter\Environment;
use Mnonm\FiscalAdapter\Exceptions\MissingEndpoint;

it('has the default urls per environment', function () {
    $endpoints = new ArcaEndpoints;

    expect($endpoints->url('wsaa', Environment::Homologation))->toBe('https://wsaahomo.afip.gov.ar/ws/services/LoginCms')
        ->and($endpoints->url('wscpe', Environment::Homologation))->toBe('https://cpea-ws-qaext.afip.gob.ar/wscpe/services/soap')
        ->and($endpoints->url('wsaa', Environment::Production))->toBe('https://wsaa.afip.gov.ar/ws/services/LoginCms')
        ->and($endpoints->url('wscpe', Environment::Production))->toBe('https://cpea-ws.afip.gob.ar/wscpe/services/soap');
});

it('applies overrides and adds services', function () {
    $endpoints = new ArcaEndpoints(['homologation' => ['wscpe' => 'https://x.test/a', 'wsfe' => 'https://x.test/b']]);

    expect($endpoints->url('wscpe', Environment::Homologation))->toBe('https://x.test/a')
        ->and($endpoints->url('wsfe', Environment::Homologation))->toBe('https://x.test/b')
        ->and($endpoints->url('wscpe', Environment::Production))->toBe('https://cpea-ws.afip.gob.ar/wscpe/services/soap');
});

it('throws MissingEndpoint naming service and environment', function () {
    (new ArcaEndpoints)->url('wsfe', Environment::Production);
})->throws(MissingEndpoint::class, 'wsfe');

it('names the environment in the message', function () {
    try {
        (new ArcaEndpoints)->url('wsfe', Environment::Production);
    } catch (MissingEndpoint $e) {
        expect($e->getMessage())->toContain('production');
    }
});
