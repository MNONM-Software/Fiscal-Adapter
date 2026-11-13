<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Mnonm\FiscalAdapter\Arca\Soap\SoapCall;
use Mnonm\FiscalAdapter\Arca\Soap\SoapTransport;
use Mnonm\FiscalAdapter\CallObserver;
use Mnonm\FiscalAdapter\CallRecord;
use Mnonm\FiscalAdapter\Environment;

function recordingObserver(): object
{
    return new class implements CallObserver
    {
        /** @var list<CallRecord> */
        public array $records = [];

        public function record(CallRecord $record): void
        {
            $this->records[] = $record;
        }
    };
}

function soapCall(string $envelope = '<env/>'): SoapCall
{
    return new SoapCall('wscpe', 'dummy', 'https://arca.test/ws', 'urn:dummy', $envelope, Environment::Homologation, '30712345671');
}

function soapFault(string $code, string $message): string
{
    return "<soap:Envelope xmlns:soap=\"http://schemas.xmlsoap.org/soap/envelope/\"><soap:Body><soap:Fault><faultcode>{$code}</faultcode><faultstring>{$message}</faultstring></soap:Fault></soap:Body></soap:Envelope>";
}

it('returns the body on 200', function () {
    Http::fake(['*' => Http::response('<ok/>', 200)]);

    $result = (new SoapTransport(recordingObserver()))->call(soapCall());

    expect($result->succeeded())->toBeTrue()->and($result->body)->toBe('<ok/>')->and($result->failure)->toBeNull();
});

it('connection refused is unavailable', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));

    $failure = (new SoapTransport(recordingObserver()))->call(soapCall())->failure;

    expect($failure->isUnavailable())->toBeTrue()->and($failure->reasonCodes())->toBe(['connection_failed']);
});

it('cut after sending is unknown', function (int $n) {
    Http::fake(fn () => throw new ConnectionException("cURL error {$n}: x"));

    $failure = (new SoapTransport(recordingObserver()))->call(soapCall())->failure;

    expect($failure->isUnknown())->toBeTrue()->and($failure->reasonCodes())->toBe(['response_cut']);
})->with([18, 28, 52, 55, 56, 92]);

it('non-2xx without fault is unknown', function () {
    Http::fake(['*' => Http::response('Bad gateway', 502)]);

    $failure = (new SoapTransport(recordingObserver()))->call(soapCall())->failure;

    expect($failure->isUnknown())->toBeTrue()->and($failure->reasonCodes())->toBe(['http_error']);
});

it('client fault is a rejection', function (string $code) {
    Http::fake(['*' => Http::response(soapFault($code, 'Token expirado'), 500)]);

    $failure = (new SoapTransport(recordingObserver()))->call(soapCall())->failure;

    expect($failure->isRejected())->toBeTrue()
        ->and($failure->reasonCodes())->toBe(['soap_client_fault'])
        ->and($failure->reasons[0]->message)->toBe('Token expirado');
})->with(['soap:Client', 'soap:Sender', 'Client']);

it('server fault is unavailable', function () {
    Http::fake(['*' => Http::response(soapFault('soap:Server', 'x'), 500)]);

    $failure = (new SoapTransport(recordingObserver()))->call(soapCall())->failure;

    expect($failure->isUnavailable())->toBeTrue()->and($failure->reasonCodes())->toBe(['server_fault']);
});

it('sends SOAPAction and text/xml', function () {
    Http::fake(['*' => Http::response('<ok/>', 200)]);

    (new SoapTransport(recordingObserver()))->call(soapCall());

    Http::assertSent(fn ($r) => $r->header('SOAPAction')[0] === 'urn:dummy'
        && $r->header('Content-Type')[0] === 'text/xml; charset=utf-8');
});

it('records every call, redacted', function () {
    Http::fake(['*' => Http::response('<r>&lt;token&gt;T2&lt;/token&gt;</r>', 200)]);
    $observer = recordingObserver();

    (new SoapTransport($observer))->call(soapCall('<e><token>TOK</token><sign>SIG</sign></e>'));

    $record = $observer->records[0];
    expect($observer->records)->toHaveCount(1)
        ->and($record->request.$record->response)->not->toContain('TOK')->not->toContain('SIG')->not->toContain('T2')
        ->and($record->request)->toContain('[REDACTED]')
        ->and($record->response)->toContain('[REDACTED]')
        ->and($record->authority)->toBe('arca')
        ->and($record->httpStatus)->toBe(200)
        ->and($record->durationMs)->toBeGreaterThanOrEqual(0)
        ->and($record->service)->toBe('wscpe')
        ->and($record->method)->toBe('dummy')
        ->and($record->environment)->toBe(Environment::Homologation)
        ->and($record->representedTaxId)->toBe('30712345671')
        ->and($record->url)->toBe('https://arca.test/ws')
        ->and($record->error)->toBeNull();
});

it('records failures with error and no response when there was no body', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));
    $observer = recordingObserver();

    (new SoapTransport($observer))->call(soapCall());

    expect($observer->records[0]->error)->not->toBeNull()
        ->and($observer->records[0]->response)->toBeNull()
        ->and($observer->records[0]->httpStatus)->toBeNull();
});

it('failure messages carry no secrets', function () {
    $envelope = '<e><token>TOK</token><sign>SIG</sign></e>';
    $cases = [
        fn () => throw new ConnectionException('cURL error 7: Failed to connect'),
        fn () => throw new ConnectionException('cURL error 28: timed out'),
        fn () => Http::response('Bad gateway', 502),
        fn () => Http::response(soapFault('soap:Client', 'malo <token>TOK</token>'), 500),
        fn () => Http::response(soapFault('soap:Server', 'x'), 500),
    ];

    foreach ($cases as $case) {
        Http::fake($case);
        $failure = (new SoapTransport(recordingObserver()))->call(soapCall($envelope))->failure;
        foreach ($failure->reasons as $reason) {
            expect($reason->message)->not->toContain('TOK')->not->toContain('SIG');
        }
        Http::swap(app(Factory::class));
    }
});
