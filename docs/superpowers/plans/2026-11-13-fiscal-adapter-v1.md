# Fiscal Adapter v1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Librería PHP `mnonm/fiscal-adapter` con un núcleo independiente del país y un adaptador ARCA que emite, anula, consulta y reconcilia cartas de porte (WSCPE), más catálogos y último número de orden.

**Architecture:** Paquete Laravel, como Hermes. Núcleo (`Mnonm\FiscalAdapter`) con `FiscalAuthority`, `Outcome`, `Credential`, `CallContext`, `CallObserver`, `FiscalManager`, sin palabras de ningún país. Adaptador ARCA (`Mnonm\FiscalAdapter\Arca`) con transporte SOAP armado a mano sobre el cliente `Http` de Laravel, WSAA con ticket en un `TicketStore` provisto por quien la usa (renovación bajo `Cache::lock`), y WSCPE. Service provider + fachada `Fiscal` + `Fiscal::fake()`. Se porta de la rama `origin/AR-2500-be-integracion-arca` del repo Artisano Back (`/home/pato/Develop/Artisano/Back/web`), quitando Eloquent y los modelos de Artisano.

**Tech Stack:** PHP 8.3, ext-openssl, ext-simplexml, `illuminate/*` ^13 (support, http, cache, events, contracts), Carbon, Pest 4 sobre Orchestra Testbench 11, Pint, PHPStan 2.

**Spec:** `docs/genesis/2026-11-13-fiscal-adapter-genesis-design.md` (y `docs/genesis/PROJECT_GENESIS.md`).

## Global Constraints

- Repo local `/home/pato/Develop/Fiscal-Adapter`. **No crear el repo en GitHub ni pushear**: eso lo autoriza el usuario aparte.
- Commits sólo a nombre del usuario: **sin `Co-Authored-By`, sin "Generated with Claude Code"** (PROJECT_GENESIS §4).
- Código, identificadores, nombres de tests y mensajes de commit en **inglés**. Mensajes de error que ve una persona, en español (como Hermes).
- `"php": "^8.3"`. Requiere `ext-openssl` y `ext-simplexml`. **Prohibido `ext-soap`**.
- `illuminate/support`, `illuminate/http`, `illuminate/cache`, `illuminate/events`, `illuminate/contracts` `^13.0` en `require` (decisión del usuario: paquete Laravel como el resto de los proyectos). Usar lo de Laravel: `Http` (+ `Http::fake()`), `Cache::lock`, `config()`, Carbon / `Carbon::setTestNow()`, eventos.
- `"license": "proprietary"`, sin archivo `LICENSE`.
- La librería **no toca bases de datos** ni conoce modelos de Artisano.
- Nada del núcleo (`src/` fuera de `src/Arca/`) nombra CUIT, CTG, ticket, WSAA ni SOAP.
- Ningún secreto (clave privada, passphrase, token, sign, CMS `in0`) en `CallRecord`, mensajes de excepción, `var_dump`/`print_r` o el repo.
- Fechas con ARCA: sin offset, en `America/Argentina/Buenos_Aires`.
- Repo público: CUITs de ejemplo inventados con dígito verificador válido. Los de este plan: `30712345671` (empresa), `20123456786` (chofer), `30798765430` (transportista), `30500000003` (destino), `20301234563` (otro chofer). **No copiar** `docs/runbooks/` ni specs de la rama de #524.
- **No hay `php` en el host.** Todo comando PHP corre con la imagen de Task 1:
  `docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer -v "$PWD":/app -w /app fiscal-adapter-test <comando>`
  (abajo, `$RUN <comando>`).

## Review Focus

1. **Clave privada con passphrase**, o passphrase equivocada → `Credential` falla con `InvalidCredential` y el mensaje no contiene la passphrase ni el PEM. Test en Task 2.
2. **Patentes en minúscula o con espacios** (`" ab123cd"`) → se mandan normalizadas (`AB123CD`) y la identidad de la reconciliación compara normalizado y sin importar el orden. Tests en Task 7 y Task 9.
3. **`departureAt` en UTC** (la app de quien la usa corre en UTC) → se manda en hora argentina (`2026-10-08T13:00:00Z` → `2026-10-08T10:00:00`). Test en Task 7.
4. **Vencimiento del ticket con offset `-03:00` y la app en UTC** → se compara bien contra `now()`. Test en Task 6.
5. **Observaciones de anulación con `&`, `<`** → se escapan en el sobre y no rompen el XML. Test en Task 7.

---

## File Structure

```
composer.json  Dockerfile.test  phpunit.xml  pint.json  phpstan.neon  .gitignore  CLAUDE.md  README.md
.github/workflows/ci.yml
src/
  Environment.php  Credential.php  CallContext.php  Outcome.php  OutcomeStatus.php  Reason.php
  CallRecord.php  CallObserver.php  EventCallObserver.php  FiscalCallRecorded.php  FiscalManager.php
  FiscalAdapterServiceProvider.php  Facades/Fiscal.php
  Contracts/FiscalAuthority.php  Contracts/Issuable.php  Contracts/Cancellation.php  Contracts/Query.php
  Exceptions/FiscalAdapterException.php  InvalidCredential.php  InvalidDocument.php  UnsupportedDocument.php
             MissingEndpoint.php  UnknownAuthority.php  MissingTicketStore.php
  Testing/FakeAuthority.php
  Arca/
    Cuit.php  ArcaEndpoints.php  ArcaAuthority.php
    Soap/SoapCall.php  SoapResult.php  SoapTransport.php  Redactor.php
    Wsaa/AccessTicket.php  TicketKey.php  TicketStore.php  InMemoryTicketStore.php
         WsaaClient.php  AccessTicketProvider.php
    Wscpe/WscpeXml.php  ArcaWaybill.php  ArcaWaybillAnnulment.php  AnnulmentReason.php
          ArcaWaybillQuery.php  WaybillResult.php
config/fiscal-adapter.php
tests/
  Pest.php  TestCase.php  ArchTest.php
  Support/TestCertificate.php  WsaaResponses.php  WscpeResponses.php  Fixtures.php
  Unit/...  Feature/...  Homologation/...
```

---

### Task 1: Esqueleto del repo, imagen de test y guardas de arquitectura

**Files:**
- Create: `composer.json`, `Dockerfile.test`, `phpunit.xml`, `pint.json`, `phpstan.neon`, `.gitignore`, `CLAUDE.md`, `README.md`, `.github/workflows/ci.yml`, `tests/Pest.php`, `tests/ArchTest.php`

