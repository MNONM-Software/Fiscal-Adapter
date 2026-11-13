<?php

use Mnonm\FiscalAdapter\Exceptions\UnknownAuthority;
use Mnonm\FiscalAdapter\FiscalManager;
use Mnonm\FiscalAdapter\Testing\FakeAuthority;

it('resolves and memoizes an authority', function () {
    $calls = 0;
    $manager = new FiscalManager(['x' => function () use (&$calls) {
        $calls++;

        return new FakeAuthority('x');
    }]);

    expect($manager->authority('x'))->toBe($manager->authority('x'))
        ->and($calls)->toBe(1);
});

it('extends with a new authority', function () {
    $manager = new FiscalManager;
    $manager->extend('sifen', fn () => new FakeAuthority('sifen'));

    expect($manager->authority('sifen')->name())->toBe('sifen');
});

it('throws UnknownAuthority with the name', function () {
    (new FiscalManager)->authority('nope');
})->throws(UnknownAuthority::class, 'nope');
