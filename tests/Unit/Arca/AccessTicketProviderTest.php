<?php

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mnonm\FiscalAdapter\Arca\ArcaEndpoints;
use Mnonm\FiscalAdapter\Arca\Soap\SoapTransport;
use Mnonm\FiscalAdapter\Arca\Wsaa\AccessTicket;
use Mnonm\FiscalAdapter\Arca\Wsaa\AccessTicketProvider;
use Mnonm\FiscalAdapter\Arca\Wsaa\InMemoryTicketStore;
use Mnonm\FiscalAdapter\Arca\Wsaa\MissingTicketStore;
use Mnonm\FiscalAdapter\Arca\Wsaa\TicketKey;
use Mnonm\FiscalAdapter\Arca\Wsaa\WsaaClient;
use Mnonm\FiscalAdapter\CallContext;
use Mnonm\FiscalAdapter\CallObserver;
use Mnonm\FiscalAdapter\CallRecord;
use Mnonm\FiscalAdapter\Credential;
use Mnonm\FiscalAdapter\Environment;
use Mnonm\FiscalAdapter\Exceptions\FiscalAdapterException;
use Mnonm\FiscalAdapter\Tests\Support\TestCertificate;
use Mnonm\FiscalAdapter\Tests\Support\WsaaResponses;

function ticketCredential(): Credential
{
    $dir = sys_get_temp_dir().'/tkt-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $files = TestCertificate::create($dir);
    $credential = Credential::fromFiles($files['certificate'], $files['private_key']);
    array_map('unlink', glob($dir.'/*'));
    rmdir($dir);

    return $credential;
}

function ticketProvider(int $lockSeconds = 90): array
{
    $store = new InMemoryTicketStore;
    $observer = new class implements CallObserver
    {
        public function record(CallRecord $record): void {}
    };
    $provider = new AccessTicketProvider(new WsaaClient(new SoapTransport($observer), new ArcaEndpoints), $store, $lockSeconds);

    return [$provider, $store, new CallContext(ticketCredential(), '30712345671', Environment::Homologation)];
}

function ticketKey(CallContext $context, string $service = 'wscpe'): TicketKey
{
    return new TicketKey($context->credential->fingerprint(), $service, $context->environment);
}

function fakeLogin(string $token = 'NEW', string $expiration = '2026-10-09T10:00:00-03:00'): void
{
    Http::swap(new Factory);
    Http::fake(['*' => Http::response(WsaaResponses::loginCms($token, 'SIG', $expiration), 200)]);
}

afterEach(fn () => Carbon::setTestNow());

it('reuses a stored ticket that is still usable', function () {
    Carbon::setTestNow('2026-10-08T12:00:00Z');
    [$provider, $store, $context] = ticketProvider();
    $store->put(ticketKey($context), new AccessTicket('STORED', 'S', CarbonImmutable::parse('2026-10-08T18:00:00Z')));
    Http::fake();

    expect($provider->for($context, 'wscpe'))->toBeInstanceOf(AccessTicket::class)->token->toBe('STORED');
    Http::assertNothingSent();
});

it('renews five minutes before expiry', function () {
    $expiry = CarbonImmutable::parse('2026-10-08T18:00:00Z');

    Carbon::setTestNow($expiry->subMinutes(4));
    [$provider, $store, $context] = ticketProvider();
    $store->put(ticketKey($context), new AccessTicket('OLD', 'S', $expiry));
    fakeLogin('NEW');
    expect($provider->for($context, 'wscpe')->token)->toBe('NEW')
        ->and($store->get(ticketKey($context))->token)->toBe('NEW');
    Http::assertSentCount(1);

    Carbon::setTestNow($expiry->subMinutes(6));
    [$provider, $store, $context] = ticketProvider();
    $store->put(ticketKey($context), new AccessTicket('OLD', 'S', $expiry));
    Http::fake();
    expect($provider->for($context, 'wscpe')->token)->toBe('OLD');
    Http::assertNothingSent();
});

