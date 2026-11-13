<?php

namespace Mnonm\FiscalAdapter\Arca;

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

        $response = $this->call($context, 'consultarCPEAutomotor', fn (AccessTicket $ticket, Cuit $cuit) => $query->envelope($ticket, $cuit));

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

        return $data['ctg'] !== null
            ? Outcome::authorized($data['ctg'], $data, $errors)
            : Outcome::rejected($errors !== [] ? $errors : [new Reason('no_ctg', 'ARCA no devolvió la carta de porte ni errores.')]);
    }

    public function reconcile(CallContext $context, Issuable|Cancellation $document): Outcome
    {
        // TODO Task 9
        return Outcome::unknown(new Reason('not_implemented', 'La reconciliación todavía no está implementada.'));
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
