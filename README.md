# mnonm/fiscal-adapter

Paquete Laravel para hablar con autoridades fiscales (ARCA hoy, SIFEN más adelante) detrás de una
interfaz común.

## Instalación

El paquete es privado y no está en Packagist; se instala por VCS. En el `composer.json` del
proyecto:

```json
{
    "repositories": [
        { "type": "vcs", "url": "git@github.com:MNONM-Software/Fiscal-Adapter.git" }
    ],
    "require": {
        "mnonm/fiscal-adapter": "^0.1"
    }
}
```

Requiere PHP ^8.3, `ext-openssl` y `ext-simplexml`. El service provider y el alias `Fiscal` se
registran solos por package discovery.

## Uso

### Ligar el almacén de tickets

ARCA no emite un ticket de acceso nuevo mientras el anterior siga vigente (hasta 12 h). Por eso el
paquete exige un `TicketStore` **durable** (base de datos o archivo): un cache que se vacía deja la
credencial sin autenticar hasta que el ticket venza. Sin uno ligado, `Fiscal::authority('arca')`
lanza `MissingTicketStore`.

```php
// AppServiceProvider::register()
$this->app->singleton(\Mnonm\FiscalAdapter\Arca\Wsaa\TicketStore::class, MiTicketStoreEnBase::class);
```

Opcional: ligar un `CallObserver` propio. Si no, se usa `EventCallObserver`, que dispara
`FiscalCallRecorded` por cada llamada al organismo.

### Emitir

```php
use Mnonm\FiscalAdapter\CallContext;
use Mnonm\FiscalAdapter\Environment;
use Mnonm\FiscalAdapter\Facades\Fiscal;
use Mnonm\FiscalAdapter\OutcomeStatus;

$context = new CallContext($credential, '30712345671', Environment::Production);

$outcome = Fiscal::authority('arca')->issue($context, $waybill);

match ($outcome->status) {
    OutcomeStatus::Authorized => $this->guardar($outcome->authorityId, $outcome->data),
    OutcomeStatus::Rejected => $this->mostrar($outcome->reasons),
    OutcomeStatus::Pending => $this->consultarMasTarde(),
    OutcomeStatus::Unavailable => $this->reintentar(),
    OutcomeStatus::Unknown => Fiscal::authority('arca')->reconcile($context, $waybill),
};
```

### Los cinco estados

El resultado es siempre un valor, nunca una excepción por algo que dijo o no dijo el organismo.

| Estado | Significa | Qué hace quien la usa |
| --- | --- | --- |
| `Authorized` | El organismo aceptó. Trae el identificador (CTG, CDC, CAE...) y los datos devueltos | Guarda |
| `Rejected` | El organismo respondió que no. Trae motivos `{code, message}` tal cual | Muestra; corrige y reintenta con otro pedido |
| `Pending` | Recibido, resultado después (SIFEN por lotes) | Consulta más tarde |
| `Unavailable` | No llegó, seguro. Reintentar es inofensivo | Reintenta |
| `Unknown` | Pudo haber llegado. **No reintentar sin reconciliar** | `reconcile()` antes de reintentar |

`Pending` no lo usa ARCA en v1.

### Avisos

- **`query()` devuelve `Authorized` para cualquier CPE encontrado, también uno anulado.** Quien llama
  tiene que mirar `$outcome->data['state']` (`AC` activo, `AN` anulado).
- **No llamar dentro de una transacción `REPEATABLE READ`.** El ticket de acceso se renueva bajo un
  lock y se relee del almacén; dentro de una transacción con snapshot fijo no se vería el ticket que
  otro proceso acaba de guardar, y se pediría uno de más.
- `config/fiscal-adapter.php` (publicable con el tag `fiscal-adapter-config`): `arca.timeout` (30 s),
  `arca.endpoints` (URLs a pisar) y `arca.lock_seconds` (null = `timeout + 60`; debe superar al timeout).

### Tests de quien usa el paquete

```php
$fake = Fiscal::fake()->respondWith(ArcaWaybill::class, Outcome::rejected([new Reason('2002', 'x')]));

// ... código bajo prueba ...

$fake->assertIssued(fn ($document, $context) => $document->orderNumber === 419);
```

Sin respuesta programada, el fake devuelve `Authorized` con `FAKE-1`, `FAKE-2`... `Fiscal::fake()`
lanza `LogicException` en producción.

### Pruebas contra homologación (opcionales)

`tests/Homologation` pega contra WSAA y WSCPE de homologación. **Nunca corren en CI** ni en `pest` a secas:
el grupo `homologation` se aplica por directorio y `phpunit.xml` lo excluye. Sin las variables
`FISCAL_HOMO_CERT`, `FISCAL_HOMO_KEY` y `FISCAL_HOMO_CUIT` se saltan. Fijan `Environment::Homologation`;
no hay forma de apuntarlas a producción. Con un certificado de homologación propio:

```bash
docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer \
  -e FISCAL_HOMO_CERT=/certs/homo.crt -e FISCAL_HOMO_KEY=/certs/homo.key -e FISCAL_HOMO_CUIT=<cuit> \
  -v "$PWD":/app -v <carpeta-con-certificado>:/certs:ro -w /app fiscal-adapter-test \
  vendor/bin/pest --group=homologation
```

El ticket de acceso (12 h) se guarda en `sys_get_temp_dir()` (modo 0600) para no pedir uno por corrida.

## Desarrollo

Ver `CLAUDE.md`. Resumen: `composer test` (pest + pint + phpstan), corrido dentro de la imagen
`Dockerfile.test`.
