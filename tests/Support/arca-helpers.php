<?php

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Mnonm\FiscalAdapter\Arca\ArcaAuthority;
use Mnonm\FiscalAdapter\Arca\ArcaEndpoints;
use Mnonm\FiscalAdapter\Arca\Soap\SoapTransport;
use Mnonm\FiscalAdapter\Arca\Wsaa\AccessTicket;
use Mnonm\FiscalAdapter\Arca\Wsaa\AccessTicketProvider;
use Mnonm\FiscalAdapter\Arca\Wsaa\InMemoryTicketStore;
use Mnonm\FiscalAdapter\Arca\Wsaa\TicketKey;
use Mnonm\FiscalAdapter\Arca\Wsaa\WsaaClient;
use Mnonm\FiscalAdapter\Arca\Wscpe\AnnulmentReason;
use Mnonm\FiscalAdapter\Arca\Wscpe\ArcaWaybillAnnulment;
use Mnonm\FiscalAdapter\CallContext;
use Mnonm\FiscalAdapter\CallObserver;
use Mnonm\FiscalAdapter\CallRecord;
use Mnonm\FiscalAdapter\Credential;
use Mnonm\FiscalAdapter\Environment;
use Mnonm\FiscalAdapter\Tests\Support\TestCertificate;

function authorityCredential(): Credential
{
    $dir = sys_get_temp_dir().'/auth-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $files = TestCertificate::create($dir);
    $credential = Credential::fromFiles($files['certificate'], $files['private_key']);
    array_map('unlink', glob($dir.'/*'));
    rmdir($dir);

    return $credential;
}

/**
 * @return array{0: ArcaAuthority, 1: CallContext}
 */
function authority(bool $seedTicket = true): array
{
    Carbon::setTestNow('2026-10-08T12:00:00Z');
    Http::swap(new Factory);
    $store = new InMemoryTicketStore;
    $observer = new class implements CallObserver
    {
        public function record(CallRecord $record): void {}
    };
    $transport = new SoapTransport($observer);
    $endpoints = new ArcaEndpoints;
    $context = new CallContext(authorityCredential(), '30712345671', Environment::Homologation);

    if ($seedTicket) {
        $store->put(new TicketKey($context->credential->fingerprint(), 'wscpe', $context->environment), new AccessTicket('TOK', 'SIG', CarbonImmutable::parse('2026-10-08T18:00:00Z')));
    }

    $tickets = new AccessTicketProvider(new WsaaClient($transport, $endpoints), $store);

    return [new ArcaAuthority($tickets, $transport, $endpoints), $context];
}

function fakeWscpe(string $body): void
{
    Http::fake(['*wscpe*' => Http::response($body, 200)]);
}

function annulment(): ArcaWaybillAnnulment
{
    return new ArcaWaybillAnnulment(1, 419, AnnulmentReason::Other, 'x');
}

function soapClientFault(string $message): string
{
    return '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><soap:Fault>'
        ."<faultcode>soap:Client</faultcode><faultstring>{$message}</faultstring></soap:Fault></soap:Body></soap:Envelope>";
}
