<?php

namespace Mnonm\FiscalAdapter\Arca\Wsaa;

/**
 * Store en memoria, para tests. No es durable: no usar en producción.
 */
final class InMemoryTicketStore implements TicketStore
{
    /** @var array<string, AccessTicket> */
    private array $tickets = [];

    public function get(TicketKey $key): ?AccessTicket
    {
        return $this->tickets[$key->toString()] ?? null;
    }

    public function put(TicketKey $key, AccessTicket $ticket): void
    {
        $this->tickets[$key->toString()] = $ticket;
    }

    public function forget(TicketKey $key): void
    {
        unset($this->tickets[$key->toString()]);
    }
}