**Interfaces:**
- Produces: namespace `Mnonm\FiscalAdapter\` → `src/`, `Mnonm\FiscalAdapter\Tests\` → `tests/`; imagen `fiscal-adapter-test`; `composer test` = pest + pint --test + phpstan; `tests/TestCase.php` extiende `Orchestra\Testbench\TestCase` y `tests/Pest.php` hace `uses(TestCase::class)->in('Unit', 'Feature', 'Homologation')`.

- [ ] **Step 1: `git init -b develop`** en `/home/pato/Develop/Fiscal-Adapter` (ya existe `docs/`).
- [ ] **Step 2: `Dockerfile.test`**: `FROM php:8.3-cli-bookworm`, instala `git unzip`, `COPY --from=composer:2 /usr/bin/composer /usr/bin/composer`. Comentario de cabecera con el comando `$RUN` de Global Constraints (como el de Hermes). `docker build -f Dockerfile.test -t fiscal-adapter-test .` → termina OK.
- [ ] **Step 3: `composer.json`**: name `mnonm/fiscal-adapter`, type `library`, license `proprietary`; require `php ^8.3`, `ext-openssl`, `ext-simplexml`, `illuminate/support ^13.0`, `illuminate/http ^13.0`, `illuminate/cache ^13.0`, `illuminate/events ^13.0`, `illuminate/contracts ^13.0`; require-dev `pestphp/pest ^4.0`, `orchestra/testbench ^11.2`, `laravel/pint ^1.32`, `phpstan/phpstan ^2.2`; `extra.laravel.providers` = `Mnonm\FiscalAdapter\FiscalAdapterServiceProvider`, `extra.laravel.aliases.Fiscal` = `Mnonm\FiscalAdapter\Facades\Fiscal`; script `test`. `$RUN composer install` → OK.
- [ ] **Step 4: Config de herramientas**: `phpunit.xml` con testsuites `Unit` (`tests/Unit`), `Feature` (`tests/Feature`), `Homologation` (`tests/Homologation`) y `<groups><exclude><group>homologation</group></exclude></groups>`; `pint.json` preset laravel; `phpstan.neon` level 7 sobre `src`; `.gitignore` con `/vendor`, `.env*`, `*.key`, `*.pem`, `*.crt`, `*.p12`, `/credentials`, `.phpunit.cache`.
- [ ] **Step 5: Test de arquitectura `tests/ArchTest.php`**

```php
arch('core does not know ARCA')->expect([
    'Mnonm\FiscalAdapter\Contracts', 'Mnonm\FiscalAdapter\Exceptions', 'Mnonm\FiscalAdapter\Testing',
])->not->toUse('Mnonm\FiscalAdapter\Arca');
arch('no ext-soap')->expect('Mnonm\FiscalAdapter')->not->toUse(['SoapClient', 'SoapServer']);
arch('no database')->expect('Mnonm\FiscalAdapter')->not->toUse(['PDO', 'Illuminate\Database', 'Illuminate\Support\Facades\DB', 'Illuminate\Support\Facades\Schema']);
```

- [ ] **Step 6: `$RUN vendor/bin/pest`** → PASS (3 tests; los arch pasan sobre `src/` vacío con un `src/.gitkeep`).
- [ ] **Step 7: `CLAUDE.md`** del repo: qué es (2 líneas), link al génesis como autoridad, convenciones (Global Constraints resumidas), comando `$RUN`, "antes de cerrar: `composer test` en verde", la frontera (núcleo sin ARCA, nada de base de datos). `README.md`: instalación por `"type": "vcs"` y un ejemplo de uso (se completa en Task 11).
- [ ] **Step 8: CI** `.github/workflows/ci.yml`: en push y PR; matrix PHP `8.3`, `8.4` con `shivammathur/setup-php` (extensions `openssl, simplexml`), `composer install`, `vendor/bin/pest`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`. No corre el grupo `homologation`.
- [ ] **Step 9: Commit** `chore: scaffold fiscal-adapter library` (incluye `docs/`).

---

### Task 2: Núcleo — valores, contratos y excepciones

**Files:**
- Create: `src/Environment.php`, `src/Credential.php`, `src/CallContext.php`, `src/Outcome.php`, `src/OutcomeStatus.php`, `src/Reason.php`, `src/CallRecord.php`, `src/CallObserver.php`, `src/EventCallObserver.php`, `src/FiscalCallRecorded.php`, `src/Contracts/*.php`, `src/Exceptions/*.php`, `tests/Support/TestCertificate.php`
- Test: `tests/Unit/CredentialTest.php`, `tests/Unit/OutcomeTest.php`

**Interfaces:**
- Produces:
  - `enum Environment: string { case Homologation = 'homologation'; case Production = 'production'; }`
  - `enum OutcomeStatus: string { Authorized, Rejected, Pending, Unavailable, Unknown }` (values en snake: `'authorized'`…)
  - `final readonly class Reason { __construct(public string $code, public string $message) }`
  - `final readonly class Outcome` con `public OutcomeStatus $status; public ?string $authorityId; public array $data /* array<string,mixed> */; public array $reasons /* list<Reason> */`; factories `authorized(string $authorityId, array $data = [], array $reasons = [])`, `rejected(array $reasons)`, `pending(?string $authorityId = null, array $data = [])`, `unavailable(Reason $reason)`, `unknown(Reason $reason)`; `isAuthorized()`, `isRejected()`, `isPending()`, `isUnavailable()`, `isUnknown()`; `reasonCodes(): list<string>`.
  - `final class Credential`: `static fromFiles(string $certificatePath, string $privateKeyPath, ?string $passphrase = null): self`, `static fromPem(string $certificatePem, string $privateKeyPem, ?string $passphrase = null): self`, `fingerprint(): string` (sha256 hex del certificado DER), `certificatePem(): string`, `privateKeyPem(): string`, `passphrase(): ?string` (estas tres `@internal`), `__debugInfo(): array{fingerprint: string}`, `__serialize()` lanza `\LogicException`.
  - `final readonly class CallContext { __construct(public Credential $credential, public string $representedTaxId, public Environment $environment) }`
  - `final readonly class CallRecord { authority, service, method: string; environment: Environment; url: string; representedTaxId: ?string; request: string; response: ?string; httpStatus: ?int; durationMs: int; error: ?string; occurredAt: \Carbon\CarbonImmutable }`
  - `interface CallObserver { public function record(CallRecord $record): void; }` + `EventCallObserver` (default: despacha `FiscalCallRecorded { public CallRecord $record }` con `event()`)
  - `interface Issuable {}`, `interface Cancellation {}`, `interface Query {}` en `Contracts`
  - `interface FiscalAuthority { name(): string; supports(object $document): bool; issue(CallContext $context, Issuable $document): Outcome; cancel(CallContext $context, Cancellation $cancellation): Outcome; query(CallContext $context, Query $query): Outcome; reconcile(CallContext $context, Issuable|Cancellation $document): Outcome; }` — PHPDoc de `reconcile`: "nunca `Unknown` por no encontrar: si el organismo confirma que no está, `Unavailable`".
  - Excepciones: `FiscalAdapterException extends \RuntimeException`; hijas `InvalidCredential`, `InvalidDocument`, `UnsupportedDocument`, `MissingEndpoint`, `UnknownAuthority`, `MissingTicketStore`.
  - Tests: `TestCertificate::create(string $dir, ?string $passphrase = null): array{certificate: string, private_key: string}` (portado de `tests/Support/ArcaTestCertificate.php`, serialNumber `CUIT 30712345671`, devuelve rutas absolutas). El reloj de los tests es `Carbon::setTestNow()`.