it('compares expiry across timezones', function () {
    $expiresAt = CarbonImmutable::parse('2026-10-08T12:00:00-03:00');

    Carbon::setTestNow('2026-10-08T14:54:59Z');
    [$provider, $store, $context] = ticketProvider();
    $store->put(ticketKey($context), new AccessTicket('OLD', 'S', $expiresAt));
    Http::fake();
    expect($provider->for($context, 'wscpe')->token)->toBe('OLD');
    Http::assertNothingSent();

    Carbon::setTestNow('2026-10-08T14:55:01Z');
    fakeLogin('NEW', '2026-10-09T10:00:00-03:00');
    expect($provider->for($context, 'wscpe')->token)->toBe('NEW');
});

it('rereads inside the lock', function () {
    Carbon::setTestNow('2026-10-08T12:00:00Z');
    [$provider, $store, $context] = ticketProvider();
    $provider->beforeLogin(fn () => $store->put(ticketKey($context), new AccessTicket('OTHER', 'S', CarbonImmutable::parse('2026-10-08T18:00:00Z'))));
    Http::fake();

    expect($provider->for($context, 'wscpe')->token)->toBe('OTHER');
    Http::assertNothingSent();
});

it('ticket already held without a stored one is unavailable', function () {
    [$provider, , $context] = ticketProvider();
    Http::fake(['*' => Http::response(WsaaResponses::alreadyHasTicket(), 500)]);

    $outcome = $provider->for($context, 'wscpe');

    expect($outcome->isUnavailable())->toBeTrue()
        ->and($outcome->reasonCodes())->toBe(['ticket_already_held'])
        ->and($outcome->reasons[0]->message)->toContain('hasta 12 horas');
});

it('other WSAA rejections pass through', function () {
    [$provider, , $context] = ticketProvider();
    Http::fake(['*' => Http::response(str_replace('El CEE ya posee un TA valido para el acceso al WSN solicitado', 'Certificado expirado', WsaaResponses::alreadyHasTicket()), 500)]);

    $outcome = $provider->for($context, 'wscpe');

    expect($outcome->isRejected())->toBeTrue()->and($outcome->reasonCodes())->toBe(['soap_client_fault']);
});

it('lock timeout is unavailable', function () {
    [$provider, , $context] = ticketProvider(lockSeconds: 1);
    $held = Cache::lock('fiscal-adapter:arca:wsaa:'.ticketKey($context)->toString(), 30);
    expect($held->get())->toBeTrue();
    Http::fake();

    $outcome = $provider->for($context, 'wscpe');
    $held->release();

    expect($outcome->isUnavailable())->toBeTrue()->and($outcome->reasonCodes())->toBe(['lock_timeout']);
    Http::assertNothingSent();
});

it('keys tickets by certificate, service and environment', function () {
    Carbon::setTestNow('2026-10-08T12:00:00Z');
    [$provider, , $context] = ticketProvider();
    $other = new CallContext(ticketCredential(), '30712345671', Environment::Homologation);
    $production = new CallContext($context->credential, '30712345671', Environment::Production);
    fakeLogin();

    $provider->for($context, 'wscpe');
    $provider->for($context, 'wscpe');
    Http::assertSentCount(1);
    $provider->for($other, 'wscpe');
    $provider->for($production, 'wscpe');
    $provider->for($context, 'wsfe');
    Http::assertSentCount(4);
});

it('forgets a ticket', function () {
    Carbon::setTestNow('2026-10-08T12:00:00Z');
    [$provider, , $context] = ticketProvider();
    fakeLogin();

    $provider->for($context, 'wscpe');
    $provider->forget($context, 'wscpe');
    $provider->for($context, 'wscpe');

    Http::assertSentCount(2);
});

it('builds the ticket key string', function () {
    expect((new TicketKey('abc', 'wscpe', Environment::Production))->toString())->toBe('abc:wscpe:production');
});

it('missing ticket store is a core-agnostic exception', function () {
    expect(new MissingTicketStore)->toBeInstanceOf(FiscalAdapterException::class);
});
