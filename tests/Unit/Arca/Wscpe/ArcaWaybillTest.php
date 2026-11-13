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
]);

it('accepts the maximum gross weight', fn () => expect(Fixtures::waybill(['grossKg' => 88000])->grossKg)->toBe(88000));
