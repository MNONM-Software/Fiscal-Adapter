<?php

namespace Mnonm\FiscalAdapter\Arca\Wscpe;

use Mnonm\FiscalAdapter\Arca\Cuit;
use Mnonm\FiscalAdapter\Arca\Wsaa\AccessTicket;
use Mnonm\FiscalAdapter\Contracts\Query;

/**
 * `consultarCPEAutomotor`: por CTG (identifica a la carta de porte) o por tipo, sucursal y número
 * de orden (que un intento rechazado o cancelado libera y puede tener otro camión).
 */
final readonly class ArcaWaybillQuery implements Query
{
    private function __construct(
        public ?string $ctg,
        public ?int $branch,
        public ?int $orderNumber,
        public int $cpeType,
    ) {}

    public static function byCtg(string $ctg): self
    {
        return new self($ctg, null, null, ArcaWaybill::AUTOMOTOR);
    }

    public static function byOrderNumber(int $branch, int $orderNumber, int $cpeType = ArcaWaybill::AUTOMOTOR): self
    {
        return new self(null, $branch, $orderNumber, $cpeType);
    }

    public function envelope(AccessTicket $ticket, Cuit $represented): string
    {
        $search = $this->ctg !== null
            ? '<nroCTG>'.WscpeXml::text($this->ctg).'</nroCTG>'
            : "<cartaPorte><tipoCPE>{$this->cpeType}</tipoCPE><sucursal>{$this->branch}</sucursal><nroOrden>{$this->orderNumber}</nroOrden></cartaPorte>";

        return WscpeXml::envelope('ConsultarCPEAutomotorReq', $ticket, $represented, "<solicitud>{$search}</solicitud>");
    }
}
