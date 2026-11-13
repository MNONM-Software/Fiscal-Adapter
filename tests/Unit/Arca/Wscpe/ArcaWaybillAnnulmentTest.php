<?php

use Mnonm\FiscalAdapter\Arca\Wscpe\AnnulmentReason;
use Mnonm\FiscalAdapter\Arca\Wscpe\ArcaWaybillAnnulment;
use Mnonm\FiscalAdapter\Exceptions\InvalidDocument;

it('rejects invalid annulments naming the field', function (AnnulmentReason $reason, ?string $observations) {
    expect(fn () => new ArcaWaybillAnnulment(1, 419, $reason, $observations))->toThrow(InvalidDocument::class, 'observations');
})->with([
    'other without observations' => [AnnulmentReason::Other, null],
    'other with empty observations' => [AnnulmentReason::Other, ''],
    'other with blank observations' => [AnnulmentReason::Other, '   '],
    'observations over 100 characters' => [AnnulmentReason::Accident, str_repeat('a', 101)],
    'multibyte observations over 100 characters' => [AnnulmentReason::Other, str_repeat('ñ', 101)],
]);

it('accepts valid annulments', function (AnnulmentReason $reason, ?string $observations) {
    expect((new ArcaWaybillAnnulment(1, 419, $reason, $observations))->reason)->toBe($reason);
})->with([
    'accident without observations' => [AnnulmentReason::Accident, null],
    'cargo loss without observations' => [AnnulmentReason::CargoLoss, null],
    'other with observations' => [AnnulmentReason::Other, 'Carga mal'],
    'exactly 100 multibyte characters' => [AnnulmentReason::Other, str_repeat('ñ', 100)],
]);
