<?php

namespace Mnonm\FiscalAdapter\Tests;

use Mnonm\FiscalAdapter\FiscalAdapterServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [FiscalAdapterServiceProvider::class];
    }
}
