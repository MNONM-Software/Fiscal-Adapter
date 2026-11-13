<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Mnonm\FiscalAdapter\Arca\ArcaAuthority;
use Mnonm\FiscalAdapter\Arca\Wsaa\AccessTicketProvider;
use Mnonm\FiscalAdapter\Arca\Wsaa\InMemoryTicketStore;
use Mnonm\FiscalAdapter\Arca\Wsaa\MissingTicketStore;
use Mnonm\FiscalAdapter\Arca\Wsaa\TicketStore;
use Mnonm\FiscalAdapter\CallContext;
use Mnonm\FiscalAdapter\Environment;
use Mnonm\FiscalAdapter\Facades\Fiscal;
use Mnonm\FiscalAdapter\FiscalAdapterServiceProvider;
use Mnonm\FiscalAdapter\FiscalCallRecorded;
use Mnonm\FiscalAdapter\Tests\Support\Fixtures;

it('resolves arca with a bound ticket store', function () {
    $this->app->instance(TicketStore::class, new InMemoryTicketStore);

    expect(Fiscal::authority('arca'))->toBeInstanceOf(ArcaAuthority::class)
        ->and(Fiscal::authority('arca')->name())->toBe('arca');
});

it('fails clearly without a ticket store', function () {
    Fiscal::authority('arca');
})->throws(MissingTicketStore::class, 'TicketStore');

it('merges the default config', function () {
    expect(config('fiscal-adapter.arca.timeout'))->toBe(30)
        ->and(config('fiscal-adapter.arca.lock_seconds'))->toBeNull();
});

it('keeps the lock longer than the transport timeout by default', function () {
    config(['fiscal-adapter.arca.timeout' => 45]);
    $this->app->instance(TicketStore::class, new InMemoryTicketStore);

    expect(lockSecondsOf(Fiscal::authority('arca')))->toBe(105);
});

it('honors an explicit lock_seconds', function () {
    config(['fiscal-adapter.arca.lock_seconds' => 200]);
    $this->app->instance(TicketStore::class, new InMemoryTicketStore);

    expect(lockSecondsOf(Fiscal::authority('arca')))->toBe(200);
});

it('dispatches FiscalCallRecorded end to end', function () {
    Event::fake([FiscalCallRecorded::class]);
    Http::fake(['*' => Http::response('<x/>', 200)]);
    $this->app->instance(TicketStore::class, new InMemoryTicketStore);
    $context = new CallContext(authorityCredential(), '30712345671', Environment::Homologation);

    Fiscal::authority('arca')->issue($context, Fixtures::waybill());

    Event::assertDispatched(FiscalCallRecorded::class);
});

it('publishes the config under its tag', function () {
    $paths = ServiceProvider::pathsToPublish(FiscalAdapterServiceProvider::class, 'fiscal-adapter-config');

    expect($paths)->toHaveCount(1);
});

function lockSecondsOf(ArcaAuthority $authority): int
{
    $tickets = (new ReflectionProperty($authority, 'tickets'))->getValue($authority);
    expect($tickets)->toBeInstanceOf(AccessTicketProvider::class);

    return (new ReflectionProperty($tickets, 'lockSeconds'))->getValue($tickets);
}
