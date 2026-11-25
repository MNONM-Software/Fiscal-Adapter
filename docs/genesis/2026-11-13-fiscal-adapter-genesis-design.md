# Fiscal Adapter — documento de génesis

> Fecha: 2026-11-13 · Estado: aprobado el 2026-11-13
> Origen: brainstorm del 12-13/11/2026 sobre la épica AR-2500 (Integración con ARCA), más la review de esa épica.
> Las reglas generales de todo proyecto nuevo están en [`PROJECT_GENESIS.md`](./PROJECT_GENESIS.md) y valen acá sin repetirlas.

Convención: **[U]** marca una decisión del usuario. **[C]** marca una decisión de Claude durante el brainstorm,
revisable. Lo que no tiene marca es descripción o contexto.

---

## 1. Qué es y por qué existe

Fiscal Adapter es una **librería PHP** que habla con los organismos fiscales —hoy ARCA (Argentina), más
adelante SIFEN (Paraguay) u otros— detrás de una interfaz que no depende del país. **[U]**

Quien la usa (Artisano, el primero) le da lo necesario para comunicarse: certificado, clave, a quién representa,
ambiente y los datos del documento. La librería arma el pedido, lo firma, lo manda, interpreta la respuesta y
devuelve un resultado simple. **No guarda nada ni conoce el modelo de quien la usa.** **[U]**

### Por qué ahora

