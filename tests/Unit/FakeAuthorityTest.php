<?php

use Mnonm\FiscalAdapter\Arca\Wscpe\ArcaWaybill;
use Mnonm\FiscalAdapter\Arca\Wscpe\ArcaWaybillQuery;
use Mnonm\FiscalAdapter\CallContext;
use Mnonm\FiscalAdapter\Environment;
use Mnonm\FiscalAdapter\Outcome;
use Mnonm\FiscalAdapter\Reason;
use Mnonm\FiscalAdapter\Testing\FakeAuthority;
use Mnonm\FiscalAdapter\Tests\Support\Fixtures;
use PHPUnit\Framework\AssertionFailedError;

function fakeContext(): CallContext
{
    return new CallContext(authorityCredential(), '30712345671', Environment::Homologation);
}

it('numbers default authorized responses', function () {
    $fake = new FakeAuthority;

    expect($fake->issue(fakeContext(), Fixtures::waybill())->authorityId)->toBe('FAKE-1')
        ->and($fake->issue(fakeContext(), Fixtures::waybill())->authorityId)->toBe('FAKE-2');
});

it('records what was sent', function () {
    $fake = new FakeAuthority;
    $waybill = Fixtures::waybill();
    $fake->issue(fakeContext(), $waybill);

    expect($fake->sent())->toHaveCount(1)
        ->and($fake->sent()[0]['operation'])->toBe('issue')
        ->and($fake->sent()[0]['document'])->toBe($waybill);
});

it('responds by document class and by closure', function () {
    $fake = (new FakeAuthority)
        ->respondWith(ArcaWaybill::class, Outcome::rejected([new Reason('1', 'no')]))
        ->respondWith(ArcaWaybillQuery::class, fn ($document) => Outcome::pending('P-'.$document::class));

    expect($fake->issue(fakeContext(), Fixtures::waybill())->isRejected())->toBeTrue()
        ->and($fake->query(fakeContext(), ArcaWaybillQuery::byCtg('1'))->isPending())->toBeTrue();
});

it('asserts issued with and without filter', function () {
    $fake = new FakeAuthority;
    $fake->issue(fakeContext(), Fixtures::waybill(['orderNumber' => 7]));

    $fake->assertIssued();
    $fake->assertIssued(fn ($document) => $document instanceof ArcaWaybill);
    expect(fn () => $fake->assertIssued(fn () => false))->toThrow(AssertionFailedError::class);
});

it('fails assertCancelled and assertNothingSent when they do not hold', function () {
    $fake = new FakeAuthority;
    $fake->assertNothingSent();
    $fake->issue(fakeContext(), Fixtures::waybill());

    expect(fn () => $fake->assertCancelled())->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertNothingSent())->toThrow(AssertionFailedError::class);
});

it('supports every document', function () {
    expect((new FakeAuthority)->supports(new stdClass))->toBeTrue();
});
