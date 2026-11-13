<?php

use Mnonm\FiscalAdapter\Arca\ArcaAuthority;
use Mnonm\FiscalAdapter\Arca\ArcaWaybillAuthority;
use Mnonm\FiscalAdapter\Arca\Testing\FakeArcaAuthority;
use Mnonm\FiscalAdapter\Arca\Wscpe\ArcaWaybill;
use Mnonm\FiscalAdapter\CallContext;
use Mnonm\FiscalAdapter\Environment;
use Mnonm\FiscalAdapter\Outcome;
use Mnonm\FiscalAdapter\Reason;
use Mnonm\FiscalAdapter\Tests\Support\Fixtures;
use PHPUnit\Framework\AssertionFailedError;

function arcaFakeContext(): CallContext
{
    return new CallContext(authorityCredential(), '30712345671', Environment::Homologation);
}

it('is the ARCA adapter contract, like the real one', function () {
    expect(new FakeArcaAuthority)->toBeInstanceOf(ArcaWaybillAuthority::class)
        ->and(is_subclass_of(ArcaAuthority::class, ArcaWaybillAuthority::class))->toBeTrue();
});

it('answers the adapter methods with sensible defaults', function () {
    $fake = new FakeArcaAuthority;
    $context = arcaFakeContext();

    $last = $fake->lastOrderNumber($context, 1);

    expect($last->isAuthorized())->toBeTrue()
        ->and($last->data['last_order_number'])->toBe(0)
        ->and($fake->provinces($context)->data['items'])->toBe([])
        ->and($fake->localities($context, 12)->data['items'])->toBe([])
        ->and($fake->grainTypes($context)->data['items'])->toBe([]);
});

it('answers a programmed outcome or closure per method', function () {
    $fake = (new FakeArcaAuthority)
        ->respondToMethod('lastOrderNumber', fn (CallContext $context, int $branch) => Outcome::authorized((string) ($branch * 100), ['last_order_number' => $branch * 100]))
        ->respondToMethod('provinces', Outcome::authorized('consultarProvincias', ['items' => [['code' => 12, 'name' => 'SANTA FE']]]))
        ->respondToMethod('grainTypes', Outcome::unavailable(new Reason('server_fault', 'x')));
    $context = arcaFakeContext();

    expect($fake->lastOrderNumber($context, 3)->data['last_order_number'])->toBe(300)
        ->and($fake->provinces($context)->data['items'])->toBe([['code' => 12, 'name' => 'SANTA FE']])
        ->and($fake->grainTypes($context)->isUnavailable())->toBeTrue();
});

it('refuses to program an unknown method', function () {
    (new FakeArcaAuthority)->respondToMethod('consultarPlantas', Outcome::authorized('x'));
})->throws(InvalidArgumentException::class);

it('covers the issuing flow: last number, issue and assertions', function () {
    $fake = (new FakeArcaAuthority)
        ->respondToMethod('lastOrderNumber', Outcome::authorized('418', ['last_order_number' => 418]))
        ->respondWith(ArcaWaybill::class, Outcome::authorized('101234567890'));
    $context = arcaFakeContext();

    $next = $fake->lastOrderNumber($context, 1)->data['last_order_number'] + 1;
    $outcome = $fake->issue($context, Fixtures::waybill(['orderNumber' => $next]));

    expect($outcome->authorityId)->toBe('101234567890');
    $fake->assertIssued(fn (ArcaWaybill $waybill) => $waybill->orderNumber === 419);
    $fake->assertMethodCalled('lastOrderNumber', fn (CallContext $context, int $branch) => $branch === 1);
});

it('records forgotten tickets', function () {
    $fake = new FakeArcaAuthority;

    $fake->forgetTicket(arcaFakeContext());

    $fake->assertMethodCalled('forgetTicket');
    expect(fn () => $fake->assertMethodCalled('provinces'))->toThrow(AssertionFailedError::class);
});

it('counts adapter lookups as sent', function () {
    $fake = new FakeArcaAuthority;
    $fake->assertNothingSent();

    $fake->provinces(arcaFakeContext());

    expect(fn () => $fake->assertNothingSent())->toThrow(AssertionFailedError::class);
});

it('keeps the generic fake behaviour', function () {
    $fake = new FakeArcaAuthority;

    expect($fake->name())->toBe('fake')
        ->and($fake->supports(new stdClass))->toBeTrue()
        ->and($fake->issue(arcaFakeContext(), Fixtures::waybill())->authorityId)->toBe('FAKE-1')
        ->and($fake->sent())->toHaveCount(1);
    $fake->assertIssued();
    expect(fn () => $fake->assertCancelled())->toThrow(AssertionFailedError::class);
});
