<?php

namespace Mnonm\FiscalAdapter\Arca\Soap;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Mnonm\FiscalAdapter\CallObserver;
use Mnonm\FiscalAdapter\CallRecord;
use Mnonm\FiscalAdapter\Outcome;
use Mnonm\FiscalAdapter\Reason;

/**
 * SOAP 1.1 sobre el cliente HTTP de Laravel (sin ext-soap). Cada llamada se informa al observer con
 * los secretos tapados, y el resultado trae el desenlace ya clasificado: nunca lanza por fallas de ARCA.
 */
final class SoapTransport
{
    public function __construct(private readonly CallObserver $observer, private readonly int $timeoutSeconds = 30) {}

    public function call(SoapCall $call): SoapResult
    {
        $occurredAt = now()->toImmutable();
        $startedAt = hrtime(true);
        $status = null;
        $body = null;

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withHeaders(['SOAPAction' => $call->soapAction])
                ->withBody($call->envelope, 'text/xml; charset=utf-8')
                ->post($call->url);

            $status = $response->status();
            $body = $response->body();

            $fault = $this->faultString($body);
            if ($fault !== null) {
                $fault = Redactor::redact($fault);
                $failure = $this->isClientFault($body)
                    ? Outcome::rejected([new Reason('soap_client_fault', $fault)])
                    : Outcome::unavailable(new Reason('server_fault', "ARCA devolvió un error de servidor: {$fault}"));
            } elseif (! $response->successful()) {
                $failure = Outcome::unknown(new Reason('http_error', "ARCA respondió HTTP {$status} sin SOAP Fault."));
            } else {
                $this->record($call, $occurredAt, $startedAt, $status, $body, null);

                return SoapResult::ok($body);
            }
        } catch (ConnectionException $exception) {
            $message = Redactor::redact($exception->getMessage());
            $failure = self::isCutAfterSending($message)
                ? Outcome::unknown(new Reason('response_cut', "La conexión con ARCA se cortó después de enviar el pedido: {$message}"))
                : Outcome::unavailable(new Reason('connection_failed', "No se pudo conectar con ARCA: {$message}"));
        }

        $this->record($call, $occurredAt, $startedAt, $status, $body, $failure->reasons[0]->message);

        return SoapResult::failed($failure);
    }

    /**
     * Errores de cURL que pueden ocurrir con el pedido ya enviado (ARCA pudo procesarlo): timeout,
     * respuesta vacía o parcial, conexión cortada al enviar o recibir, stream HTTP/2 roto. Los que
     * cortan antes de enviar (DNS, conexión rechazada, TLS) no son ambiguos.
     */
    private static function isCutAfterSending(string $message): bool
    {
        return preg_match('/cURL error (18|28|52|55|56|92)\b/', $message) === 1;
    }

    private function faultString(string $body): ?string
    {
        if (! preg_match('/<(?:[\w-]+:)?Fault\b/i', $body)) {
            return null;
        }
        preg_match('/<faultstring[^>]*>(.*?)<\/faultstring>/is', $body, $matches);

        return trim(html_entity_decode($matches[1] ?? 'sin faultstring'));
    }

    /**
     * `soap:Client` (SOAP 1.1) o `soap:Sender` (1.2): la culpa es del sobre, no del servidor.
     */
    private function isClientFault(string $body): bool
    {
        return (bool) preg_match('/<faultcode[^>]*>\s*(?:[\w-]+:)?(?:Client|Sender)\b/i', $body);
    }

    private function record(SoapCall $call, CarbonImmutable $occurredAt, int $startedAt, ?int $status, ?string $response, ?string $error): void
    {
        $this->observer->record(new CallRecord(
            authority: 'arca',
            service: $call->service,
            method: $call->method,
            environment: $call->environment,
            url: $call->url,
            representedTaxId: $call->representedTaxId,
            request: Redactor::redact($call->envelope),
            response: $response === null ? null : Redactor::redact($response),
            httpStatus: $status,
            durationMs: intdiv(hrtime(true) - $startedAt, 1_000_000),
            error: $error,
            occurredAt: $occurredAt,
        ));
    }
}
