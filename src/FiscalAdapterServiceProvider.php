<?php

namespace Mnonm\FiscalAdapter;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Mnonm\FiscalAdapter\Arca\ArcaAuthority;
use Mnonm\FiscalAdapter\Arca\ArcaEndpoints;
use Mnonm\FiscalAdapter\Arca\Soap\SoapTransport;
use Mnonm\FiscalAdapter\Arca\Wsaa\AccessTicketProvider;
use Mnonm\FiscalAdapter\Arca\Wsaa\MissingTicketStore;
use Mnonm\FiscalAdapter\Arca\Wsaa\TicketStore;
use Mnonm\FiscalAdapter\Arca\Wsaa\WsaaClient;

class FiscalAdapterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/fiscal-adapter.php', 'fiscal-adapter');

        $this->app->singletonIf(CallObserver::class, EventCallObserver::class);

        $this->app->singleton(FiscalManager::class, fn (Application $app): FiscalManager => new FiscalManager([
            'arca' => fn (): ArcaAuthority => $this->makeArca($app),
        ]));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/fiscal-adapter.php' => config_path('fiscal-adapter.php'),
        ], 'fiscal-adapter-config');
    }

    private function makeArca(Application $app): ArcaAuthority
    {
        if (! $app->bound(TicketStore::class)) {
            throw new MissingTicketStore('Ligá una implementación durable de TicketStore en el contenedor (base o archivo, no un cache volátil) antes de usar la autoridad «arca».');
        }

        $timeout = (int) config('fiscal-adapter.arca.timeout', 30);
        $lockSeconds = config('fiscal-adapter.arca.lock_seconds');
        $lockSeconds = $lockSeconds === null ? $timeout + 60 : (int) $lockSeconds;

        $transport = new SoapTransport($app->make(CallObserver::class), $timeout);
        $endpoints = new ArcaEndpoints((array) config('fiscal-adapter.arca.endpoints', []));
        $tickets = new AccessTicketProvider(new WsaaClient($transport, $endpoints), $app->make(TicketStore::class), $lockSeconds);

        return new ArcaAuthority($tickets, $transport, $endpoints);
    }
}
