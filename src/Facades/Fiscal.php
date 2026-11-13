<?php

namespace Mnonm\FiscalAdapter\Facades;

use Illuminate\Support\Facades\Facade;
use LogicException;
use Mnonm\FiscalAdapter\Contracts\FiscalAuthority;
use Mnonm\FiscalAdapter\FiscalManager;
use Mnonm\FiscalAdapter\Testing\FakeAuthority;

/**
 * @method static FiscalAuthority authority(string $name)
 * @method static void extend(string $name, \Closure $factory)
 *
 * @see FiscalManager
 */
class Fiscal extends Facade
{
    /**
     * Replaces every authority with one shared fake that tests can program and assert on.
     */
    public static function fake(): FakeAuthority
    {
        if (static::$app->environment('production')) {
            throw new LogicException('Fiscal::fake() no se puede usar en producción.');
        }

        $fake = new FakeAuthority;
        static::swap(FiscalManager::alwaysReturning($fake));

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return FiscalManager::class;
    }
}
