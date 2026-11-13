<?php

namespace Mnonm\FiscalAdapter\Arca\Wscpe;

use Mnonm\FiscalAdapter\Arca\Cuit;
use Mnonm\FiscalAdapter\Arca\Wsaa\AccessTicket;
use Mnonm\FiscalAdapter\Contracts\Issuable;
use Mnonm\FiscalAdapter\Exceptions\InvalidDocument;

/**
 * Carta de porte automotor de granos (`autorizarCPEAutomotor`). Primer corte: origen productor
 * (campo o silobolsa en campo), sin intervinientes. Se valida al construir.
 */
final readonly class ArcaWaybill implements Issuable
{
    public const AUTOMOTOR = 74;

    public const MAX_GROSS_KG = 88000;

    /** Límites de WSCPE (los mismos que valida #524). */
    public const MAX_BRANCH = 99999;

    public const MAX_ORDER_NUMBER = 99999999;

    public const MAX_KM = 99999;

    public const MAX_PLATES = 3;

    /** @var list<string> */
    private array $plates;

    /**
     * @param  list<string>  $plates
     *
     * @throws InvalidDocument
     */
    public function __construct(
        public int $branch,
        public int $orderNumber,
        public int $originProvinceCode,
        public int $originLocalityCode,
        public int $grainCode,
        public string $harvest,
        public int $grossKg,
        public int $tareKg,
        public Cuit $destinationCuit,
        public int $destinationProvinceCode,
        public int $destinationLocalityCode,
        public ?int $destinationPlantNumber,
        public Cuit $recipientCuit,
        public Cuit $carrierCuit,
        array $plates,
        public \DateTimeImmutable $departureAt,
        public int $km,
        public Cuit $driverCuit,
        public Cuit $freightPayerCuit,
        public bool $declaredNotFumigated,
        public int $cpeType = self::AUTOMOTOR,
    ) {
        foreach (['branch' => [$branch, self::MAX_BRANCH], 'orderNumber' => [$orderNumber, self::MAX_ORDER_NUMBER], 'km' => [$km, self::MAX_KM]] as $field => [$value, $max]) {
            if ($value <= 0) {
                throw new InvalidDocument("El campo {$field} debe ser mayor que cero.");
            }

            if ($value > $max) {
                throw new InvalidDocument("El campo {$field} no puede superar {$max}.");
            }
        }

        if ($tareKg < 1) {
            throw new InvalidDocument('El campo tareKg (tara) debe ser de al menos 1 kg.');
        }

        if ($grossKg <= $tareKg) {
            throw new InvalidDocument('El campo grossKg (peso bruto) debe ser mayor que la tara.');
        }

        if ($grossKg > self::MAX_GROSS_KG) {
            throw new InvalidDocument('El campo grossKg (peso bruto) no puede superar '.self::MAX_GROSS_KG.' kg.');
        }

        if (preg_match('/^\d{4}\z/', $harvest) !== 1) {
            throw new InvalidDocument("El campo harvest (cosecha) debe tener 4 dígitos, por ejemplo 2526; llegó «{$harvest}».");
        }

        $this->plates = self::normalizePlates($plates);
    }

    /**
     * @return list<string>
     */
    public function normalizedPlates(): array
    {
        return $this->plates;
    }

    public function envelope(AccessTicket $ticket, Cuit $represented): string
    {
        $bool = fn (bool $value): string => $value ? 'true' : 'false';
        $plant = $this->destinationPlantNumber !== null ? "<planta>{$this->destinationPlantNumber}</planta>" : '';
        $plates = implode('', array_map(fn (string $plate): string => '<dominio>'.WscpeXml::text($plate).'</dominio>', $this->plates));

        $solicitud = '<solicitud>'
            ."<cabecera><tipoCP>{$this->cpeType}</tipoCP><cuitSolicitante>{$represented->value}</cuitSolicitante><sucursal>{$this->branch}</sucursal><nroOrden>{$this->orderNumber}</nroOrden></cabecera>"
            ."<origen><productor><codProvincia>{$this->originProvinceCode}</codProvincia><codLocalidad>{$this->originLocalityCode}</codLocalidad></productor></origen>"
            .'<correspondeRetiroProductor>false</correspondeRetiroProductor><esSolicitanteCampo>true</esSolicitanteCampo>'
            .'<datosCarga><codGrano>'.$this->grainCode.'</codGrano><cosecha>'.WscpeXml::text($this->harvest)."</cosecha><pesoBruto>{$this->grossKg}</pesoBruto><pesoTara>{$this->tareKg}</pesoTara></datosCarga>"
            ."<destino><cuit>{$this->destinationCuit->value}</cuit><esDestinoCampo>".$bool($this->destinationPlantNumber === null)."</esDestinoCampo><codProvincia>{$this->destinationProvinceCode}</codProvincia><codLocalidad>{$this->destinationLocalityCode}</codLocalidad>{$plant}</destino>"
            ."<destinatario><cuit>{$this->recipientCuit->value}</cuit></destinatario>"
            ."<transporte><cuitTransportista>{$this->carrierCuit->value}</cuitTransportista>{$plates}"
            .'<fechaHoraPartida>'.WscpeXml::dateTime($this->departureAt).'</fechaHoraPartida>'
            ."<kmRecorrer>{$this->km}</kmRecorrer><cuitChofer>{$this->driverCuit->value}</cuitChofer><cuitPagadorFlete>{$this->freightPayerCuit->value}</cuitPagadorFlete>"
            .'<mercaderiaFumigada>'.$bool($this->declaredNotFumigated).'</mercaderiaFumigada></transporte>'
            .'</solicitud>';

        return WscpeXml::envelope('AutorizarCPEAutomotorReq', $ticket, $represented, $solicitud);
    }

    /**
     * @param  list<string>  $plates
     * @return list<string>
     *
     * @throws InvalidDocument
     */
    private static function normalizePlates(array $plates): array
    {
        if ($plates === []) {
            throw new InvalidDocument('El campo plates (patentes) necesita al menos una patente.');
        }

        if (count($plates) > self::MAX_PLATES) {
            throw new InvalidDocument('El campo plates (patentes) admite como máximo '.self::MAX_PLATES.' patentes.');
        }

        $normalized = [];

        foreach ($plates as $plate) {
            $clean = strtoupper((string) preg_replace('/\s+/', '', $plate));

            if (preg_match('/^[A-Z]{3}\d{3}$/', $clean) !== 1 && preg_match('/^[A-Z]{2}\d{3}[A-Z]{2}$/', $clean) !== 1) {
                throw new InvalidDocument("La patente «{$plate}» del campo plates no es válida: debe ser AAA123 o AA123AA.");
            }

            $normalized[] = $clean;
        }

        return $normalized;
    }
}
