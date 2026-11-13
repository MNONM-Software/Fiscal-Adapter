<?php

namespace Mnonm\FiscalAdapter\Arca\Wsaa;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Mnonm\FiscalAdapter\CallContext;
use Mnonm\FiscalAdapter\Exceptions\InvalidCredential;
use Mnonm\FiscalAdapter\Outcome;
use Mnonm\FiscalAdapter\Reason;

/**
 * Devuelve el ticket de acceso vigente de una credencial para un servicio, renovándolo contra WSAA
 * sólo cuando hace falta. El ticket se guarda en un {@see TicketStore} durable: WSAA no emite uno
 * nuevo mientras el anterior esté vigente, así que perderlo deja sin autenticar hasta que venza.
 *
 * `for()` no se llama dentro de una transacción REPEATABLE READ de quien usa la librería: la
 * relectura dentro del lock no vería el ticket que otro worker acaba de commitear, y se pediría uno
 * nuevo que WSAA rechaza con «ya posee un TA válido».
 */
final class AccessTicketProvider
{
    private ?Closure $beforeLogin = null;

    public function __construct(
        private readonly WsaaClient $wsaa,
        private readonly TicketStore $store,
        private readonly int $lockSeconds = 90,
    ) {}

    /**
     * @throws InvalidCredential
     */
    public function for(CallContext $context, string $service): AccessTicket|Outcome
    {
        $key = $this->keyFor($context, $service);

        if ($ticket = $this->usable($key)) {
            return $ticket;
        }

        try {
            return Cache::lock('fiscal-adapter:arca:wsaa:'.$key->toString(), $this->lockSeconds)
                ->block($this->lockSeconds, fn () => $this->renew($context, $service, $key));
        } catch (LockTimeoutException) {
            return Outcome::unavailable(new Reason('lock_timeout', "Otro proceso está renovando el ticket de acceso de ARCA para {$service} y no terminó en {$this->lockSeconds} s; se reintenta."));
        }
    }

    public function forget(CallContext $context, string $service): void
    {
        $this->store->forget($this->keyFor($context, $service));
    }

    /**
     * Hook de test: corre dentro del lock, antes de releer. Simula otro proceso que renovó el ticket
     * mientras éste esperaba. No usar en código de producción.
     */
    public function beforeLogin(Closure $callback): void
    {
        $this->beforeLogin = $callback;
    }

    private function renew(CallContext $context, string $service, TicketKey $key): AccessTicket|Outcome
    {
        if ($this->beforeLogin) {
            ($this->beforeLogin)();
        }

        if ($ticket = $this->usable($key)) {
            return $ticket;
        }

        $result = $this->wsaa->login($context, $service);

        if ($result instanceof Outcome) {
            return $this->isTicketAlreadyHeld($result)
                ? Outcome::unavailable(new Reason('ticket_already_held', 'WSAA dice que ya hay un ticket de acceso vigente para este servicio y no lo tenemos guardado; se podrá autenticar cuando venza (hasta 12 horas).'))
                : $result;
        }

        $this->store->put($key, $result);

        return $result;
    }

    private function isTicketAlreadyHeld(Outcome $outcome): bool
    {
        foreach ($outcome->reasons as $reason) {
            if ($reason->code === 'soap_client_fault' && stripos($reason->message, 'ya posee un TA valido') !== false) {
                return true;
            }
        }

        return false;
    }

    private function usable(TicketKey $key): ?AccessTicket
    {
        $ticket = $this->store->get($key);

        return $ticket?->isUsable() ? $ticket : null;
    }

    private function keyFor(CallContext $context, string $service): TicketKey
    {
        return new TicketKey($context->credential->fingerprint(), $service, $context->environment);
    }
}