- [ ] **Step 1: Tests de `Credential`**

```php
it('loads from files and from PEM with the same fingerprint')            // expect(fromFiles(...)->fingerprint())->toBe(fromPem(...)->fingerprint())->toMatch('/^[0-9a-f]{64}$/')
it('accepts a key protected with a passphrase')                          // TestCertificate::create($dir, 'secret') + fromFiles(..., 'secret') no lanza
it('rejects a wrong passphrase without leaking it', function () {
    // fromFiles($cert, $key, 'wrong') → toThrow(InvalidCredential::class); el mensaje not->toContain('wrong') ni 'PRIVATE KEY'
});
it('rejects a key that does not match the certificate')                  // dos TestCertificate cruzados → InvalidCredential (openssl_x509_check_private_key)
it('rejects unreadable files')                                           // ruta inexistente → InvalidCredential, mensaje con la ruta del cert, no del contenido
it('never exposes the key when dumped or serialized', function () {
    expect(print_r($credential, true))->not->toContain('PRIVATE KEY');
    expect(fn () => serialize($credential))->toThrow(LogicException::class);
});
```

- [ ] **Step 2: Tests de `Outcome` y del observer**: `it('dispatches FiscalCallRecorded')` con `Event::fake()`; cada factory deja el `status` correcto; `authorized('101234567890', ['state' => 'AC'], [new Reason('550', 'x')])` conserva las tres cosas; `rejected([])` lanza `\InvalidArgumentException` (un rechazo sin motivo no existe); `reasonCodes()` devuelve los códigos en orden.
- [ ] **Step 3: `$RUN vendor/bin/pest tests/Unit`** → FAIL (clases inexistentes).
- [ ] **Step 4: Implementar** lo de Interfaces. `Credential` valida al construir (lectura, `openssl_x509_read`, `openssl_pkey_get_private($pem, $passphrase)`, `openssl_x509_check_private_key`); los mensajes nombran sólo la ruta o "certificado"/"clave".
- [ ] **Step 5: `$RUN vendor/bin/pest`** → PASS. `$RUN vendor/bin/phpstan analyse` → sin errores.
- [ ] **Step 6: Commit** `feat: add country-agnostic core values and contracts`

---

### Task 3: CUIT

**Files:**
- Create: `src/Arca/Cuit.php`
- Test: `tests/Unit/Arca/CuitTest.php`

