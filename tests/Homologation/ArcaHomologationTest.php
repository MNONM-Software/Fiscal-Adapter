<?php

use Mnonm\FiscalAdapter\Arca\ArcaAuthority;
use Mnonm\FiscalAdapter\Arca\ArcaEndpoints;
use Mnonm\FiscalAdapter\Arca\Wsaa\TicketStore;
use Mnonm\FiscalAdapter\CallContext;
use Mnonm\FiscalAdapter\Credential;
use Mnonm\FiscalAdapter\Environment;
use Mnonm\FiscalAdapter\Facades\Fiscal;
use Mnonm\FiscalAdapter\Outcome;
use Mnonm\FiscalAdapter\Tests\Support\FileTicketStore;
use Mnonm\FiscalAdapter\Tests\Support\Fixtures;

/*
 * Opt-in suite against ARCA homologation. It never runs in CI (group `homologation` is excluded in
 * phpunit.xml and applied to this directory in tests/Pest.php). It needs FISCAL_HOMO_CERT,
 * FISCAL_HOMO_KEY and FISCAL_HOMO_CUIT; without them every test is skipped.
 */

function homologationContext(): CallContext
{
    return new CallContext(
        Credential::fromFiles((string) getenv('FISCAL_HOMO_CERT'), (string) getenv('FISCAL_HOMO_KEY')),
        (string) getenv('FISCAL_HOMO_CUIT'),
        Environment::Homologation,
    );
}

function homologationAuthority(): ArcaAuthority
{
    app()->singleton(TicketStore::class, FileTicketStore::class);

    $authority = Fiscal::authority('arca');
    assert($authority instanceof ArcaAuthority);

    return $authority;
}

function assertAnsweredByArca(Outcome $outcome): void
{
    expect($outcome->isUnknown())->toBeFalse('ARCA no respondió de forma interpretable: '.implode(',', $outcome->reasonCodes()));
    expect($outcome->isUnavailable())->toBeFalse('ARCA no disponible: '.implode(',', $outcome->reasonCodes()));
}

beforeEach(function () {
    foreach (['FISCAL_HOMO_CERT', 'FISCAL_HOMO_KEY', 'FISCAL_HOMO_CUIT'] as $variable) {
        if ((string) getenv($variable) === '') {
            $this->markTestSkipped("Falta la variable {$variable}.");
        }
    }
});

it('points only at homologation endpoints', function () {
    $endpoints = new ArcaEndpoints((array) config('fiscal-adapter.arca.endpoints', []));

    expect(homologationContext()->environment)->toBe(Environment::Homologation)
        ->and($endpoints->url('wsaa', Environment::Homologation))->toBe('https://wsaahomo.afip.gov.ar/ws/services/LoginCms')
        ->and($endpoints->url('wscpe', Environment::Homologation))->toBe('https://cpea-ws-qaext.afip.gob.ar/wscpe/services/soap');
});

it('authenticates and lists provinces', function () {
    $outcome = homologationAuthority()->provinces(homologationContext());

    expect($outcome->isAuthorized())->toBeTrue(implode(',', $outcome->reasonCodes()));

    $items = $outcome->data['items'];

    expect($items)->toHaveCount(24)
        ->and(array_column($items, 'code'))->toContain(0);
});

it('lists localities of a province', function () {
    $outcome = homologationAuthority()->localities(homologationContext(), 9);

    expect($outcome->isAuthorized())->toBeTrue(implode(',', $outcome->reasonCodes()))
        ->and(count($outcome->data['items']))->toBeGreaterThan(0);
});

it('reads the last order number', function () {
    $outcome = homologationAuthority()->lastOrderNumber(homologationContext(), 1);

    assertAnsweredByArca($outcome);
    expect($outcome->isAuthorized() || $outcome->isRejected())->toBeTrue();
});

it('gets an issue answered by ARCA', function () {
    $authority = homologationAuthority();
    $context = homologationContext();

    $last = $authority->lastOrderNumber($context, 1);
    assertAnsweredByArca($last);

    $next = $last->isAuthorized() ? $last->data['last_order_number'] + 1 : 1;

    $outcome = $authority->issue($context, Fixtures::waybill(['orderNumber' => $next]));

    assertAnsweredByArca($outcome);
    expect($outcome->isRejected())->toBeTrue('Homologación debía rechazar: '.$outcome->status->value.' '.implode(',', $outcome->reasonCodes()));
});