La épica AR-2500 construyó la integración con ARCA **adentro del monolito de Artisano** (`app/Services/Arca/`,
PR #524). Cuando Artisano se aplique en Paraguay, el organismo es SIFEN, no ARCA, y ese código no sirve: está
atado a ARCA y a los modelos de Artisano. **[U]**

Antes se intentó resolverlo con un microservicio central (Artisano Fiscal, ex Themis). Se descartó por
sobredimensionado. **[U]** Esta librería reemplaza a los dos: **PR #524 no se mergea** y su código ARCA se muda acá. **[U]**

### Qué queda en otro lado

| Qué | Dónde |
| --- | --- |
| Remito R con CAI | AR-2846 (BE) / AR-2847 (FE). El CAI se pide a mano en la web de ARCA; no hay webservice, así que no pasa por esta librería |
| Conectar Artisano a la librería: tabla de cartas de porte, emitir desde la orden de carga, cola, reintentos, credenciales por empresa, log de llamadas con retención de 10 años, pantallas | Ticket aparte de Artisano **[U]** |

---

## 2. Glosario

| Término | Qué es |
| --- | --- |
| Organismo / autoridad | La administración fiscal: ARCA (ex AFIP) en Argentina, la SET con su sistema SIFEN en Paraguay |
| Adaptador | La implementación de la interfaz común para un organismo. v1: sólo ARCA |
| Contribuyente representado | La CUIT/RUC en cuyo nombre se pide. Puede no ser la del certificado (delegación) |
| WSAA | Servicio de autenticación de ARCA. Recibe un pedido firmado con el certificado (TRA en CMS) y devuelve un ticket (token + sign) de unas 12 h por servicio |
| WSCPE | Webservice de Carta de Porte Electrónica de ARCA |
| CPE / carta de porte | Documento que acompaña el traslado de granos. ARCA lo autoriza en línea y asigna el CTG |
| CTG | Código de trazabilidad de granos: el identificador que ARCA da a una carta de porte autorizada |
| Número de orden (`nroOrden`) | Numeración de la carta de porte por sucursal y tipo, que pone quien emite |
| Homologación | Ambiente de prueba de ARCA, con certificado propio sacado en WSASS |

---

## 3. Forma y stack

- **Repo público** `MNONM-Software/Fiscal-Adapter`, paquete de composer `mnonm/fiscal-adapter`, namespace
  `Mnonm\FiscalAdapter`. **[U]** Se instala como Hermes: entrada `"type": "vcs"` en el `composer.json` de quien
  la usa, sin Packagist. **[C]** Pasarla a privada después es cambiar la visibilidad y dar un token a CI,
  máquinas y deploy; no toca el código. **[U]** **Sin licencia**, como Hermes: el código se ve pero no se puede
  reusar legalmente; abrirla después siempre se puede. Sin archivo `LICENSE` y `"license": "proprietary"` en
  `composer.json`. **[U]**
- **PHP 8.3**, el de Artisano. **[C]** Requiere `ext-openssl`. **Prohibido requerir `ext-soap`**: los contenedores
  de Artisano no la tienen y sumarla es cambiar la infraestructura de cada instancia. El sobre SOAP se arma a mano,
  como en #524. **[C, heredado de la review]**
- **Paquete Laravel**, como Hermes: requiere `illuminate/*` ^13 (support, http, cache, events, contracts), porque
  es lo que usan el resto de los proyectos. **[U]** Se aprovecha lo de Laravel en vez de reinventarlo: cliente
  `Http` (y `Http::fake()` en tests), `Cache::lock` para la renovación del ticket, `config()`, Carbon y
  `Carbon::setTestNow()` para el reloj, eventos. El costo aceptado: cuando Artisano suba de versión de Laravel, la
  librería tiene que acompañar. Service provider, fachada `Fiscal` y `Fiscal::fake()` por auto-discovery.
- La distinción "no llegó" / "pudo haber llegado" (§6.3) sale del mensaje de cURL que trae la
  `ConnectionException` del cliente `Http`, como en #524.
- Tests con **Pest** sobre **Orchestra Testbench**, formato con **Pint**, análisis estático con **PHPStan**. El
  nivel queda a criterio del builder. **[C]**
- Versionado semántico con tags `vX.Y.Z`. Mientras la API se asienta, `0.x`. **[C]**
- **No aplica Hermes** (no se despliega: la instala quien la usa). **[C]**

---

## 4. Arquitectura: lo común y lo de cada país

### 4.1 Decisión

Una interfaz única con los mismos datos para todos los países no funciona: una carta de porte argentina (CTG,
planta, kilos estimados, km) y una nota de remisión paraguaya (XML firmado, CDC armado por quien emite) no piden
lo mismo. Forzar un formato común termina en el mínimo común o en campos que sobran. **[C, aceptado por U]**

Por eso **lo común es el ciclo de vida y el resultado; lo de cada país es el documento**. **[C, aceptado por U]**

```
quien usa la librería
        │  Fiscal::authority('arca')            ← fachada / manager: elige el adaptador
        ▼
  FiscalAuthority (interfaz común)              ← issue · cancel · query · reconcile → Outcome
        │
        ├── ArcaAuthority                       ← v1
        │      ├── Wsaa (ticket)  ── TicketStore (lo provee quien usa)
        │      ├── SoapTransport  ── CallObserver (lo provee quien usa)
        │      └── Wscpe: ArcaWaybill, ArcaWaybillAnnulment, ArcaWaybillQuery, catálogos, último nro de orden
        │
        └── SifenAuthority                      ← futuro, no en v1
```

### 4.2 El núcleo (sin palabras de ningún país)

Nada del núcleo nombra CUIT, CTG, ticket, WSAA ni SOAP. Ese es el criterio para saber si algo va en el núcleo o
en un adaptador. **[C]**

- **`FiscalAuthority`** — lo que implementa cada adaptador:
  - `issue(CallContext, Issuable): Outcome`
  - `cancel(CallContext, Cancellation): Outcome`
  - `query(CallContext, Query): Outcome`
  - `reconcile(CallContext, Issuable|Cancellation): Outcome` — averigua si un pedido dudoso llegó (§6.4). Nunca
    devuelve `Unknown` por "no lo encontré": si el organismo confirma que no está, es `Unavailable` y reenviar es
    seguro. `Unknown` sólo si la consulta misma no tuvo respuesta clara. Nunca devuelve `Rejected` salvo una
    diferencia positiva de identidad: si la consulta falla, es `Unknown`. **[C; ajustado tras la revisión final]**
  - `supports(object $document): bool` — qué documentos sabe tratar.
  Lo que es propio de un país y no es ciclo de vida (catálogos, último número de orden) se expone en el adaptador
  concreto, no en la interfaz. **[C]**
- **`Issuable`, `Cancellation`, `Query`** — interfaces marcadoras que implementan los documentos de cada país.
- **`CallContext`** — `Credential`, identificador fiscal representado (string normalizado), `Environment`.
- **`Credential`** — certificado y clave privada (con passphrase opcional), construible desde archivos o desde
  PEM en memoria. Nunca se serializa, nunca aparece en un mensaje de excepción ni en el log. Expone una huella del
  certificado para usarla como clave del ticket. **[C]** La review de AR-2500 fija que la clave privada no entra a
  la base de quien la usa; la librería no la guarda en ningún lado.
- **`Environment`** — `Homologation` | `Production`. Las URLs de cada ambiente vienen dentro de la librería, con
  posibilidad de pisarlas por configuración. **[C]**
- **`Outcome`** — el resultado, siempre un valor, nunca una excepción por algo que dijo o no dijo el organismo:

  | Estado | Significa | Qué hace quien la usa |
  | --- | --- | --- |
  | `Authorized` | El organismo aceptó. Trae el identificador (CTG, CDC, CAE…) y los datos devueltos | Guarda |
  | `Rejected` | El organismo respondió que no. Trae motivos `{code, message}` tal cual | Muestra; corrige y reintenta con otro pedido |
  | `Pending` | Recibido, resultado después (SIFEN por lotes) | Consulta más tarde |
  | `Unavailable` | No llegó, seguro. Reintentar es inofensivo | Reintenta |
  | `Unknown` | Pudo haber llegado. **No reintentar sin reconciliar** | `reconcile()` antes de reintentar |

  `Pending` no lo usa ARCA en v1: está para que SIFEN entre sin cambiar la interfaz. **[C]**
- **Excepciones** sólo para errores de programación o configuración: credencial ilegible, ambiente sin URL,
  documento que el adaptador no soporta, datos inválidos antes de mandar. **[C]**
- **`CallObserver`** — callback que recibe un `CallRecord` por cada llamada al organismo: organismo, servicio,
  método, ambiente, URL, representado, pedido y respuesta **ya sin secretos**, código HTTP, duración, error,
  y la `reference` opcional que trajo el `CallContext` (un id opaco de quien llama, que la librería no lee y
  copia a todas las llamadas que ese contexto dispara, login de WSAA incluido; agregado el 25/11/2026 para que
  Artisano ate cada llamada a su pedido sin estado implícito). La librería no lo guarda; quien la usa decide dónde (Artisano: tabla con retención de 10 años). **[U: lo
  guarda el Back; C: forma]** Con la integración de Laravel se despacha como evento.

### 4.3 Integración con Laravel

- `Fiscal::authority('arca')` resuelve el adaptador desde el contenedor, con su `TicketStore` y su `CallObserver`.
- `Fiscal::fake()` al estilo `Http::fake()`: respuestas programadas por documento y aserciones
  (`assertIssued`, `assertNothingSent`). Quien la usa lo necesita para sus tests. **[C]** Devuelve un fake del
  adaptador ARCA (`Arca\Testing\FakeArcaAuthority`, que implementa `Arca\ArcaWaybillAuthority`) para que también
  cubra último número de orden y catálogos: el flujo de emisión los necesita. Es el segundo punto, junto al
  service provider, donde la capa Laravel nombra ARCA. **[C, revisión final]**
- El fake no corre en producción: si `app()->isProduction()`, `fake()` falla. **[C, heredado de Artisano Fiscal]**
- Config publicable (ambiente, timeouts, URLs). Sin migraciones, sin modelos, sin comandos que escriban en una
  base. **[C]**

---

## 5. Lo que el adaptador de SIFEN va a necesitar (para no cerrarle la puerta)

**No verificado:** sale de lo que se sabe de SIFEN, no de un relevamiento. No se implementa en v1. **[C]**

- Autenticación por TLS mutuo con el certificado del contribuyente, no por ticket. → El ticket (WSAA) es del
  adaptador ARCA, no del núcleo; `Credential` (certificado + clave) sirve a los dos.
- Documento XML firmado (XMLDSig) por quien emite. → La firma es del adaptador.
- El identificador (CDC, 44 dígitos) lo arma quien emite **antes** de mandar. → `Outcome::Authorized` lleva el
  identificador que confirma el organismo, que en SIFEN coincide con el armado.
- Envío por lotes asincrónico. → `Outcome::Pending`.
- Contingencia: se emite sin conexión y se manda después. → Es un modo del adaptador; el núcleo no lo bloquea.
- La anulación es un "evento". → `Cancellation` es una interfaz marcadora: cada país la llena como quiera.

El builder **no** diseña SIFEN; sólo verifica que nada del núcleo lo impida. Ante la duda, el núcleo se queda
más chico.

---

## 6. Adaptador ARCA (v1)

### 6.1 Alcance: sólo carta de porte **[U]**

Carta de Porte Automotor de granos por WSCPE, el mismo primer corte que #524: origen productor (campo o
silobolsa en campo), sin intervinientes, sin semilla.

