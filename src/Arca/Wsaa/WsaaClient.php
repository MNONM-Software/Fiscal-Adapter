<?php

namespace Mnonm\FiscalAdapter\Arca\Wsaa;

use Carbon\CarbonImmutable;
use Mnonm\FiscalAdapter\Arca\ArcaEndpoints;
use Mnonm\FiscalAdapter\Arca\Soap\SoapCall;
use Mnonm\FiscalAdapter\Arca\Soap\SoapTransport;
use Mnonm\FiscalAdapter\CallContext;
use Mnonm\FiscalAdapter\Credential;
use Mnonm\FiscalAdapter\Exceptions\InvalidCredential;
use Mnonm\FiscalAdapter\Outcome;
use Mnonm\FiscalAdapter\Reason;
use SimpleXMLElement;

/**
 * Autenticación contra WSAA: TRA firmado con el certificado de la credencial, loginCms y ticket.
 */
final class WsaaClient
{
    public function __construct(private readonly SoapTransport $transport, private readonly ArcaEndpoints $endpoints) {}

    /**
     * @throws InvalidCredential
     */
    public function login(CallContext $context, string $service): AccessTicket|Outcome
    {
        $cms = $this->signTra($this->buildTra($service), $context->credential);

        $envelope = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:wsaa="http://wsaa.view.sua.dvadac.desein.afip.gov">'
            .'<soapenv:Header/><soapenv:Body><wsaa:loginCms><wsaa:in0>'.$cms.'</wsaa:in0></wsaa:loginCms></soapenv:Body></soapenv:Envelope>';

        $result = $this->transport->call(new SoapCall(
            service: 'wsaa',
            method: 'loginCms',
            url: $this->endpoints->url('wsaa', $context->environment),
            soapAction: '""',
            envelope: $envelope,
            environment: $context->environment,
            representedTaxId: $context->representedTaxId,
        ));

        if (! $result->succeeded()) {
            return $result->failure;
        }

        return $this->parseLoginResponse($result->body);
    }

    /**
     * TRA (loginTicketRequest) con ventana de -60 s / +10 min alrededor de ahora, para tolerar
     * diferencias de reloj con ARCA.
     */
    public function buildTra(string $service): string
    {
        $now = now()->toImmutable();

        $tra = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><loginTicketRequest version="1.0"/>');
        $header = $tra->addChild('header');
        $header->addChild('uniqueId', (string) $now->getTimestamp());
        $header->addChild('generationTime', $now->subSeconds(60)->toIso8601String());
        $header->addChild('expirationTime', $now->addMinutes(10)->toIso8601String());
        $tra->addChild('service', $service);

        return (string) $tra->asXML();
    }

    /**
     * Firma el TRA en CMS (PKCS#7 signed-data, sin PKCS7_DETACHED: encapsula el TRA) y devuelve el
     * DER en base64 sin saltos, que es lo que espera loginCms.
     *
     * @throws InvalidCredential
     */
    public function signTra(string $tra, Credential $credential): string
    {
        $input = tempnam(sys_get_temp_dir(), 'tra');
        $output = tempnam(sys_get_temp_dir(), 'cms');

        try {
            file_put_contents($input, $tra);
            $signed = @openssl_pkcs7_sign(
                $input,
                $output,
                $credential->certificatePem(),
                [$credential->privateKeyPem(), $credential->passphrase() ?? ''],
                [],
                PKCS7_BINARY,
            );
            if (! $signed) {
                throw new InvalidCredential('No se pudo firmar el pedido de acceso a WSAA con la credencial.');
            }

            $parts = preg_split("/\r?\n\r?\n/", (string) file_get_contents($output), 2);

            return preg_replace('/\s+/', '', $parts[1] ?? '');
        } finally {
            @unlink($input);
            @unlink($output);
        }
    }

    /**
     * Lee `loginCmsReturn` como nodo de texto: el parser XML desescapa el ticket exactamente una vez.
     */
    private function parseLoginResponse(string $body): AccessTicket|Outcome
    {
        $envelope = @simplexml_load_string($body);
        $return = $envelope === false ? [] : $envelope->xpath('//*[local-name()="loginCmsReturn"]');
        if (! $return) {
            return Outcome::unknown(new Reason('malformed_response', 'WSAA respondió sin loginCmsReturn.'));
        }

        $ticket = @simplexml_load_string((string) $return[0]);
        $token = $ticket === false ? '' : (string) $ticket->credentials->token;
        $sign = $ticket === false ? '' : (string) $ticket->credentials->sign;
        $expiration = $ticket === false ? '' : (string) $ticket->header->expirationTime;
        if ($token === '' || $sign === '' || $expiration === '') {
            return Outcome::unknown(new Reason('malformed_response', 'WSAA respondió un ticket de acceso incompleto o que no es XML válido.'));
        }

        return new AccessTicket($token, $sign, CarbonImmutable::parse($expiration));
    }
}
