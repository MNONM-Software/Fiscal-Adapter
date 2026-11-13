<?php

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Mnonm\FiscalAdapter\Arca\ArcaEndpoints;
use Mnonm\FiscalAdapter\Arca\Soap\SoapTransport;
use Mnonm\FiscalAdapter\Arca\Wsaa\AccessTicket;
use Mnonm\FiscalAdapter\Arca\Wsaa\WsaaClient;
use Mnonm\FiscalAdapter\CallContext;
use Mnonm\FiscalAdapter\CallObserver;
use Mnonm\FiscalAdapter\CallRecord;
use Mnonm\FiscalAdapter\Credential;
use Mnonm\FiscalAdapter\Environment;
use Mnonm\FiscalAdapter\Tests\Support\TestCertificate;
use Mnonm\FiscalAdapter\Tests\Support\WsaaResponses;

function wsaaFixture(?object $observer = null, ?string $passphrase = null): array
{
    $dir = sys_get_temp_dir().'/wsaa-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $files = TestCertificate::create($dir, $passphrase);
    $credential = Credential::fromFiles($files['certificate'], $files['private_key'], $passphrase);
    array_map('unlink', glob($dir.'/*'));
    rmdir($dir);

    $observer ??= new class implements CallObserver
    {
        /** @var list<CallRecord> */
        public array $records = [];

        public function record(CallRecord $record): void
        {
            $this->records[] = $record;
        }
    };

    $client = new WsaaClient(new SoapTransport($observer), new ArcaEndpoints);

    return [$client, new CallContext($credential, '30712345671', Environment::Homologation), $observer];
}

afterEach(fn () => Carbon::setTestNow());

it('builds the TRA with a -60s/+10min window', function () {
    Carbon::setTestNow('2026-10-08T13:00:00Z');
    [$client] = wsaaFixture();

    $tra = simplexml_load_string($client->buildTra('wscpe'));

    expect((string) $tra['version'])->toBe('1.0')
        ->and((string) $tra->service)->toBe('wscpe')
        ->and((string) $tra->header->uniqueId)->toBe((string) CarbonImmutable::parse('2026-10-08T13:00:00Z')->getTimestamp())
        ->and(CarbonImmutable::parse((string) $tra->header->generationTime)->equalTo(CarbonImmutable::parse('2026-10-08T12:59:00Z')))->toBeTrue()
        ->and(CarbonImmutable::parse((string) $tra->header->expirationTime)->equalTo(CarbonImmutable::parse('2026-10-08T13:10:00Z')))->toBeTrue();
});

it('signs the TRA as non-detached binary CMS', function (?string $passphrase) {
    [$client, $context] = wsaaFixture(passphrase: $passphrase);
    $tra = $client->buildTra('wscpe');

    $cms = $client->signTra($tra, $context->credential);

    expect($cms)->not->toContain("\n");
    $der = base64_decode($cms, true);
    expect($der)->not->toBeFalse();
    $pem = "-----BEGIN PKCS7-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PKCS7-----\n";

    $dir = sys_get_temp_dir();
    $smime = tempnam($dir, 'p7m');
    $content = tempnam($dir, 'out');
    try {
        file_put_contents($smime, "MIME-Version: 1.0\nContent-Type: application/x-pkcs7-mime; smime-type=signed-data; name=\"smime.p7m\"\nContent-Transfer-Encoding: base64\n\n".chunk_split($cms, 64, "\n"));
        expect(openssl_pkcs7_verify($smime, PKCS7_NOVERIFY, null, [], null, $content))->toBeTrue()
            ->and(file_get_contents($content))->toBe($tra);
        $certs = [];
        expect(openssl_pkcs7_read($pem, $certs))->toBeTrue();
    } finally {
        @unlink($smime);
        @unlink($content);
    }
})->with([[null], ['s3cret']]);

it('logs in and parses the escaped ticket', function () {
    Http::fake(['*' => Http::response(WsaaResponses::loginCms('TOK', 'SIG', '2026-10-08T22:00:00-03:00'), 200)]);
    [$client, $context] = wsaaFixture();

    $ticket = $client->login($context, 'wscpe');

    expect($ticket)->toBeInstanceOf(AccessTicket::class)
        ->and($ticket->token)->toBe('TOK')
        ->and($ticket->sign)->toBe('SIG')
        ->and($ticket->expiresAt->equalTo(CarbonImmutable::parse('2026-10-09T01:00:00Z')))->toBeTrue();
});

it('unescapes the ticket only once', function () {
    Http::fake(['*' => Http::response(WsaaResponses::loginCms('A&amp;B', 'S&lt;1', '2026-10-08T22:00:00-03:00'), 200)]);
    [$client, $context] = wsaaFixture();

    $ticket = $client->login($context, 'wscpe');

    expect($ticket->token)->toBe('A&B')->and($ticket->sign)->toBe('S<1');
});

it('posts loginCms to the environment URL', function () {
    Http::fake(['*' => Http::response(WsaaResponses::loginCms('TOK', 'SIG', '2026-10-08T22:00:00-03:00'), 200)]);
    [$client, $context, $observer] = wsaaFixture();

    $client->login($context, 'wscpe');

    Http::assertSent(fn ($r) => $r->url() === 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms'
        && $r->header('SOAPAction')[0] === '""'
        && str_contains($r->body(), 'xmlns:wsaa="http://wsaa.view.sua.dvadac.desein.afip.gov"')
        && preg_match('/<wsaa:in0>[A-Za-z0-9+\/=]{100,}<\/wsaa:in0>/', $r->body()) === 1);

    $record = $observer->records[0];
    expect($record->service)->toBe('wsaa')
        ->and($record->method)->toBe('loginCms')
        ->and($record->representedTaxId)->toBe('30712345671')
        ->and($record->request)->toContain('<wsaa:in0>[REDACTED]</wsaa:in0>')
        ->and($record->response)->not->toContain('TOK')->not->toContain('SIG');
});

it('returns the transport failure as outcome', function () {
    Http::fake(['*' => Http::response(WsaaResponses::alreadyHasTicket(), 500)]);
    [$client, $context] = wsaaFixture();

    $outcome = $client->login($context, 'wscpe');

    expect($outcome->isRejected())->toBeTrue()->and($outcome->reasonCodes())->toBe(['soap_client_fault']);
});

it('missing loginCmsReturn is unknown', function () {
    Http::fake(['*' => Http::response(WsaaResponses::withoutReturn(), 200)]);
    [$client, $context] = wsaaFixture();

    $outcome = $client->login($context, 'wscpe');

    expect($outcome->isUnknown())->toBeTrue()->and($outcome->reasonCodes())->toBe(['malformed_response']);
});

it('ticket is usable until the margin', function () {
    Carbon::setTestNow('2026-10-08T13:00:00Z');
    $ticket = new AccessTicket('T', 'S', CarbonImmutable::parse('2026-10-08T13:10:00Z'));

    expect($ticket->isUsable())->toBeTrue();
    Carbon::setTestNow('2026-10-08T13:05:01Z');
    expect($ticket->isUsable())->toBeFalse()->and($ticket->isUsable(60))->toBeTrue();
});

it('ticket debug info hides token and sign', function () {
    $ticket = new AccessTicket('SECRET-TOK', 'SECRET-SIG', CarbonImmutable::parse('2026-10-08T13:10:00Z'));

    expect(print_r($ticket, true))->not->toContain('SECRET')
        ->and($ticket->__debugInfo())->toHaveKey('expiresAt');
});
