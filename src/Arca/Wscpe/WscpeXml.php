<?php

namespace Mnonm\FiscalAdapter\Arca\Wscpe;

use Mnonm\FiscalAdapter\Arca\Cuit;
use Mnonm\FiscalAdapter\Arca\Wsaa\AccessTicket;

/**
 * Sobre de WSCPE. El elemento raíz va en el namespace del servicio y sus hijos sin namespace (el
 * esquema no declara elementFormDefault); el orden de los hijos es el del esquema.
 */
final class WscpeXml
{
    public const NS = 'https://serviciosjava.afip.gob.ar/wscpe/';

    public const TIMEZONE = 'America/Argentina/Buenos_Aires';

    public static function soapAction(string $method): string
    {
        return self::NS.$method;
    }

    public static function envelope(string $element, AccessTicket $ticket, Cuit $represented, string $body = ''): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:wsc="'.self::NS.'">'
            .'<soapenv:Header/><soapenv:Body>'
            ."<wsc:{$element}>"
            .'<auth><token>'.self::text($ticket->token).'</token><sign>'.self::text($ticket->sign).'</sign>'
            ."<cuitRepresentada>{$represented->value}</cuitRepresentada></auth>"
            .$body
            ."</wsc:{$element}>"
            .'</soapenv:Body></soapenv:Envelope>';
    }

    public static function text(string|int $value): string
    {
        return htmlspecialchars((string) $value, ENT_XML1 | ENT_SUBSTITUTE);
    }

    public static function dateTime(\DateTimeInterface $value): string
    {
        return \DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new \DateTimeZone(self::TIMEZONE))
            ->format('Y-m-d\TH:i:s');
    }
}
