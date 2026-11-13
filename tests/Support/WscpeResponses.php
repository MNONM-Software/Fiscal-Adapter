<?php

namespace Mnonm\FiscalAdapter\Tests\Support;

/**
 * Hand-built WSCPE SOAP responses.
 */
class WscpeResponses
{
    public const HEADER = '<cabecera><tipoCartaPorte>74</tipoCartaPorte><sucursal>1</sucursal><nroOrden>419</nroOrden><nroCTG>101234567890</nroCTG><fechaEmision>2026-10-08T10:15:00</fechaEmision><estado>AC</estado><fechaVencimiento>2026-10-13T10:15:00</fechaVencimiento></cabecera>';

    public const IDENTITY = '<datosCarga><codGrano>23</codGrano><pesoBruto>45000</pesoBruto><pesoTara>15000</pesoTara><cosecha>2526</cosecha></datosCarga>'
        .'<transporte><cuitTransportista>30798765430</cuitTransportista><dominio>AB123CD</dominio><cuitChofer>20123456786</cuitChofer></transporte>';

    public static function response(string $element, string $respuesta): string
    {
        return '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
            ."<ns2:{$element} xmlns:ns2=\"https://serviciosjava.afip.gob.ar/wscpe/\"><respuesta>{$respuesta}</respuesta></ns2:{$element}>"
            .'</soap:Body></soap:Envelope>';
    }

    public static function error(string $code, string $message = 'x'): string
    {
        return "<errores><error><codigo>{$code}</codigo><descripcion>{$message}</descripcion></error></errores>";
    }
}