| Operación | Método WSCPE | En la librería |
| --- | --- | --- |
| Emitir | `autorizarCPEAutomotor` | `issue(ArcaWaybill)` |
| Anular | `anularCPE` (motivo 1 siniestro, 2 pérdida de carga, 3 otro + observaciones) | `cancel(ArcaWaybillAnnulment)` |
| Consultar | `consultarCPEAutomotor` por CTG, o por tipo + sucursal + número de orden | `query(ArcaWaybillQuery)` |
| Último número de orden | `consultarUltNroOrden` (sucursal, tipo) | método del adaptador |
| Catálogos | provincias, localidades por provincia, tipos de grano | métodos del adaptador |

El catálogo de plantas (`consultarPlantas`) **no entra en v1**: #524 no lo implementa y no hay respuestas grabadas. **[C]**

`Authorized` de una carta de porte trae: CTG, estado, emisión, vencimiento, PDF (base64, puede faltar: 550),
CUIT del chofer, patentes y peso bruto. La anulación es `Authorized` cuando ARCA devuelve estado anulado.

### 6.2 WSAA y el ticket

- TRA con ventana −60 s / +10 min sobre el reloj, firmado con `openssl_pkcs7_sign` en modo binario, **no
  desacoplado** (la firma encapsula el TRA), DER en base64 sin saltos. Se lee `loginCmsReturn` como nodo de texto.
