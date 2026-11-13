<?php

namespace Mnonm\FiscalAdapter\Arca\Wscpe;

use Mnonm\FiscalAdapter\Arca\Cuit;
use Mnonm\FiscalAdapter\Arca\Wsaa\AccessTicket;
use Mnonm\FiscalAdapter\Contracts\Cancellation;
use Mnonm\FiscalAdapter\Exceptions\InvalidDocument;

/**
 * `anularCPE`. Se valida al construir: con motivo `Other` las observaciones son obligatorias, y nunca
 * pasan de 100 caracteres.
 */
final readonly class ArcaWaybillAnnulment implements Cancellation
{
    public const MAX_OBSERVATIONS = 100;

    /**
     * @throws InvalidDocument
     */
    public function __construct(
        public int $branch,
        public int $orderNumber,
        public AnnulmentReason $reason,
        public ?string $observations = null,
        public ?string $ctg = null,
        public int $cpeType = ArcaWaybill::AUTOMOTOR,
    ) {
        if ($reason === AnnulmentReason::Other && trim((string) $observations) === '') {
            throw new InvalidDocument('El campo observations (observaciones) es obligatorio cuando el motivo de anulación es «otro».');
        }

        if ($observations !== null && mb_strlen($observations) > self::MAX_OBSERVATIONS) {
            throw new InvalidDocument('El campo observations (observaciones) no puede superar '.self::MAX_OBSERVATIONS.' caracteres.');
        }
    }

    public function envelope(AccessTicket $ticket, Cuit $represented): string
    {
        $observations = $this->observations !== null && $this->observations !== ''
            ? '<anulacionObservaciones>'.WscpeXml::text($this->observations).'</anulacionObservaciones>'
            : '';

        $solicitud = '<solicitud>'
            ."<cartaPorte><tipoCPE>{$this->cpeType}</tipoCPE><sucursal>{$this->branch}</sucursal><nroOrden>{$this->orderNumber}</nroOrden></cartaPorte>"
            ."<anulacionMotivo>{$this->reason->value}</anulacionMotivo>"
            .$observations
            .'</solicitud>';

        return WscpeXml::envelope('AnularCPEReq', $ticket, $represented, $solicitud);
    }
}
