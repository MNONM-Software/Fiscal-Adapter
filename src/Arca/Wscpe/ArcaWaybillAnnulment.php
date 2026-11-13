<?php

namespace Mnonm\FiscalAdapter\Arca\Wscpe;

use Mnonm\FiscalAdapter\Arca\Cuit;
use Mnonm\FiscalAdapter\Arca\Wsaa\AccessTicket;
use Mnonm\FiscalAdapter\Contracts\Cancellation;

/**
 * `anularCPE`. Con motivo `Other` ARCA espera observaciones; acá no se exige, ARCA las rechaza.
 */
final readonly class ArcaWaybillAnnulment implements Cancellation
{
    public function __construct(
        public int $branch,
        public int $orderNumber,
        public AnnulmentReason $reason,
        public ?string $observations = null,
        public ?string $ctg = null,
        public int $cpeType = ArcaWaybill::AUTOMOTOR,
    ) {}

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