- Ticket por **(huella del certificado, servicio, ambiente)**. Se renueva 5 minutos antes de vencer.
- **El ticket tiene que persistirse en un almacén durable, no en un cache.** WSAA no entrega uno nuevo mientras el
  anterior siga vigente ("ya posee un TA valido"): si se pierde, no hay autenticación hasta que venza, hasta 12 h.
  **[C, corrige una propuesta anterior; heredado de la review]** Por eso:
  - La librería define `TicketStore` (leer, guardar, olvidar); quien la usa provee la implementación (Artisano:
    tabla cifrada). La renovación va dentro de un `Cache::lock` por clave para que dos procesos no renueven a la
    vez, y dentro del lock se relee antes de pedir.
  - Trae un `InMemoryTicketStore` sólo para tests y **ningún** adaptador de cache.
  - "ya posee un TA valido" sin ticket guardado → `Unavailable` con un código propio y un mensaje que dice que se
    podrá autenticar cuando venza.
  - Un ticket guardado que no se puede leer (p. ej. se rotó la clave de cifrado de quien la usa) cuenta como
    inexistente.
  - El lock no se pide dentro de una transacción de base de quien la usa: con REPEATABLE READ la relectura no ve
    el ticket que otro proceso acaba de guardar. Se documenta para quien la usa.
- **El ticket guarda qué CUIT puede representar en el momento de emitirse.** Una delegación nueva no aparece hasta
  el próximo ticket. → La librería expone cómo descartar el ticket de una credencial (`forgetTicket()`), explicado
  en el README. **[C, medido en homologación el 13/10/2026]**
- Una falla de WSAA (autenticación) no llegó a WSCPE: se devuelve `Unavailable` con código `auth_failed`, salvo
  `ticket_already_held` y `lock_timeout`. **[C, revisión final]**

### 6.3 Transporte SOAP

