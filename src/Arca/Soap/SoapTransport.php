<?php

namespace Mnonm\FiscalAdapter\Arca\Soap;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
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
            $failure = $this->classify($response);

            if ($failure === null) {
                $this->record($call, $occurredAt, $startedAt, $status, $body, null);

                return SoapResult::ok($body);
            }
        } catch (ConnectionException $exception) {
            $message = Redactor::redact($exception->getMessage());
            $failure = self::isCutAfterSending($message)
                ? Outcome::unknown(new Reason('response_cut', "La conexión con ARCA se cortó después de enviar el pedido: {$message}"))
                : Outcome::unavailable(new Reason('connection_failed', "No se pudo conectar con ARCA: {$message}"));
        } catch (RequestException $exception) {
            // Laravel la lanza cuando llegaron los encabezados (status >= 400, como el 500 de un SOAP
            // Fault) y después falló la transferencia. Con un código de corte, el pedido pudo llegar.
            $status = $exception->response->status();
            $body = $exception->response->body();
            $cut = self::cutCode($exception);
            $failure = $cut !== null
                ? Outcome::unknown(new Reason('response_cut', "La conexión con ARCA se cortó después de recibir los encabezados (cURL error {$cut}), con HTTP {$status}."))
                : ($this->classify($exception->response) ?? Outcome::unknown(new Reason('http_error', "ARCA respondió HTTP {$status} y la transferencia falló.")));
        }

        $this->record($call, $occurredAt, $startedAt, $status, $body, $failure->reasons[0]->message);

        return SoapResult::failed($failure);
    }

    /**
     * El desenlace de una respuesta HTTP, o null si es un 2xx sin SOAP Fault.
     */
    private function classify(Response $response): ?Outcome
    {
        $body = $response->body();
        $fault = $this->faultString($body);

        if ($fault !== null) {
            $fault = Redactor::redact($fault);

            return $this->isClientFault($body)
                ? Outcome::rejected([new Reason('soap_client_fault', $fault)])
                : Outcome::unavailable(new Reason('server_fault', "ARCA devolvió un error de servidor: {$fault}"));
        }

        if (! $response->successful()) {
            return Outcome::unknown(new Reason('http_error', "ARCA respondió HTTP {$response->status()} sin SOAP Fault."));
        }

        return null;
    }

    /**
     * El código de corte de cURL de una transferencia que falló con la respuesta ya empezada: del
     * contexto del handler (errno) o, si no está, del mensaje de la excepción o de su causa.
     */
    private static function cutCode(RequestException $exception): ?int
    {
        $errorData = $exception->response->transferStats?->getHandlerErrorData();
        $candidates = [];

        if (is_int($errorData)) {
            $candidates[] = "cURL error {$errorData}";
        } elseif ($errorData instanceof \Throwable) {
            $candidates[] = $errorData->getMessage();
        }

        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            $candidates[] = $cause->getMessage();
        }

        foreach ($candidates as $candidate) {
            if (preg_match('/cURL error (18|28|52|55|56|92)\b/', $candidate, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return null;
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

    /**
     * Un observer que falla se reporta y no cambia el resultado: la llamada a ARCA ya ocurrió, y
     * convertir un `Authorized` en una excepción haría reintentar algo que quedó emitido.
     */
    private function record(SoapCall $call, CarbonImmutable $occurredAt, int $startedAt, ?int $status, ?string $response, ?string $error): void
    {
        try {
            $this->notify($call, $occurredAt, $startedAt, $status, $response, $error);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function notify(SoapCall $call, CarbonImmutable $occurredAt, int $startedAt, ?int $status, ?string $response, ?string $error): void
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
            reference: $call->reference,
        ));
    }
}
