<?php

namespace Mnonm\FiscalAdapter\Facades;

use Illuminate\Support\Facades\Facade;
use LogicException;
use Mnonm\FiscalAdapter\Arca\Testing\FakeArcaAuthority;
use Mnonm\FiscalAdapter\Contracts\FiscalAuthority;
use Mnonm\FiscalAdapter\FiscalManager;

/**
 * @method static FiscalAuthority authority(string $name)
 * @method static void extend(string $name, \Closure $factory)
 *
 * @see FiscalManager
 */
class Fiscal extends Facade
{
    /**
     * Replaces every authority with one shared fake that tests can program and assert on. It is the
     * generic fake plus the ARCA adapter methods (last order number, catalogs, forgetTicket), so the
     * whole issuing flow runs under it.
     */
    public static function fake(): FakeArcaAuthority
    {
        if (static::$app->environment('production')) {
            throw new LogicException('Fiscal::fake() no se puede usar en producción.');
        }

        $fake = new FakeArcaAuthority;
        static::swap(FiscalManager::alwaysReturning($fake));

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return FiscalManager::class;
    }
}