**Interfaces:**
- Consumes: `InvalidDocument` (Task 2).
- Produces: `final readonly class Cuit { public string $value; static from(string $value): self /* lanza InvalidDocument */; static tryFrom(?string $value): ?self; __toString(): string }`. Regla portada de `app/Support/Cuit.php` (#524): 11 dígitos tras quitar no-dígitos, prefijos `20 23 24 25 26 27 30 33 34`, pesos `5 4 3 2 7 6 5 4 3 2`, DV `11→0`, `10→9`.

- [ ] **Step 1: Test**

```php
it('normalizes dashes and spaces', fn () => expect(Cuit::from('30-71234567-1')->value)->toBe('30712345671'));
it('accepts the fixture CUITs', fn (string $c) => expect(Cuit::from($c)->value)->toBe($c))
    ->with(['30712345671', '20123456786', '30798765430', '30500000003', '20301234563']);
it('rejects a wrong check digit', fn () => expect(fn () => Cuit::from('30712345678'))->toThrow(InvalidDocument::class));
it('rejects an unknown prefix', fn () => expect(Cuit::tryFrom('12123456786'))->toBeNull());
it('rejects wrong length and null', fn () => expect(Cuit::tryFrom('3071234567'))->toBeNull()->and(Cuit::tryFrom(null))->toBeNull());
```

- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(arca): add CUIT value object`

---

### Task 4: Transporte SOAP, redacción y clasificación

**Files:**
- Create: `src/Arca/Soap/SoapCall.php`, `SoapResult.php`, `SoapTransport.php`, `Redactor.php`, `src/Arca/ArcaEndpoints.php`
- Test: `tests/Unit/Arca/SoapTransportTest.php`, `tests/Unit/Arca/RedactorTest.php`, `tests/Unit/Arca/ArcaEndpointsTest.php`

**Interfaces:**
- Consumes: `CallObserver`, `CallRecord`, `Environment`, `Outcome`, `Reason`, `MissingEndpoint`. Config `fiscal-adapter.arca.timeout` (30) y `fiscal-adapter.arca.endpoints` (overrides) en `config/fiscal-adapter.php`, que se crea acá; el `TestCase` la carga con `config([...])` hasta que exista el provider (Task 11).
- Produces:
  - `final readonly class SoapCall { __construct(public string $service, public string $method, public string $url, public string $soapAction, public string $envelope, public Environment $environment, public ?string $representedTaxId = null) }`
  - `final readonly class SoapResult { public ?string $body; public ?Outcome $failure; static ok(string $body): self; static failed(Outcome $failure): self; succeeded(): bool }`
  - `final class SoapTransport { __construct(CallObserver $observer, int $timeoutSeconds = 30); call(SoapCall $call): SoapResult }` — `Http::timeout()->withHeaders(['SOAPAction' => …])->withBody($envelope, 'text/xml; charset=utf-8')->post($url)`; la clasificación lee el mensaje de `Illuminate\Http\Client\ConnectionException`.
  - `final class Redactor { static redact(string $xml): string }` — regex portadas tal cual de `SoapTransport::redact()` de #524 (`token|sign|in0`, con o sin prefijo, cualquier mayúscula, y la forma escapada `&lt;token&gt;`/`&lt;sign&gt;`).
  - `final class ArcaEndpoints { __construct(array $overrides = []) /* array<'homologation'|'production', array<string,string>> */; url(string $service, Environment $environment): string /* lanza MissingEndpoint */ }`. Defaults:
    - homologation: `wsaa` `https://wsaahomo.afip.gov.ar/ws/services/LoginCms`, `wscpe` `https://cpea-ws-qaext.afip.gob.ar/wscpe/services/soap`
    - production: `wsaa` `https://wsaa.afip.gov.ar/ws/services/LoginCms`, `wscpe` `https://cpea-ws.afip.gob.ar/wscpe/services/soap`

- [ ] **Step 1: Tests de clasificación** con `Http::fake()`. Las fallas de conexión se simulan con `Http::fake(fn () => throw new ConnectionException('cURL error N: …'))`. Cada caso: `$result = $transport->call($call)` y la expectativa sobre `$result->failure`:

| Caso | Mock | Esperado |
| --- | --- | --- |
| `it('returns the body on 200')` | `Http::response('<ok/>', 200)` | `succeeded()` y `body === '<ok/>'` |
| `it('connection refused is unavailable')` | `ConnectionException('cURL error 7: Failed to connect')` | `isUnavailable()`, código `connection_failed` |
| `it('cut after sending is unknown')` dataset `18, 28, 52, 55, 56, 92` | `ConnectionException("cURL error {$n}: x")` | `isUnknown()`, código `response_cut` |
| `it('non-2xx without fault is unknown')` | `Http::response('Bad gateway', 502)` | `isUnknown()`, código `http_error` |
| `it('client fault is a rejection')` dataset `soap:Client`, `soap:Sender`, `Client` sin prefijo | `Http::response(fault($code, 'Token expirado'), 500)` | `isRejected()`, código `soap_client_fault`, mensaje `Token expirado` |
| `it('server fault is unavailable')` | `Http::response(fault('soap:Server', 'x'), 500)` | `isUnavailable()`, código `server_fault` |

  Además: `it('sends SOAPAction and text/xml')` con `Http::assertSent()` (header `SOAPAction`, `Content-Type: text/xml; charset=utf-8`).
- [ ] **Step 2: Tests del observer**: `it('records every call, redacted')` — envelope con `<token>TOK</token><sign>SIG</sign>` y respuesta con `&lt;token&gt;T2&lt;/token&gt;` → el `CallRecord` recibido tiene `request`/`response` sin `TOK`, `SIG`, `T2`, con `[REDACTED]`; `httpStatus`, `durationMs >= 0`, `service`, `method`, `environment`, `representedTaxId` llenos; en fallos `error` lleno y `response` null si no hubo cuerpo. `it('failure messages carry no secrets')` — ningún `Reason::message` contiene `TOK`/`SIG`.
- [ ] **Step 3: Tests de `Redactor`** (portar los casos de `tests/Feature/Arca/SoapTransportTest.php` de #524 que prueban `redact`) y de `ArcaEndpoints` (defaults, override, `MissingEndpoint` para servicio sin URL con el nombre del servicio y el ambiente en el mensaje).
- [ ] **Step 4: Run** → FAIL. **Step 5: Implement** (portar `SoapTransport` de #524 casi tal cual, con `$observer->record()` en vez de `ArcaCallLog::create`: `faultString`, `isClientFault`, `isCutAfterSending` con `/cURL error (18|28|52|55|56|92)\b/`). **Step 6: Run** → PASS.
- [ ] **Step 7: Commit** `feat(arca): add SOAP transport with redaction and outcome classification`

---

### Task 5: WSAA — TRA, firma y login

**Files:**
- Create: `src/Arca/Wsaa/AccessTicket.php`, `src/Arca/Wsaa/WsaaClient.php`, `tests/Support/WsaaResponses.php`
- Test: `tests/Unit/Arca/WsaaClientTest.php`

**Interfaces:**
- Consumes: `SoapTransport`, `SoapCall`, `ArcaEndpoints`, `Credential`, `CallContext`, `Outcome`.
- Produces:
  - `final readonly class AccessTicket { __construct(public string $token, public string $sign, public \Carbon\CarbonImmutable $expiresAt); isUsable(int $marginSeconds = 300): bool /* contra now() */; __debugInfo(): array{expiresAt: string} }`
  - `final class WsaaClient { __construct(SoapTransport $transport, ArcaEndpoints $endpoints); buildTra(string $service): string /* usa now() */; signTra(string $tra, Credential $credential): string; login(CallContext $context, string $service): AccessTicket|Outcome }`
  - `WsaaResponses::loginCms(string $token, string $sign, string $expiration): string` (portado de `tests/Support/ArcaWsaaResponses.php`, destination `CUIT 30712345671`), `WsaaResponses::alreadyHasTicket(): string` (Fault `soap:Client` con faultstring `El CEE ya posee un TA valido para el acceso al WSN solicitado`).

- [ ] **Step 1: Tests**
  - `it('builds the TRA with a -60s/+10min window')`: `Carbon::setTestNow('2026-10-08T13:00:00Z')` → `generationTime` = 12:59:00, `expirationTime` = 13:10:00 (mismo instante, cualquier offset), `service` = `wscpe`, `uniqueId` = timestamp.
  - `it('signs the TRA as non-detached binary CMS')`: `base64_decode(signTra(...))` verificable con `openssl_pkcs7_verify` tras envolver en PEM, y el contenido verificado es el TRA.
  - `it('logs in and parses the escaped ticket')`: `Http::fake()` con `loginCms('TOK','SIG','2026-10-08T22:00:00-03:00')` → `AccessTicket` con `token TOK`, `sign SIG`, `expiresAt` == `2026-10-09T01:00:00Z`.
  - `it('posts loginCms to the environment URL')`: el request va a la URL de homologación con el CMS dentro de `<wsaa:in0>` y el observer lo registra con `in0` tapado.
  - `it('returns the transport failure as outcome')`: Fault `soap:Client` → `Outcome` rechazado `soap_client_fault`.
  - `it('missing loginCmsReturn is unknown')` → `Outcome::unknown`, código `malformed_response`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** portando `WsaaClient` de #524 (envelope `loginCms` con namespace `http://wsaa.view.sua.dvadac.desein.afip.gov`, SOAPAction `""`; `openssl_pkcs7_sign(..., PKCS7_BINARY)` con archivos temporales que se borran en `finally`; clave por PEM + passphrase en vez de `file://`). **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(arca): add WSAA client`

---

### Task 6: Ticket durable y renovación con lock

**Files:**
- Create: `src/Arca/Wsaa/TicketKey.php`, `TicketStore.php`, `InMemoryTicketStore.php`, `AccessTicketProvider.php`
- Test: `tests/Unit/Arca/AccessTicketProviderTest.php`

**Interfaces:**
- Consumes: `WsaaClient`, `AccessTicket`, `CallContext`.
- Produces:
  - `final readonly class TicketKey { __construct(public string $credentialFingerprint, public string $service, public Environment $environment); toString(): string /* "{fingerprint}:{service}:{environment}" */ }`
  - `interface TicketStore { get(TicketKey $key): ?AccessTicket /* null también si no se puede leer */; put(TicketKey $key, AccessTicket $ticket): void; forget(TicketKey $key): void }` — PHPDoc: "debe ser durable (base, archivo); un cache que se vacía deja la credencial sin autenticar hasta 12 h".
  - `InMemoryTicketStore` (para tests).
  - `final class AccessTicketProvider { __construct(WsaaClient $wsaa, TicketStore $store, int $lockSeconds = 90); for(CallContext $context, string $service): AccessTicket|Outcome; forget(CallContext $context, string $service): void; beforeLogin(\Closure $callback): void /* hook de test */ }` — renovación dentro de `Cache::lock('fiscal-adapter:arca:wsaa:'.$key->toString(), $lockSeconds)->block($lockSeconds, …)`; `LockTimeoutException` → `Outcome::unavailable`, código `lock_timeout`.

- [ ] **Step 1: Tests** (store en memoria, cache `array` de Testbench, `Http::fake()` para WSAA)
  - `it('reuses a stored ticket that is still usable')` → ningún request HTTP.
  - `it('renews five minutes before expiry')`: ticket que vence `T`, `setTestNow(T-4min)` → pide uno nuevo y lo guarda; clock `T-6min` → no.
  - `it('compares expiry across timezones')` (Review Focus 4): app en UTC, ticket con `expiresAt` `2026-10-08T12:00:00-03:00`; `setTestNow('2026-10-08T14:54:59Z')` → reusa; `setTestNow('2026-10-08T14:55:01Z')` → renueva.
  - `it('rereads inside the lock')`: `beforeLogin` guarda un ticket válido en el store → `for()` lo devuelve y WSAA no recibe request.
  - `it('ticket already held without a stored one is unavailable')`: WSAA responde `alreadyHasTicket()` → `Outcome::unavailable`, código `ticket_already_held`, mensaje menciona "hasta 12 horas".
  - `it('lock timeout is unavailable')`: tomar antes el lock con `Cache::lock(...)->get()` y `lockSeconds` = 1 → `Outcome::unavailable`, código `lock_timeout`.
  - `it('keys tickets by certificate, service and environment')`: dos credenciales distintas o dos ambientes → dos logins.
  - `it('forgets a ticket')`: `forget()` y el próximo `for()` hace login.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** portando `AccessTicketProvider` de #524 (Eloquent → `TicketStore`; `Cache::lock` se mantiene). PHPDoc de clase: no llamar dentro de una transacción REPEATABLE READ de quien la usa. **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(arca): add durable access ticket provider`

---

### Task 7: Documentos WSCPE y sobres

**Files:**
- Create: `src/Arca/Wscpe/WscpeXml.php`, `ArcaWaybill.php`, `ArcaWaybillAnnulment.php`, `AnnulmentReason.php`, `ArcaWaybillQuery.php`, `WaybillResult.php`, `tests/Support/Fixtures.php`
- Test: `tests/Unit/Arca/Wscpe/ArcaWaybillTest.php`, `tests/Unit/Arca/Wscpe/EnvelopeTest.php`

**Interfaces:**
- Consumes: `Cuit`, `AccessTicket`, `Issuable`, `Cancellation`, `Query`, `InvalidDocument`.
- Produces:
  - `final class WscpeXml { const NS = 'https://serviciosjava.afip.gob.ar/wscpe/'; const TIMEZONE = 'America/Argentina/Buenos_Aires'; static soapAction(string $method): string; static envelope(string $element, AccessTicket $ticket, Cuit $represented, string $body = ''): string; static text(string|int $value): string; static dateTime(\DateTimeInterface $value): string /* Y-m-d\TH:i:s en hora AR */ }` (portado de `WscpeSoap`).
  - `final readonly class ArcaWaybill implements Issuable` — constructor con argumentos nombrados: `int $branch, int $orderNumber, int $originProvinceCode, int $originLocalityCode, int $grainCode, string $harvest, int $grossKg, int $tareKg, Cuit $destinationCuit, int $destinationProvinceCode, int $destinationLocalityCode, ?int $destinationPlantNumber, Cuit $recipientCuit, Cuit $carrierCuit, array $plates /* list<string> */, \DateTimeImmutable $departureAt, int $km, Cuit $driverCuit, Cuit $freightPayerCuit, bool $declaredNotFumigated, int $cpeType = self::AUTOMOTOR`; `const AUTOMOTOR = 74`; `const MAX_GROSS_KG = 88000`; `envelope(AccessTicket $ticket, Cuit $represented): string`; `normalizedPlates(): list<string>`.
  - `enum AnnulmentReason: int { case Accident = 1; case CargoLoss = 2; case Other = 3; }`
  - `final readonly class ArcaWaybillAnnulment implements Cancellation { __construct(int $branch, int $orderNumber, AnnulmentReason $reason, ?string $observations = null, ?string $ctg = null, int $cpeType = ArcaWaybill::AUTOMOTOR); envelope(AccessTicket, Cuit): string }`
  - `final readonly class ArcaWaybillQuery implements Query { static byCtg(string $ctg): self; static byOrderNumber(int $branch, int $orderNumber, int $cpeType = ArcaWaybill::AUTOMOTOR): self; envelope(AccessTicket, Cuit): string }`
  - `Fixtures::waybill(array $overrides = []): ArcaWaybill` con los valores del test de abajo.

- [ ] **Step 1: Test del sobre de emisión** (valores del test de #524 con CUITs válidos):

```php
it('builds the authorize envelope in schema order', function () {
    $waybill = Fixtures::waybill(); // branch 1, order 419, origen 9/1234, grano 23, cosecha '2526', bruto 45000, tara 15000,
    // destino 30500000003 12/5678 planta 12345, destinatario 30500000003, transportista 30798765430,
    // patentes ['AB123CD','AC456DE'], partida 2026-10-08 10:00 hora AR, km 120, chofer 20123456786,
    // pagador 30712345671, declaredNotFumigated true
    $xml = $waybill->envelope(new AccessTicket('TOK', 'SIG', now()->toImmutable()->addHours(6)), Cuit::from('30712345671'));

    expect($xml)->toContain('<wsc:AutorizarCPEAutomotorReq><auth><token>TOK</token><sign>SIG</sign><cuitRepresentada>30712345671</cuitRepresentada></auth>')
        ->toContain('<solicitud><cabecera><tipoCP>74</tipoCP><cuitSolicitante>30712345671</cuitSolicitante><sucursal>1</sucursal><nroOrden>419</nroOrden></cabecera>'
            .'<origen><productor><codProvincia>9</codProvincia><codLocalidad>1234</codLocalidad></productor></origen>'
            .'<correspondeRetiroProductor>false</correspondeRetiroProductor><esSolicitanteCampo>true</esSolicitanteCampo>'
            .'<datosCarga><codGrano>23</codGrano><cosecha>2526</cosecha><pesoBruto>45000</pesoBruto><pesoTara>15000</pesoTara></datosCarga>'
            .'<destino><cuit>30500000003</cuit><esDestinoCampo>false</esDestinoCampo><codProvincia>12</codProvincia><codLocalidad>5678</codLocalidad><planta>12345</planta></destino>'
            .'<destinatario><cuit>30500000003</cuit></destinatario>'
            .'<transporte><cuitTransportista>30798765430</cuitTransportista><dominio>AB123CD</dominio><dominio>AC456DE</dominio>'
            .'<fechaHoraPartida>2026-10-08T10:00:00</fechaHoraPartida><kmRecorrer>120</kmRecorrer><cuitChofer>20123456786</cuitChofer>'
            .'<cuitPagadorFlete>30712345671</cuitPagadorFlete><mercaderiaFumigada>true</mercaderiaFumigada></transporte></solicitud>');
});
```

- [ ] **Step 2: Más tests de sobres**
  - `it('without plant goes as field destination')` → `<esDestinoCampo>true</esDestinoCampo>` y sin `<planta>`.
  - `it('sends the not-fumigated declaration as is')` dataset `[true,'true'],[false,'false']`.
  - `it('sends departure in Argentine time')` (Review Focus 3): `departureAt` `2026-10-08T13:00:00Z` → `<fechaHoraPartida>2026-10-08T10:00:00</fechaHoraPartida>`.
  - `it('normalizes plates')` (Review Focus 2): `[' ab123cd', 'AC 456 DE']` → `<dominio>AB123CD</dominio><dominio>AC456DE</dominio>`.
  - Anulación: `it('builds the annul envelope')` → `<wsc:AnularCPEReq>…<solicitud><cartaPorte><tipoCPE>74</tipoCPE><sucursal>1</sucursal><nroOrden>419</nroOrden></cartaPorte><anulacionMotivo>3</anulacionMotivo><anulacionObservaciones>Carga mal</anulacionObservaciones></solicitud>`; sin observaciones no hay elemento; `it('escapes observations')` (Review Focus 5): `'A & B <x>'` → `A &amp; B &lt;x&gt;` y el sobre parsea con `simplexml_load_string`.
  - Consulta: `byCtg('101234567890')` → `<wsc:ConsultarCPEAutomotorReq>…<solicitud><nroCTG>101234567890</nroCTG></solicitud>`; `byOrderNumber(1, 419)` → `<solicitud><cartaPorte><tipoCPE>74</tipoCPE><sucursal>1</sucursal><nroOrden>419</nroOrden></cartaPorte></solicitud>`.
- [ ] **Step 3: Tests de validación** (`InvalidDocument`, mensaje en español que nombra el campo): bruto ≤ tara; bruto > 88000; `harvest` que no matchea `/^\d{4}$/`; sin patentes; patente que no es `^[A-Z]{3}\d{3}$` ni `^[A-Z]{2}\d{3}[A-Z]{2}$` tras normalizar; `km`, `branch`, `orderNumber` ≤ 0.
- [ ] **Step 4: Run** → FAIL. **Step 5: Implement.** `WaybillResult` queda para Task 8. **Step 6: Run** → PASS.
- [ ] **Step 7: Commit** `feat(arca): add WSCPE waybill documents and envelopes`

---

### Task 8: `ArcaAuthority` — emitir, anular, consultar

**Files:**
- Create: `src/Arca/ArcaAuthority.php`, `src/Arca/Wscpe/WaybillResult.php`, `tests/Support/WscpeResponses.php`
- Test: `tests/Unit/Arca/ArcaAuthorityTest.php`

**Interfaces:**
- Consumes: `AccessTicketProvider`, `SoapTransport`, `ArcaEndpoints`, documentos de Task 7, `Cuit`, `UnsupportedDocument`.
- Produces:
  - `final class ArcaAuthority implements FiscalAuthority { __construct(AccessTicketProvider $tickets, SoapTransport $transport, ArcaEndpoints $endpoints); name(): string /* 'arca' */ }` + los métodos de `FiscalAuthority`.
  - `final class WaybillResult { static fromResponse(string $xml): array{respuesta: ?\SimpleXMLElement, errors: list<Reason>} /* no lanza: sin <respuesta> → respuesta null y errors vacío; quien llama lo convierte en unknown malformed_response */; static data(\SimpleXMLElement $respuesta): array{ctg: ?string, state: ?string, issued_at: ?\Carbon\CarbonImmutable, expires_at: ?\Carbon\CarbonImmutable, pdf: ?string, driver_cuit: ?string, plates: ?list<string>, gross_kg: ?int} }` (fechas leídas en hora AR).
  - Flujo común: `CallContext::representedTaxId` → `Cuit::from()`; ticket por `tickets->for($context, 'wscpe')` (si devuelve `Outcome`, se devuelve tal cual); `transport->call(new SoapCall('wscpe', $method, $url, WscpeXml::soapAction($method), $envelope, $env, $cuit))`; si `failure`, se devuelve.
  - `WscpeResponses::response(string $element, string $respuesta): string` (portado de `cpeResponse`), constantes `HEADER` (cabecera de #524 con CTG `101234567890`, estado `AC`) e `IDENTITY` (datosCarga/transporte con chofer `20123456786`, patente `AB123CD`, bruto `45000`).

- [ ] **Step 1: Tests de interpretación** (ticket ya en `InMemoryTicketStore`, `Http::fake()` con la respuesta de WSCPE):

| Test | Respuesta | Esperado |
| --- | --- | --- |
| `issue authorized` | `AutorizarCPEAutomotorResp`, `HEADER.'<pdf>JVBERi0=</pdf>'` | `isAuthorized`, `authorityId` `101234567890`, `data.state` `AC`, `data.pdf` `JVBERi0=`, `data.issued_at` == `2026-10-08T10:15:00-03:00` |
| `issue 550 is authorized without pdf` | `HEADER` + error 550 | autorizado, `pdf` null, `reasonCodes()` `['550']` |
| `issue rejected` | error `2002` "Tiene cartas de porte vencidas pendientes" | `isRejected`, motivo tal cual |
| `issue 961 is rejected` | error `961` | `isRejected`, código `961` |
| `issue internal error is unknown` dataset | error `500` → `internal_error`; `2241` → `already_issued` | `isUnknown` |
| `issue without ctg nor errors` | `<respuesta/>` vacío | `isRejected`, código `no_ctg` |
| `missing respuesta is unknown` | sobre sin `<respuesta>` | `isUnknown`, código `malformed_response` |
| `cancel annulled` | `AnularCPEResp`, cabecera con estado `AN` | autorizado; con 550 además sigue autorizado |
| `cancel out of time` | error `2121` "Plazo vencido" | `isRejected`, `2121` |
| `cancel still active` | cabecera `AC` sin errores | `isRejected`, código `not_annulled` |
| `query by ctg` | `ConsultarCPEAutomotorResp`, `HEADER.IDENTITY.'<pdf>x</pdf>'` | autorizado con `driver_cuit`, `plates ['AB123CD']`, `gross_kg 45000` |

- [ ] **Step 2: Tests de cableado**: `it('represents the context CUIT, not the certificate one')` (el sobre lleva `cuitRepresentada` del contexto `30712345671` aunque el cert diga otra); `it('propagates ticket failures')` (provider devuelve `ticket_already_held` → `issue` devuelve ese `Outcome` sin llamar a WSCPE); `it('rejects foreign documents')` → `UnsupportedDocument` para un `Issuable` que no es `ArcaWaybill`; `supports()` true sólo para los tres documentos.
- [ ] **Step 3: Run** → FAIL. **Step 4: Implement** portando `AuthorizeWaybillOperation`, `AnnulWaybillOperation`, `QueryWaybillOperation`, `WscpeSoap::cpeResult/errors/respuesta`. **Step 5: Run** → PASS.
- [ ] **Step 6: Commit** `feat(arca): issue, cancel and query waybills`

---

### Task 9: Reconciliación con identidad

**Files:**
- Modify: `src/Arca/ArcaAuthority.php` (`reconcile`)
- Test: `tests/Unit/Arca/ArcaReconcileTest.php`

**Interfaces:**
- Consumes: Task 8.
- Produces: `ArcaAuthority::reconcile(CallContext, Issuable|Cancellation): Outcome` con estas reglas (§6.4 del génesis):
  - Emisión: consulta `byOrderNumber(branch, orderNumber, cpeType)`. Sin CTG en la respuesta → `Outcome::unavailable(new Reason('not_found', …))`. CTG y coincide identidad → `authorized`. CTG y no coincide → `rejected([new Reason('order_number_taken', 'ARCA ya tiene otra carta de porte con este número de orden: …')])`. Error 500 → `unknown`.
  - Identidad: chofer, patentes normalizadas y ordenadas, bruto; si la respuesta no trae alguno de los tres → no coincide.
  - Anulación: consulta `byCtg` si la anulación trae `ctg`, si no `byOrderNumber`. Estado `AN` → `authorized`; cualquier otro caso → `unavailable(new Reason('not_annulled', …))`. Error 500 → `unknown`.
  - Falla de transporte o de ticket en la consulta → se devuelve esa falla.

- [ ] **Step 1: Tests**
  - `it('adopts the waybill when identity matches')` — respuesta `HEADER.IDENTITY`, documento `Fixtures::waybill(['plates' => ['AB123CD']])`.
  - `it('matches plates in any order and case')` (Review Focus 2) — respuesta con `<dominio>AC456DE</dominio><dominio>AB123CD</dominio>`, documento `['ab123cd', 'ac456de']` → autorizado.
  - `it('rejects when another truck holds the number')` dataset: chofer `20301234563`, patente `ZZ999ZZ`, bruto `44000` → `order_number_taken`.
  - `it('not found means safe to resend')` — error `2010` "No existe" sin cabecera → `isUnavailable`, `not_found`.
  - `it('missing identity fields do not match')` → `order_number_taken`.
  - `it('internal error during reconcile stays unknown')` dataset emisión y anulación.
  - `it('annulment reconciles by ctg when known')` — request contiene `<nroCTG>`; `AN` → autorizado; `AC` → `isUnavailable`, `not_annulled`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** (portar `Waybill::matchesArcaResult` como método privado). **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(arca): reconcile doubtful requests by identity`

---

### Task 10: Catálogos y último número de orden

**Files:**
- Modify: `src/Arca/ArcaAuthority.php`
- Test: `tests/Unit/Arca/ArcaCatalogsTest.php`

**Interfaces:**
- Produces (en `ArcaAuthority`, fuera de la interfaz común):
  - `lastOrderNumber(CallContext $context, int $branch, int $cpeType = ArcaWaybill::AUTOMOTOR): Outcome` — `consultarUltNroOrden` / `ConsultarUltNroOrdenReq`, body `<solicitud><sucursal>{branch}</sucursal><tipoCPE>{type}</tipoCPE></solicitud>`; autorizado con `authorityId` = el número como string y `data['last_order_number']` int.
  - `provinces(CallContext): Outcome` — `consultarProvincias` / `ConsultarProvinciasReq`, items de `<provincia>`.
  - `localities(CallContext, int $provinceCode): Outcome` — `consultarLocalidadesPorProvincia` / `ConsultarLocalidadesPorProvinciaReq`, body `<solicitud><codProvincia>{n}</codProvincia></solicitud>`, items de `<localidad>`.
  - `grainTypes(CallContext): Outcome` — `consultarTiposGrano` / `ConsultarTiposGranoReq`, items de `<grano>`.
  - Catálogos autorizados con `authorityId` = método y `data['items']` = `list<array{code: int, name: string}>`. Errores → `rejected`; 500 → `unknown`.
  - `forgetTicket(CallContext $context): void` → `tickets->forget($context, 'wscpe')`.

- [ ] **Step 1: Tests**: sobre y parseo de cada uno (respuesta con dos items, p. ej. `<provincia><codigo>0</codigo><descripcion>CABA</descripcion></provincia>`: la 0 es CABA, no hay 15); `lastOrderNumber` con `<nroOrden>418</nroOrden>` → `418`; error → rechazado; `forgetTicket` hace que el siguiente pedido pase por WSAA.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(arca): add WSCPE catalogs and last order number`

---

### Task 11: `FiscalManager`, fake, service provider y fachada

**Files:**
- Create: `src/FiscalManager.php`, `src/Testing/FakeAuthority.php`, `src/FiscalAdapterServiceProvider.php`, `src/Facades/Fiscal.php`
- Modify: `config/fiscal-adapter.php`, `tests/TestCase.php` (`getPackageProviders`), `README.md`, `CLAUDE.md`
- Test: `tests/Unit/FiscalManagerTest.php`, `tests/Unit/FakeAuthorityTest.php`, `tests/Feature/ServiceProviderTest.php`, `tests/Feature/FakeTest.php`

**Interfaces:**
- Produces:
  - `final class FiscalManager { __construct(array $factories = []) /* array<string, \Closure(): FiscalAuthority> */; extend(string $name, \Closure $factory): void; authority(string $name): FiscalAuthority /* memoiza; lanza UnknownAuthority */ }`
  - `final class FakeAuthority implements FiscalAuthority { __construct(string $name = 'fake'); respondWith(string $documentClass, Outcome|\Closure $response): self; assertIssued(?\Closure $filter = null): void; assertCancelled(?\Closure $filter = null): void; assertNothingSent(): void; sent(): list<array{operation: string, context: CallContext, document: object}> }` — sin respuesta programada devuelve `Outcome::authorized('FAKE-1')`, `'FAKE-2'`…; las aserciones lanzan `PHPUnit\Framework\AssertionFailedError` si la clase existe, si no `\LogicException`.
  - `config/fiscal-adapter.php`: `arca.timeout` (30), `arca.endpoints` (overrides, vacío), `arca.lock_seconds` (90).
  - `FiscalAdapterServiceProvider`: `mergeConfigFrom`; registra `FiscalManager` singleton con la fábrica `arca` → `ArcaAuthority` armada con `TicketStore` del contenedor (**si no está ligado, `MissingTicketStore`** con mensaje "Ligá una implementación durable de TicketStore…") y `CallObserver` del contenedor (default `EventCallObserver`). Publica la config con tag `fiscal-adapter-config`.
  - `Facades\Fiscal` sobre `FiscalManager` + `static fake(): FakeAuthority` que reemplaza el manager por uno cuyas autoridades son todas el mismo `FakeAuthority`; **lanza `\LogicException` si `app()->isProduction()`**.

- [ ] **Step 1: Tests unitarios**: manager resuelve y memoiza; `UnknownAuthority` con el nombre; fake registra envíos, respuestas por clase y por closure, numeración `FAKE-n`, aserciones que fallan.
- [ ] **Step 2: Tests de integración** (`tests/Feature`): `it('resolves arca with a bound ticket store')`; `it('fails clearly without a ticket store')` → `MissingTicketStore`; `it('dispatches FiscalCallRecorded end to end')` (`Event::fake()` + `Http::fake()`); `it('fakes through the facade')` → `Fiscal::fake()->respondWith(ArcaWaybill::class, Outcome::rejected([...]))` y `Fiscal::authority('arca')->issue(...)` devuelve eso; `it('refuses to fake in production')`.
- [ ] **Step 3: Run** → FAIL. **Step 4: Implement.** **Step 5: Run** → PASS; `$RUN composer test` → todo verde.
- [ ] **Step 6: README**: instalación (`repositories` vcs + `composer require mnonm/fiscal-adapter`), ejemplo con Laravel (ligar `TicketStore`, `Fiscal::authority('arca')->issue($context, $waybill)` y un `match` sobre `$outcome->status`), tabla de los cinco estados y qué hacer con cada uno (copiada del génesis §4.2), aviso de durabilidad del ticket y de no llamar dentro de transacciones. `CLAUDE.md`: actualizar con la estructura final.
- [ ] **Step 7: Commit** `feat: add fiscal manager, fake authority and Laravel integration`

---

### Task 12: Pruebas opcionales contra homologación

**Files:**
- Create: `tests/Homologation/ArcaHomologationTest.php`
- Modify: `README.md`, `CLAUDE.md`

**Interfaces:**
- Consumes: todo lo anterior.
- Produces: grupo `homologation`, que se salta solo si faltan `FISCAL_HOMO_CERT`, `FISCAL_HOMO_KEY`, `FISCAL_HOMO_CUIT`. **Nunca** apunta a producción: el test fija `Environment::Homologation` y falla si alguna variable apunta a otra cosa.

- [ ] **Step 1: Tests** (`->group('homologation')`, `beforeEach` con `markTestSkipped` si falta una variable):
  - `it('authenticates and lists provinces')` → autorizado, `count(items) === 24`, existe el código `0`.
  - `it('lists localities of a province')` → autorizado, más de 0 items.
  - `it('reads the last order number')` → autorizado o rechazado con código (no `unknown`/`unavailable`).
  - `it('gets an issue answered by ARCA')` → `issue()` de `Fixtures::waybill()` con número `last + 1` devuelve `isRejected()` (homologación no autoriza: 1005/956/1015/2243) y **nunca** `unknown`/`unavailable`.
  - El ticket se guarda en un `TicketStore` de archivo temporal dentro del test (durable entre corridas en la misma máquina, ruta en `sys_get_temp_dir()`), para no quemar el ticket de 12 h en cada corrida.
- [ ] **Step 2: Run sin variables**: `$RUN vendor/bin/pest --group=homologation` → SKIPPED. `$RUN vendor/bin/pest` → no las corre.
- [ ] **Step 3: Run con certificado** (lo provee el usuario; el de `Back/web/.arca-homo/` sirve): `docker run … -e FISCAL_HOMO_CERT=/certs/homo.crt -e FISCAL_HOMO_KEY=/certs/homo.key -e FISCAL_HOMO_CUIT=<cuit> -v <carpeta>:/certs:ro fiscal-adapter-test vendor/bin/pest --group=homologation` → PASS. **Pedir OK al usuario antes de correrlo**; si no hay certificado disponible, dejarlo anotado como pendiente.
- [ ] **Step 4: README/CLAUDE.md**: cómo correrlas y que nunca van en CI.
- [ ] **Step 5: Commit** `test(arca): add opt-in homologation suite`
