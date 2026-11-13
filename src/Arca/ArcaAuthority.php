<?php

namespace Mnonm\FiscalAdapter\Arca;

use Carbon\CarbonImmutable;
use Mnonm\FiscalAdapter\Arca\Soap\SoapCall;
use Mnonm\FiscalAdapter\Arca\Soap\SoapTransport;
use Mnonm\FiscalAdapter\Arca\Wsaa\AccessTicket;
use Mnonm\FiscalAdapter\Arca\Wsaa\AccessTicketProvider;
use Mnonm\FiscalAdapter\Arca\Wscpe\ArcaWaybill;
use Mnonm\FiscalAdapter\Arca\Wscpe\ArcaWaybillAnnulment;
use Mnonm\FiscalAdapter\Arca\Wscpe\ArcaWaybillQuery;
use Mnonm\FiscalAdapter\Arca\Wscpe\WaybillResult;
use Mnonm\FiscalAdapter\Arca\Wscpe\WscpeXml;
use Mnonm\FiscalAdapter\CallContext;
use Mnonm\FiscalAdapter\Contracts\Cancellation;
use Mnonm\FiscalAdapter\Contracts\FiscalAuthority;
use Mnonm\FiscalAdapter\Contracts\Issuable;
use Mnonm\FiscalAdapter\Contracts\Query;
use Mnonm\FiscalAdapter\Exceptions\UnsupportedDocument;
use Mnonm\FiscalAdapter\Outcome;
use Mnonm\FiscalAdapter\Reason;

/**
 * ARCA: cartas de porte automotor de granos por WSCPE.
 */
final class ArcaAuthority implements FiscalAuthority
{
    private const SERVICE = 'wscpe';

    /** Error interno de ARCA: no es un rechazo, se reintenta. */
    private const INTERNAL_ERROR = '500';

    /** Ya existe una carta de porte con ese número: se resuelve consultando. */
    private const ALREADY_ISSUED = '2241';

    private const STATE_ANNULLED = 'AN';

    public function __construct(
        private readonly AccessTicketProvider $tickets,
        private readonly SoapTransport $transport,
        private readonly ArcaEndpoints $endpoints,
    ) {}

    public function name(): string
    {
        return 'arca';
    }

    public function supports(object $document): bool
    {
        return $document instanceof ArcaWaybill
            || $document instanceof ArcaWaybillAnnulment
            || $document instanceof ArcaWaybillQuery;
    }

    public function issue(CallContext $context, Issuable $document): Outcome
    {
        if (! $document instanceof ArcaWaybill) {
            throw new UnsupportedDocument('ARCA sólo emite cartas de porte (ArcaWaybill); llegó '.$document::class.'.');
        }

        $response = $this->call($context, 'autorizarCPEAutomotor', fn (AccessTicket $ticket, Cuit $cuit) => $document->envelope($ticket, $cuit));

        if ($response instanceof Outcome) {
            return $response;
        }

        ['respuesta' => $respuesta, 'errors' => $errors] = WaybillResult::fromResponse($response);

        if ($respuesta === null) {
            return $this->malformed();
        }

        if ($failure = $this->ambiguous($errors)) {
            return $failure;
        }

        $data = WaybillResult::data($respuesta);

        return $data['ctg'] !== null
            ? Outcome::authorized($data['ctg'], $data, $errors)
            : Outcome::rejected($errors !== [] ? $errors : [new Reason('no_ctg', 'ARCA no devolvió CTG ni errores.')]);
    }

    public function cancel(CallContext $context, Cancellation $cancellation): Outcome
    {
        if (! $cancellation instanceof ArcaWaybillAnnulment) {
            throw new UnsupportedDocument('ARCA sólo anula cartas de porte (ArcaWaybillAnnulment); llegó '.$cancellation::class.'.');
        }

        $response = $this->call($context, 'anularCPE', fn (AccessTicket $ticket, Cuit $cuit) => $cancellation->envelope($ticket, $cuit));

        if ($response instanceof Outcome) {
            return $response;
        }

        ['respuesta' => $respuesta, 'errors' => $errors] = WaybillResult::fromResponse($response);

        if ($respuesta === null) {
            return $this->malformed();
        }

        if ($failure = $this->ambiguous($errors, checkAlreadyIssued: false)) {
            return $failure;
        }

        $data = WaybillResult::data($respuesta);

        if ($data['state'] === self::STATE_ANNULLED) {
            $id = $data['ctg'] ?? $cancellation->ctg ?? "{$cancellation->branch}-{$cancellation->orderNumber}";

            return Outcome::authorized($id, $data, $errors);
        }

        return Outcome::rejected($errors !== [] ? $errors : [new Reason('not_annulled', 'ARCA dejó la carta de porte en estado '.($data['state'] ?? 'desconocido').'.')]);
    }