- SOAP 1.1 armado a mano sobre el cliente `Http` de Laravel, timeout configurable (30 s en #524).
- WSCPE: el elemento raíz va en el namespace del servicio (`https://serviciosjava.afip.gob.ar/wscpe/`) y sus hijos
  sin namespace; el orden de los hijos es el del esquema.
- Clasificación del resultado del transporte:

  | Qué pasó | Outcome |
  | --- | --- |
  | DNS, conexión rechazada, TLS (no se mandó nada) | `Unavailable` |
  | cURL 18, 28, 52, 55, 56, 92 (timeout, respuesta vacía o cortada: pudo haber llegado) | `Unknown` |
  | HTTP no 2xx sin SOAP Fault | `Unknown` |
  | SOAP Fault `Client`/`Sender` (la culpa es del sobre; los errores de autenticación de WSCPE llegan así) | `Rejected`, código `soap_client_fault` |
  | SOAP Fault `Server` | `Unavailable` |
  | Respuesta sin `<respuesta>` | `Unknown` |
  | Código de error WSCPE `500` (error interno de ARCA) | `Unknown` |

  Las filas de Fault `Server` y de HTTP no 2xx coinciden con #524 (verificado por el revisor del documento). **[C]**
- **Secretos tapados** antes de que el pedido o la respuesta salgan de la librería (`CallRecord`, excepciones): el
  contenido de `token`, `sign` e `in0` (el CMS), con o sin prefijo, en cualquier mayúscula, y también la forma
  escapada (`&lt;token&gt;`) con que WSAA devuelve el ticket. Con test que lo fije. **[C, heredado]**
- Fechas con ARCA: van y vuelven **sin offset, en hora argentina** (`America/Argentina/Buenos_Aires`). La librería
  recibe y devuelve `DateTimeImmutable` con zona; la conversión es interna. **[C, heredado]**

### 6.4 Reglas de la carta de porte que la librería tiene que saber

- **Número de orden consecutivo.** ARCA exige `nroOrden = consultarUltNroOrden + 1` (error 961 si no; se valida
  antes que el padrón). La librería no numera: expone el último número y quien la usa decide. **[C, medido en
  homologación]**
- **2241 (ya emitida con ese número)** → `Unknown`: se resuelve reconciliando.
- **Reconciliación con identidad.** La consulta por número de orden puede encontrar la carta de porte **de otro
  camión**, porque un intento rechazado libera el número y otro lo toma. `reconcile()` compara chofer, patentes y
  peso bruto con el `ArcaWaybill` mandado: coincide → `Authorized`; no coincide → `Rejected` con código
  `order_number_taken`; la respuesta no trae alguno de los tres → `Unknown` con `identity_unverifiable` (la forma de
  la respuesta autorizada nunca se vio en vivo); no hay ninguna → `Unavailable` (no quedó en ARCA: reenviar **el
  mismo documento** es seguro, como en #524). Si la consulta misma vuelve rechazada, es `Unknown` con `reconcile_failed`:
  nunca un `Rejected` que lleve a pedir otro número. Si la consulta no llegó (conexión, falla del servidor, WSAA),
  vuelve `Unavailable`: reenviar el mismo documento es seguro, porque si ya estaba ARCA responde 2241 y se reconcilia
  de nuevo. Con CTG, la consulta es
  por CTG. En la anulación: estado anulado → `Authorized`; sigue activa → `Unavailable` (se reenvía `anularCPE`,
  como en #524). **[C, heredado: bug real hallado en la
  revisión de #524]**
- **Fumigación.** `mercaderiaFumigada` tiene el sentido invertido en ARCA (`true` = no fumigada). El documento
  expone `declaredNotFumigated` y la librería lo manda tal cual, con un test que fija el sentido. **[C, heredado]**
- **Anulación fuera de plazo (2121)** → `Rejected`.
- **CUIT.** Value object con validación de dígito verificador y normalización (con o sin guiones). Vive en el
  adaptador ARCA, no en el núcleo. **[C]**
- **Validación antes de mandar.** `ArcaWaybill` valida lo que no depende de ARCA (obligatorios, CUITs, tara + bruto
  ≤ 88.000 kg, patentes) y falla con excepción antes de gastar una llamada. **[C]**

### 6.5 Límite de homologación

En homologación **no se puede llegar a una carta de porte autorizada**: ninguna CUIT de prueba cumple a la vez IVA,
domicilio en el padrón único y actividad de productor (errores 1005, 956, 1015, 2243 según la CUIT). Contra
homologación se prueban la autenticación, los catálogos y que un pedido llegue y vuelva rechazado con su código. El
camino autorizado se cubre con respuestas grabadas. **[C, medido 09-13/10/2026]**

---

## 7. Pruebas

- **Unitarias y de contrato** con respuestas XML grabadas: autorizada, rechazada, 550 sin PDF, 2241, 961, 2121,
  Fault `Client`, Fault `Server`, sin `<respuesta>`, código 500, respuesta de WSAA con ticket escapado. Las de #524
  sirven de base (`tests/Feature/Arca/**` y `tests/Support/ArcaWsaaResponses.php`, `ArcaTestCertificate.php` en la
  rama `AR-2500-be-integracion-arca`).
- **Certificado de prueba autofirmado generado en el test**, como `ArcaTestCertificate` de #524. Nunca uno real.
- **Contra homologación: opcionales**, activadas por variables de entorno (ruta del certificado y la clave, CUIT
  representada). **Nunca corren en CI** ni contra producción. **[U: sólo homologación, nunca producción]**
- CI en GitHub Actions: Pest, Pint en modo test, PHPStan, sobre PHP 8.3 y 8.4.

---

## 8. Seguridad (repo público)

- **Nada personal ni de clientes en el repo.** La rama de #524 tiene la CUIT personal del desarrollador y alias de
  certificados de homologación en `docs/runbooks/arca-certificados.md` y en un spec de LPG. Esos documentos **no se
  copian**; si hace falta su contenido, se reescribe sin datos personales. Los tests de #524 están limpios
  (verificado el 13/11/2026), pero se revisan al portarlos.
- CUITs de ejemplo en tests y docs: inventados con dígito verificador válido, o los de prueba que publica ARCA.
- Certificados, claves y tickets: nunca en el repo, nunca en logs, nunca en mensajes de excepción. `.gitignore` con
  `*.key`, `*.pem`, `*.crt`, `*.p12` y `.env*` desde el primer commit.

---

## 9. Objetivos, no-objetivos y anti-objetivos

### Objetivos

- Artisano puede emitir, anular y consultar cartas de porte con ARCA usando sólo la librería, con lo mismo que hacía
  #524.
- Sumar SIFEN (u otro organismo) es escribir un adaptador nuevo sin tocar el núcleo ni lo ya hecho para ARCA.
- Lo aprendido en #524 y en homologación no se pierde: está en código y tests.

### No-objetivos (v1)

- WSLPG (liquidación primaria de granos), WSFE (factura con CAE), otros servicios de ARCA. **[U]**
- El adaptador de SIFEN. **[U]**
- Persistencia, cola, reintentos, scheduler, alertas cuando un pedido falla. Son de quien la usa.
- El CAI de remitos. **[U]**
- Conectar Artisano. **[U]**

### Anti-objetivos: lo que la haría un fracaso aunque funcione

- Que la librería toque una base de datos (ni migraciones ni modelos) o conozca un modelo de Artisano.
- Que el núcleo nombre conceptos de ARCA y SIFEN tenga que esquivarlos.
- Que un secreto (clave, token, sign, CMS) aparezca en un log, una excepción o el repo.
- Que requiera `ext-soap`.
- Que el ticket de WSAA viva en un almacén que se pueda borrar solo.
- Que un timeout se trate como "no llegó" y se reintente sin reconciliar: autorizaría dos veces el mismo documento.

---

## 10. Código de origen

La rama `AR-2500-be-integracion-arca` del repo de Artisano Back (en `origin`) queda como referencia. Lo que se muda,
adaptado para no depender de los modelos de Artisano ni de Eloquent:

| En #524 | En la librería |
| --- | --- |
| `app/Services/Arca/WsaaClient.php`, `AccessTicket.php`, `AccessTicketProvider.php` | WSAA + ticket, con `TicketStore` en vez de Eloquent (`Cache::lock` se mantiene) |
| `app/Services/Arca/SoapTransport.php` | Transporte, con `CallObserver` en vez de `ArcaCallLog::create` |
| `app/Services/Arca/ArcaEndpoints.php`, `config/arca.php` | `Environment` + URLs |
| `app/Services/Arca/ArcaOutcome.php` | `Outcome` (con los estados nuevos) |
| `app/Services/Arca/Wscpe/*` | Adaptador ARCA, WSCPE, con documentos propios en vez de `Waybill`/`ArcaRequest` |
| `app/Services/Arca/FakeArcaGateway.php` | `Fiscal::fake()` |
| `app/Support/Cuit.php` | CUIT del adaptador ARCA |
| `Waybill::matchesArcaResult()` | Identidad de la reconciliación (§6.4) |

Lo que **no** se muda: `ArcaRequest`, `ArcaCallLog`, `ArcaCredential`, `ArcaAccessTicket`, el job, el sweep, los
comandos, los catálogos persistidos, `Waybill`, `RemitoCai`. Son de Artisano.

---

## 11. Lo que queda a criterio del builder

- Los nombres finales de clases y métodos, dentro de §4.2.
- La forma de los documentos ARCA (constructor con argumentos nombrados, builder, etc.).
- El nivel de PHPStan y la estructura de carpetas.
- Si el `README` va en español o en inglés (el código va en inglés, por `PROJECT_GENESIS.md`).
