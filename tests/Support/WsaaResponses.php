<?php

namespace Mnonm\FiscalAdapter\Tests\Support;

/**
 * Hand-built WSAA SOAP responses to fake `loginCms`.
 */
class WsaaResponses
{
    /**
     * The ticket travels as escaped XML inside `loginCmsReturn`.
     */
    public static function loginCms(string $token, string $sign, string $expiration): string
    {
        $ticket = htmlspecialchars("<?xml version=\"1.0\" encoding=\"UTF-8\"?><loginTicketResponse version=\"1.0\"><header><source>CN=wsaahomo</source><destination>SERIALNUMBER=CUIT 30712345671</destination><uniqueId>1</uniqueId><generationTime>2026-10-08T10:00:00-03:00</generationTime><expirationTime>{$expiration}</expirationTime></header><credentials><token>{$token}</token><sign>{$sign}</sign></credentials></loginTicketResponse>");

        return "<soapenv:Envelope xmlns:soapenv=\"http://schemas.xmlsoap.org/soap/envelope/\"><soapenv:Body><loginCmsResponse xmlns=\"http://wsaa.view.sua.dvadac.desein.afip.gov\"><loginCmsReturn>{$ticket}</loginCmsReturn></loginCmsResponse></soapenv:Body></soapenv:Envelope>";
    }

    public static function alreadyHasTicket(): string
    {
        return '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><soap:Fault><faultcode>soap:Client</faultcode><faultstring>El CEE ya posee un TA valido para el acceso al WSN solicitado</faultstring></soap:Fault></soap:Body></soap:Envelope>';
    }

    public static function withoutReturn(): string
    {
        return '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body><loginCmsResponse xmlns="http://wsaa.view.sua.dvadac.desein.afip.gov"/></soapenv:Body></soapenv:Envelope>';
    }
}
