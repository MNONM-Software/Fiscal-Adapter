# fiscal-adapter

Paquete de composer para Laravel que habla con autoridades fiscales: ARCA hoy, SIFEN después. Expone
una interfaz común (núcleo) y un driver por autoridad.

El diseño vive en `docs/genesis/2026-11-13-fiscal-adapter-genesis-design.md` y es la autoridad:
cuando el código y el documento discrepan, gana el documento.

## Convenciones

- PHP 8.3, Laravel ^13 (`illuminate/*`). Llaves siempre, tipos explícitos, PHPDoc con array shapes.
- **Código, identificadores, nombres de tests y mensajes de commit en inglés.** Los mensajes de error
  que ve una persona, en español.
- Tests con Pest sobre Orchestra Testbench. Usar lo de Laravel: `Http::fake()`, `Cache::lock`,
  `config()`, `Carbon::setTestNow()`, eventos.
- Sin `ext-soap` (prohibido). Requiere `ext-openssl` y `ext-simplexml`.
- Fechas con ARCA: sin offset, en `America/Argentina/Buenos_Aires`.
- Ningún secreto (clave privada, passphrase, token, sign, CMS) en `CallRecord`, mensajes de
  excepción, `var_dump`/`print_r` ni en el repo.
- Repo público: CUITs de ejemplo inventados, con dígito verificador válido.
- Licencia `proprietary`, sin archivo `LICENSE`. No hay `composer.lock` (es una librería).

## Correr los tests

**No hay `php` en el host.** Todo comando PHP corre con la imagen de test:

```bash
docker build -f Dockerfile.test -t fiscal-adapter-test .
RUN='docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer -v "$PWD":/app -w /app fiscal-adapter-test'
$RUN composer install
$RUN vendor/bin/pest
```

**Antes de cerrar un cambio: `composer test` en verde** (pest + `pint --test` + phpstan). El grupo
`homologation` queda fuera de la corrida por defecto: pega contra los servicios reales.
Se aplica por directorio (`tests/Pest.php`) y se corre a mano con `--group=homologation` y las variables
`FISCAL_HOMO_CERT`/`FISCAL_HOMO_KEY`/`FISCAL_HOMO_CUIT` (ver README). Nunca en CI; nunca un certificado
real de producción.

## La frontera

**El núcleo no conoce ARCA.** Nada de `src/` fuera de `src/Arca/` nombra CUIT, CTG, ticket, WSAA ni
SOAP. `tests/ArchTest.php` lo cuida para `Contracts`, `Exceptions` y `Testing`.

**La librería no toca bases de datos** ni conoce modelos de Artisano. Cache y locks van por
`Cache`, nunca por `Illuminate\Database`.

## Estructura

- `src/` raíz: núcleo (`Outcome`, `CallContext`, `Credential`, `CallObserver`, `FiscalManager`),
  `Contracts/`, `Exceptions/`, `Facades/Fiscal` (con `fake()`), `Testing/FakeAuthority`.
- `src/Arca/`: el driver ARCA. `ArcaWaybillAuthority` (contrato del adaptador: ciclo de vida +
  último número, catálogos, `forgetTicket`), `Wsaa/` (ticket de acceso, `TicketStore`), `Wscpe/`
  (documentos y XML de cartas de porte), `Soap/` (transporte sobre `Http`), `Testing/FakeArcaAuthority`
  (lo que devuelve `Fiscal::fake()`, compone `FakeAuthority`).
- `FiscalAdapterServiceProvider` es el único punto de la raíz que arma cosas de ARCA (la fábrica
  `arca` del manager, con el `TicketStore` del contenedor o `MissingTicketStore` si no hay uno).
- Agregar una autoridad: `Fiscal::extend('nombre', fn () => new MiAutoridad(...))`.
- Tests: `tests/Unit` (puros), `tests/Feature` (provider, fachada, fake), `tests/Homologation`.