    public function query(CallContext $context, Query $query): Outcome
    {
        if (! $query instanceof ArcaWaybillQuery) {
            throw new UnsupportedDocument('ARCA sólo consulta cartas de porte (ArcaWaybillQuery); llegó '.$query::class.'.');
        }

        $found = $this->consult($context, $query);

        if ($found instanceof Outcome) {
            return $found;
        }

        ['data' => $data, 'errors' => $errors] = $found;

        return $data['ctg'] !== null
            ? Outcome::authorized($data['ctg'], $data, $errors)
            : Outcome::rejected($errors !== [] ? $errors : [new Reason('no_ctg', 'ARCA no devolvió la carta de porte ni errores.')]);
    }

    public function reconcile(CallContext $context, Issuable|Cancellation $document): Outcome
    {
        if ($document instanceof ArcaWaybill) {
            return $this->reconcileIssue($context, $document);
        }

        if ($document instanceof ArcaWaybillAnnulment) {
            return $this->reconcileCancellation($context, $document);
        }

        throw new UnsupportedDocument('ARCA sólo reconcilia cartas de porte y sus anulaciones; llegó '.$document::class.'.');
    }

    /**
     * Último número de orden usado en una sucursal: el siguiente es éste más uno.
     */
    public function lastOrderNumber(CallContext $context, int $branch, int $cpeType = ArcaWaybill::AUTOMOTOR): Outcome
    {
        $body = '<solicitud><sucursal>'.WscpeXml::text($branch).'</sucursal><tipoCPE>'.WscpeXml::text($cpeType).'</tipoCPE></solicitud>';
        $found = $this->consultRaw($context, 'consultarUltNroOrden', fn (AccessTicket $ticket, Cuit $cuit) => WscpeXml::envelope('ConsultarUltNroOrdenReq', $ticket, $cuit, $body));

        if ($found instanceof Outcome) {
            return $found;
        }

        ['respuesta' => $respuesta, 'errors' => $errors] = $found;

        if ($errors !== []) {
            return Outcome::rejected($errors);
        }

        if (trim((string) $respuesta->nroOrden) === '') {
            return $this->malformed();
        }

        $number = (int) $respuesta->nroOrden;

        return Outcome::authorized((string) $number, ['last_order_number' => $number]);
    }

    public function provinces(CallContext $context): Outcome
    {
        return $this->catalog($context, 'consultarProvincias', 'ConsultarProvinciasReq', '', 'provincia');
    }

    public function localities(CallContext $context, int $provinceCode): Outcome
    {
        return $this->catalog($context, 'consultarLocalidadesPorProvincia', 'ConsultarLocalidadesPorProvinciaReq', '<solicitud><codProvincia>'.WscpeXml::text($provinceCode).'</codProvincia></solicitud>', 'localidad');
    }

    public function grainTypes(CallContext $context): Outcome
    {
        return $this->catalog($context, 'consultarTiposGrano', 'ConsultarTiposGranoReq', '', 'grano');
    }

    /**
     * Descarta el ticket de WSCPE guardado: el próximo pedido vuelve a pasar por WSAA.
     */
    public function forgetTicket(CallContext $context): void
    {
        $this->tickets->forget($context, self::SERVICE);
    }

    private function catalog(CallContext $context, string $method, string $element, string $body, string $itemTag): Outcome
    {
        $found = $this->consultRaw($context, $method, fn (AccessTicket $ticket, Cuit $cuit) => WscpeXml::envelope($element, $ticket, $cuit, $body));

        if ($found instanceof Outcome) {
            return $found;
        }

        ['respuesta' => $respuesta, 'errors' => $errors] = $found;

        if ($errors !== []) {
            return Outcome::rejected($errors);
        }

        $items = [];
        foreach ($respuesta->{$itemTag} as $item) {
            $items[] = ['code' => (int) $item->codigo, 'name' => trim((string) $item->descripcion)];
        }

        return Outcome::authorized($method, ['items' => $items]);
    }

    private function reconcileIssue(CallContext $context, ArcaWaybill $waybill): Outcome
    {
        $found = $this->consult($context, ArcaWaybillQuery::byOrderNumber($waybill->branch, $waybill->orderNumber, $waybill->cpeType));

        if ($found instanceof Outcome) {
            return $found;
        }

        ['data' => $data, 'errors' => $errors] = $found;

        if ($data['ctg'] === null) {
            $detail = $errors !== []
                ? ' ARCA respondió: '.implode('; ', array_map(fn (Reason $reason): string => "{$reason->code} {$reason->message}", $errors)).'.'
                : '';

            return Outcome::unavailable(new Reason('not_found', 'ARCA no tiene una carta de porte con este número de orden; es seguro reenviarla.'.$detail));
        }

        if (! $this->matchesIdentity($waybill, $data)) {
            return Outcome::rejected([new Reason('order_number_taken', "ARCA ya tiene otra carta de porte con este número de orden: CTG {$data['ctg']}, con otro chofer, patentes o peso bruto.")]);
        }

        return Outcome::authorized($data['ctg'], $data, $errors);
    }

