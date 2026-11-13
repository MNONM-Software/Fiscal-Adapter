<?php

namespace Mnonm\FiscalAdapter;

/**
 * Recibe un {@see CallRecord} por cada llamada al organismo, ya sin secretos.
 *
 * No debe lanzar: la llamada ya ocurrió y su resultado vale aunque el registro falle. Si lanza, la
 * librería reporta la excepción (`report()`) y devuelve el resultado igual, sin registro. Tener en
 * cuenta que la respuesta puede ser grande (p. ej. un PDF en base64).
 */
interface CallObserver
{
    public function record(CallRecord $record): void;
}
