<?php

namespace Mnonm\FiscalAdapter\Arca;

use Mnonm\FiscalAdapter\Arca\Wscpe\ArcaWaybill;
use Mnonm\FiscalAdapter\CallContext;
use Mnonm\FiscalAdapter\Contracts\FiscalAuthority;
use Mnonm\FiscalAdapter\Outcome;

/**
 * El adaptador ARCA de cartas de porte: el ciclo de vida común más lo que es propio de WSCPE
 * (último número de orden, catálogos, descartar el ticket). Lo implementan {@see ArcaAuthority} y
 * {@see Testing\FakeArcaAuthority}, así que quien la usa puede tipar contra esto y testear con
 * `Fiscal::fake()`.
 */
interface ArcaWaybillAuthority extends FiscalAuthority
{
    /**
     * Último número de orden usado en una sucursal: el siguiente es éste más uno.
     * `Authorized` trae `data['last_order_number']`.
     */
    public function lastOrderNumber(CallContext $context, int $branch, int $cpeType = ArcaWaybill::AUTOMOTOR): Outcome;

    /**
     * `Authorized` trae `data['items']`: lista de `{code: int, name: string}`.
     */
    public function provinces(CallContext $context): Outcome;

    /**
     * `Authorized` trae `data['items']`: lista de `{code: int, name: string}`.
     */
    public function localities(CallContext $context, int $provinceCode): Outcome;

    /**
     * `Authorized` trae `data['items']`: lista de `{code: int, name: string}`.
     */
    public function grainTypes(CallContext $context): Outcome;

    /**
     * Descarta el ticket de WSCPE guardado: el próximo pedido vuelve a pasar por WSAA. Hace falta
     * después de una delegación nueva, que no aparece hasta el próximo ticket.
     */
    public function forgetTicket(CallContext $context): void;
}
