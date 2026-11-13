<?php

namespace Mnonm\FiscalAdapter\Tests\Support;

use Mnonm\FiscalAdapter\Arca\Cuit;
use Mnonm\FiscalAdapter\Arca\Wscpe\ArcaWaybill;

/**
 * Valid documents with invented data, overridable by constructor parameter name.
 */
class Fixtures
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function waybill(array $overrides = []): ArcaWaybill
    {
        $arguments = array_merge([
            'branch' => 1,
            'orderNumber' => 419,
            'originProvinceCode' => 9,
            'originLocalityCode' => 1234,
            'grainCode' => 23,
            'harvest' => '2526',
            'grossKg' => 45000,
            'tareKg' => 15000,
            'destinationCuit' => Cuit::from('30500000003'),
            'destinationProvinceCode' => 12,
            'destinationLocalityCode' => 5678,
            'destinationPlantNumber' => 12345,
            'recipientCuit' => Cuit::from('30500000003'),
            'carrierCuit' => Cuit::from('30798765430'),
            'plates' => ['AB123CD', 'AC456DE'],
            'departureAt' => new \DateTimeImmutable('2026-10-08 10:00:00', new \DateTimeZone('America/Argentina/Buenos_Aires')),
            'km' => 120,
            'driverCuit' => Cuit::from('20123456786'),
            'freightPayerCuit' => Cuit::from('30712345671'),
            'declaredNotFumigated' => true,
        ], $overrides);

        return new ArcaWaybill(...$arguments);
    }
}