    private function reconcileCancellation(CallContext $context, ArcaWaybillAnnulment $annulment): Outcome
    {
        $query = $annulment->ctg !== null
            ? ArcaWaybillQuery::byCtg($annulment->ctg)
            : ArcaWaybillQuery::byOrderNumber($annulment->branch, $annulment->orderNumber, $annulment->cpeType);

        $found = $this->consult($context, $query);

        if ($found instanceof Outcome) {
            return $found;
        }

        ['data' => $data, 'errors' => $errors] = $found;

        if ($data['state'] === self::STATE_ANNULLED) {
            return Outcome::authorized($data['ctg'] ?? $annulment->ctg ?? "{$annulment->branch}-{$annulment->orderNumber}", $data, $errors);
        }

        return Outcome::unavailable(new Reason('not_annulled', 'ARCA no tiene la carta de porte anulada (estado '.($data['state'] ?? 'desconocido').'); es seguro reintentar la anulación.'));
    }

    /**
     * Chofer, patentes (normalizadas y ordenadas) y bruto; si la respuesta no trae alguno, no coincide.
     *
     * @param  array{ctg: ?string, state: ?string, issued_at: ?CarbonImmutable, expires_at: ?CarbonImmutable, pdf: ?string, driver_cuit: ?string, plates: ?list<string>, gross_kg: ?int}  $data
     */
    private function matchesIdentity(ArcaWaybill $waybill, array $data): bool
    {
        if ($data['driver_cuit'] === null || $data['plates'] === null || $data['gross_kg'] === null) {
            return false;
        }

        $normalize = function (array $plates): array {
            $plates = array_map(fn (string $plate): string => strtoupper((string) preg_replace('/\s+/', '', $plate)), $plates);
            sort($plates);

            return $plates;
        };

        return $data['driver_cuit'] === $waybill->driverCuit->value
            && $normalize($data['plates']) === $normalize($waybill->normalizedPlates())
            && $data['gross_kg'] === $waybill->grossKg;
    }

    /**
     * @return Outcome|array{data: array{ctg: ?string, state: ?string, issued_at: ?CarbonImmutable, expires_at: ?CarbonImmutable, pdf: ?string, driver_cuit: ?string, plates: ?list<string>, gross_kg: ?int}, errors: list<Reason>} el desenlace si la consulta falló o fue ambigua
     */
    private function consult(CallContext $context, ArcaWaybillQuery $query): Outcome|array
    {
        $found = $this->consultRaw($context, 'consultarCPEAutomotor', fn (AccessTicket $ticket, Cuit $cuit) => $query->envelope($ticket, $cuit));

        if ($found instanceof Outcome) {
            return $found;
        }

        ['respuesta' => $respuesta, 'errors' => $errors] = $found;

        return ['data' => WaybillResult::data($respuesta), 'errors' => $errors];
    }

    /**
     * Pedido de sólo lectura: ticket, transporte, `<respuesta>` y el 500 de ARCA, comunes a toda consulta.
     *
     * @param  \Closure(AccessTicket, Cuit): string  $envelope
     * @return Outcome|array{respuesta: \SimpleXMLElement, errors: list<Reason>} el desenlace si la consulta falló o fue ambigua
     */
    private function consultRaw(CallContext $context, string $method, \Closure $envelope): Outcome|array
    {
        $response = $this->call($context, $method, $envelope);

        if ($response instanceof Outcome) {
            return $response;
        }

        ['respuesta' => $respuesta, 'errors' => $errors] = WaybillResult::fromResponse($response);

        if ($respuesta === null) {
            return $this->malformed();
        }

        if ($failure = $this->ambiguous($errors, checkAlreadyIssued: false)) {
            return $failure;
        }

        return ['respuesta' => $respuesta, 'errors' => $errors];
    }

    /**
     * @param  \Closure(AccessTicket, Cuit): string  $envelope
     * @return string|Outcome el cuerpo de la respuesta, o el desenlace si falló antes
     */
    private function call(CallContext $context, string $method, \Closure $envelope): string|Outcome
    {
        $cuit = Cuit::from($context->representedTaxId);
        $ticket = $this->tickets->for($context, self::SERVICE);

        if ($ticket instanceof Outcome) {
            return $ticket;
        }

        $result = $this->transport->call(new SoapCall(
            self::SERVICE,
            $method,
            $this->endpoints->url(self::SERVICE, $context->environment),
            WscpeXml::soapAction($method),
            $envelope($ticket, $cuit),
            $context->environment,
            $cuit->value,
        ));

        return $result->failure ?? (string) $result->body;
    }

    /**
     * @param  list<Reason>  $errors
     */
    private function ambiguous(array $errors, bool $checkAlreadyIssued = true): ?Outcome
    {
        $codes = array_map(fn (Reason $reason): string => $reason->code, $errors);

        if (in_array(self::INTERNAL_ERROR, $codes, true)) {
            return Outcome::unknown(new Reason('internal_error', 'WSCPE: error interno de ARCA (500).'));
        }

        if ($checkAlreadyIssued && in_array(self::ALREADY_ISSUED, $codes, true)) {
            return Outcome::unknown(new Reason('already_issued', 'WSCPE: la carta de porte ya fue emitida (2241); se resuelve consultándola.'));
        }

        return null;
    }

    private function malformed(): Outcome
    {
        return Outcome::unknown(new Reason('malformed_response', 'WSCPE respondió sin el elemento <respuesta>.'));
    }
}
