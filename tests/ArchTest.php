<?php

use Mnonm\FiscalAdapter\FiscalAdapterServiceProvider;

/**
 * The classes at the root of the package (src/*.php), except the service provider, which is the one
 * place that wires the ARCA driver.
 *
 * @return list<string>
 */
function rootCoreClasses(): array
{
    $classes = array_map(fn (string $file): string => 'Mnonm\\FiscalAdapter\\'.basename($file, '.php'), glob(__DIR__.'/../src/*.php') ?: []);

    return array_values(array_diff($classes, [FiscalAdapterServiceProvider::class]));
}

// One rule per target: with an array of targets, `not->toUse()` lets a violation through.
foreach (['Mnonm\FiscalAdapter\Contracts', 'Mnonm\FiscalAdapter\Exceptions', 'Mnonm\FiscalAdapter\Testing', ...rootCoreClasses()] as $core) {
    arch("core does not know ARCA: {$core}")->expect($core)->not->toUse('Mnonm\FiscalAdapter\Arca');
}

it('checks every root core class', function () {
    expect(rootCoreClasses())->toContain('Mnonm\FiscalAdapter\Outcome', 'Mnonm\FiscalAdapter\FiscalManager', 'Mnonm\FiscalAdapter\CallObserver')
        ->not->toContain(FiscalAdapterServiceProvider::class);
});

arch('no ext-soap')->expect('Mnonm\FiscalAdapter')->not->toUse(['SoapClient', 'SoapServer']);

arch('no database')->expect('Mnonm\FiscalAdapter')->not->toUse([
    'PDO',
    'Illuminate\Database',
    'Illuminate\Support\Facades\DB',
    'Illuminate\Support\Facades\Schema',
]);
