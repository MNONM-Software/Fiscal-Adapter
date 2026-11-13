<?php

use Mnonm\FiscalAdapter\Exceptions\InvalidDocument;
use Mnonm\FiscalAdapter\Tests\Support\Fixtures;

it('accepts a valid waybill', fn () => expect(Fixtures::waybill()->normalizedPlates())->toBe(['AB123CD', 'AC456DE']));

it('normalizes plates keeping the given order', fn () => expect(Fixtures::waybill(['plates' => [' ab123cd', 'AC 456 DE']])->normalizedPlates())->toBe(['AB123CD', 'AC456DE']));

it('accepts old and Mercosur plate formats', fn (string $plate) => expect(Fixtures::waybill(['plates' => [$plate]])->normalizedPlates())->toBe([strtoupper($plate)]))
    ->with(['ABC123', 'ab123cd']);

it('rejects invalid documents naming the field', function (array $overrides, string $field) {
    expect(fn () => Fixtures::waybill($overrides))->toThrow(InvalidDocument::class, $field);
})->with([
    'gross equal to tare' => [['grossKg' => 15000, 'tareKg' => 15000], 'grossKg'],
    'gross below tare' => [['grossKg' => 10000], 'grossKg'],
    'gross over the maximum' => [['grossKg' => 88001, 'tareKg' => 1000], 'grossKg'],
    'harvest with letters' => [['harvest' => '25/26'], 'harvest'],
    'harvest too short' => [['harvest' => '252'], 'harvest'],
    'no plates' => [['plates' => []], 'plates'],
    'bad plate' => [['plates' => ['AB123CD', 'XX12']], 'plates'],
    'zero km' => [['km' => 0], 'km'],
    'zero branch' => [['branch' => 0], 'branch'],
    'negative order number' => [['orderNumber' => -1], 'orderNumber'],
    'zero tare' => [['tareKg' => 0], 'tareKg'],
    'negative tare' => [['tareKg' => -5], 'tareKg'],
    'four plates' => [['plates' => ['AB123CD', 'AC456DE', 'AD789EF', 'ABC123']], 'plates'],
    'branch over five digits' => [['branch' => 100000], 'branch'],
    'km over five digits' => [['km' => 100000], 'km'],
    'order number over eight digits' => [['orderNumber' => 100000000], 'orderNumber'],
]);

it('accepts the upper limits', function () {
    $waybill = Fixtures::waybill(['tareKg' => 1, 'plates' => ['AB123CD', 'AC456DE', 'ABC123'], 'branch' => 99999, 'km' => 99999, 'orderNumber' => 99999999]);

    expect($waybill->normalizedPlates())->toHaveCount(3)
        ->and($waybill->branch)->toBe(99999)
        ->and($waybill->km)->toBe(99999)
        ->and($waybill->orderNumber)->toBe(99999999)
        ->and($waybill->tareKg)->toBe(1);
});

it('accepts the maximum gross weight', fn () => expect(Fixtures::waybill(['grossKg' => 88000])->grossKg)->toBe(88000));

it('rejects a harvest with a trailing newline', fn () => expect(fn () => Fixtures::waybill(['harvest' => "2526\n"]))->toThrow(InvalidDocument::class, 'harvest'));
