<?php

namespace Mnonm\FiscalAdapter\Arca\Wsaa;

/**
 * Guarda los tickets de acceso entre procesos.
 *
 * La implementación debe ser durable (base, archivo): un cache que se vacía deja la credencial sin
 * autenticar hasta 12 h, porque WSAA no emite un ticket nuevo mientras el anterior siga vigente.
 */
interface TicketStore
{
    /**
     * Null también cuando el ticket guardado no se puede leer (p. ej. no se descifra).
     */
    public function get(TicketKey $key): ?AccessTicket;

    public function put(TicketKey $key, AccessTicket $ticket): void;

    public function forget(TicketKey $key): void;
}
