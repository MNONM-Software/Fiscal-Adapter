<?php

use Illuminate\Support\Facades\Event;
use Mnonm\FiscalAdapter\CallRecord;
use Mnonm\FiscalAdapter\Environment;
use Mnonm\FiscalAdapter\EventCallObserver;
use Mnonm\FiscalAdapter\FiscalCallRecorded;
use Mnonm\FiscalAdapter\Outcome;
use Mnonm\FiscalAdapter\OutcomeStatus;
use Mnonm\FiscalAdapter\Reason;

it('keeps id, data and reasons on authorized', function () {
    $outcome = Outcome::authorized('101234567890', ['state' => 'AC'], [new Reason('550', 'x')]);

    expect($outcome->isAuthorized())->toBeTrue()
        ->and($outcome->status)->toBe(OutcomeStatus::Authorized)
        ->and($outcome->authorityId)->toBe('101234567890')
        ->and($outcome->data)->toBe(['state' => 'AC'])
        ->and($outcome->reasonCodes())->toBe(['550']);
});

it('builds each status with its factory', function () {
    $reason = new Reason('1', 'm');

    expect(Outcome::rejected([$reason])->isRejected())->toBeTrue()
        ->and(Outcome::pending()->isPending())->toBeTrue()
        ->and(Outcome::pending('77', ['a' => 1])->authorityId)->toBe('77')
        ->and(Outcome::unavailable($reason)->isUnavailable())->toBeTrue()
        ->and(Outcome::unknown($reason)->isUnknown())->toBeTrue()
        ->and(Outcome::unknown($reason)->status)->toBe(OutcomeStatus::Unknown)
        ->and(Outcome::unknown($reason)->reasons)->toBe([$reason]);
});

it('refuses a rejection without reasons', function () {
    expect(fn () => Outcome::rejected([]))->toThrow(InvalidArgumentException::class);
});

it('lists reason codes in order', function () {
    $outcome = Outcome::rejected([new Reason('b', 'x'), new Reason('a', 'y')]);

    expect($outcome->reasonCodes())->toBe(['b', 'a']);
});

it('dispatches FiscalCallRecorded', function () {
    Event::fake([FiscalCallRecorded::class]);
    $record = new CallRecord(
        authority: 'a', service: 's', method: 'm', environment: Environment::Homologation,
        url: 'https://example.test', representedTaxId: null, request: 'req', response: null,
        httpStatus: null, durationMs: 3, error: null, occurredAt: now()->toImmutable(),
    );

    (new EventCallObserver)->record($record);

    Event::assertDispatched(FiscalCallRecorded::class, fn ($e) => $e->record === $record);
});
