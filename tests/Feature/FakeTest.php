<?php

use Mnonm\FiscalAdapter\Arca\ArcaWaybillAuthority;
use Mnonm\FiscalAdapter\Arca\Wscpe\ArcaWaybill;
use Mnonm\FiscalAdapter\CallContext;
use Mnonm\FiscalAdapter\Environment;
use Mnonm\FiscalAdapter\Facades\Fiscal;
use Mnonm\FiscalAdapter\Outcome;
use Mnonm\FiscalAdapter\Reason;
use Mnonm\FiscalAdapter\Tests\Support\Fixtures;

it('fakes through the facade', function () {
    $fake = Fiscal::fake()->respondWith(ArcaWaybill::class, Outcome::rejected([new Reason('2002', 'x')]));
    $context = new CallContext(authorityCredential(), '30712345671', Environment::Homologation);

    $outcome = Fiscal::authority('arca')->issue($context, Fixtures::waybill());

    expect($outcome->isRejected())->toBeTrue();
    $fake->assertIssued();
});

it('fakes the ARCA adapter methods through the facade', function () {
    $fake = Fiscal::fake()->respondToMethod('lastOrderNumber', Outcome::authorized('418', ['last_order_number' => 418]));
    $context = new CallContext(authorityCredential(), '30712345671', Environment::Homologation);

    $authority = Fiscal::authority('arca');

    expect($authority)->toBeInstanceOf(ArcaWaybillAuthority::class)
        ->and($authority->lastOrderNumber($context, 1)->data['last_order_number'])->toBe(418)
        ->and(Fiscal::authority('sifen'))->toBe($fake);
});

it('refuses to fake in production', function () {
    $this->app['env'] = 'production';

    Fiscal::fake();
})->throws(LogicException::class);
