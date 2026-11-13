<?php

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

it('refuses to fake in production', function () {
    $this->app['env'] = 'production';

    Fiscal::fake();
})->throws(LogicException::class);
