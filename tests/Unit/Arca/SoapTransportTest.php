<?php

use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Exceptions;
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

/**
 * What Laravel throws when the headers arrived (status >= 400) and the body was then cut.
 */
function cutRequestException(int $status, string $body, ?int $curlErrno = null): RequestException
{
    $response = new Response(new PsrResponse($status, [], $body));

    if ($curlErrno !== null) {
        $response->transferStats = new TransferStats(new PsrRequest('POST', 'https://arca.test/ws'), $response->toPsrResponse(), 30.0, $curlErrno);
    }

    return new RequestException($response);
}

describe('request exceptions with a response', function () {
    it('a cURL cut code is unknown', function (int $errno) {
        Http::fake(fn () => throw cutRequestException(500, '<soap:Envelope><soap:Bo', $errno));

        $failure = (new SoapTransport(recordingObserver()))->call(soapCall())->failure;

        expect($failure->isUnknown())->toBeTrue()->and($failure->reasonCodes())->toBe(['response_cut']);
    })->with([18, 28, 52, 55, 56, 92]);

    it('without a cut code is classified from its response', function (int $status, string $body, string $code, string $state) {
        Http::fake(fn () => throw cutRequestException($status, $body));

        $failure = (new SoapTransport(recordingObserver()))->call(soapCall())->failure;

        expect($failure->{$state}())->toBeTrue()->and($failure->reasonCodes())->toBe([$code]);
    })->with([
        'client fault' => [500, soapFault('soap:Client', 'Token expirado'), 'soap_client_fault', 'isRejected'],
        'server fault' => [500, soapFault('soap:Server', 'x'), 'server_fault', 'isUnavailable'],
        'no fault' => [502, 'Bad gateway', 'http_error', 'isUnknown'],
    ]);

    it('is recorded and never thrown', function () {
        Http::fake(fn () => throw cutRequestException(500, 'partial', 56));
        $observer = recordingObserver();

        $result = (new SoapTransport($observer))->call(soapCall());

        expect($result->succeeded())->toBeFalse()
            ->and($observer->records)->toHaveCount(1)
            ->and($observer->records[0]->httpStatus)->toBe(500)
            ->and($observer->records[0]->response)->toBe('partial')
            ->and($observer->records[0]->error)->not->toBeNull();
    });
});

it('reports an observer failure and still returns the result', function () {
    Exceptions::fake();
    Http::fake(['*' => Http::response('<ok/>', 200)]);
    $observer = new class implements CallObserver
    {
        public function record(CallRecord $record): void
        {
            throw new RuntimeException('observer down');
        }
    };

    $result = (new SoapTransport($observer))->call(soapCall());

    expect($result->succeeded())->toBeTrue()->and($result->body)->toBe('<ok/>');
    Exceptions::assertReported(RuntimeException::class);
});
