# Reglas de genesis del proyecto

## 1. Idioma del código
- Todo el código se escribe en **inglés**: variables, funciones, clases, archivos, comentarios, mensajes de commit, nombres de tablas/columnas.
- Nunca en español ni en otro idioma. (Los textos visibles al usuario final pueden ir en el idioma del producto.)

## 2. Lenguaje y stack
- Si el lenguaje o el stack no están definidos en la carpeta genesis, **preguntar antes de empezar**.
- No asumir Python ni ningún otro lenguaje/framework por defecto.

## 3. Contexto del proyecto
- Antes de planificar o codear, leer todo el contenido de la carpeta genesis (`docs/genesis/`).
- Ahí está el contexto de qué se va a construir: usarlo como fuente de verdad.

## 4. Commits
- Los commits van solo a nombre del usuario.
- No agregar `Co-Authored-By: Claude`, ni "Generated with Claude Code", ni ninguna otra atribución a Claude en commits o PRs.

## 5. Proyectos Laravel
- Buscar en internet cuál es la última versión estable de Laravel y usar esa.
- Antes de escribir código, instalar Laravel Boost para trabajar "the Laravel way":
  ```bash
  composer require laravel/boost --dev
  php artisan boost:install
  ```

## 6. Hermes

Hermes es el deployer de Olympus ([MNONM-Software/Hermes](https://github.com/MNONM-Software/Hermes)).
Lee el `deploy.php` del repo, lo valida contra un vocabulario cerrado de pasos y ejecuta la release,
revirtiendo si algo falla. **La fuente de verdad del vocabulario es el código del runner**
(`runner/src/StepTypes.php`, `runner/src/Manifest.php`, `runner/src/Variables.php`): si algo de esta
sección contradice ese código, gana el código.

### 6.1 Antes de empezar: preguntar
- Si el contexto o la carpeta genesis no dicen si el proyecto va a usar Hermes, **preguntar**.
- Si lo usa, preguntar también lo que el manifiesto necesita y no se puede adivinar:
  - **Cómo corre en el servidor**: con `docker compose` (nombre de cada servicio y la carpeta de
    trabajo dentro del container) o directo en el host.
  - **Qué procesos de larga vida tiene**: php-fpm, worker de colas, scheduler, Horizon, Reverb, un
    server de node. Cada uno se reinicia después del deploy.
  - **Si se sirve bajo un prefijo** (por ejemplo `/ceo`): cambia la ruta de salud.
  - **Qué base usa y en qué versión**: el backup necesita el cliente de dump que corresponda.

### 6.2 Lo que necesita cualquier proyecto, sea del stack que sea
- **El repo vive en la organización `MNONM-Software` de GitHub.** El runner sólo clona repos cuyo
  dueño está en su allowlist (`allowed_owners` de `~/hermes/config.php`). Un repo personal se rechaza
  con `repo_not_allowed` al lanzar. Si tiene que ser de otro dueño, hay que sumarlo a esa allowlist
  en cada host donde corre el runner.
- **Tiene que estar pusheado.** Hermes lee el `deploy.php` de su propio clon, sincronizado desde
  GitHub; un cambio que sólo existe en local no lo ve.
- **`deploy.php` en la raíz del repo.** Devuelve un array con `version => 1`, `context` y `steps`:
  ```php
  return [
      'version' => 1,
      'context' => [...],
      'steps' => [...],
      'provision' => [...],   // opcional: el setup de primera vez
  ];
  ```
- **El contexto** dice dónde corren los comandos:

  | `type` | Claves | Estado |
  | --- | --- | --- |
  | `shell` | ninguna | Implementado. Corre en el host, **y no en la carpeta del repo**: el directorio actual es el del runner. Los argv que dependan de la carpeta usan `{{ installation.path }}`. |
  | `docker_compose_exec` | `project_dir`, `service` y `workdir` obligatorias; `user` opcional | Implementado (tramo 4a). Cada paso corre como `env -C <project_dir> docker compose exec -T --workdir <workdir> -- <service> …`. Antes de tocar nada, Hermes verifica que el servicio esté corriendo y que su container monte esta installation. Sólo en el host local: el runner rechaza `transport: ssh`. |

- **`code`**: `mounted` (default, el checkout del host montado en el container) o `image` (el código copiado
  en la imagen, como Olympus). Con `image`: hace falta un `service.rebuild` no opcional —o tildado— que incluya
  el servicio del contexto, después del `git.pull` y antes de cualquier paso que corra en la app; no va
  `deps.install`; y `storage/` tiene que estar montado en un volumen o un bind, o el rebuild borra el
  `artisan down`.

- **Los pasos**: sólo estos tipos pueden aparecer en un `deploy.php`. Una clave de más o una
  obligatoria de menos invalida el manifiesto entero.

  | Tipo | Claves obligatorias | Opcionales | Dónde corre | Qué hace |
  | --- | --- | --- | --- | --- |
  | `maintenance.on` | — | — | app | `php artisan down --secret=…`. Sólo Laravel. El `maintenance.off` lo agrega Hermes solo |
  | `backup.create` | — | — | app | `php artisan backup:run`. Sólo Laravel, **uno solo por release** entre todos los repos del destino |
  | `git.pull` | — | — | host | `fetch` + `checkout -f` de la rama en la carpeta de la installation. Se revierte con `reset --hard` |
  | `deps.install` | — | `argv` | app | Sin `argv`: `composer install --no-dev --optimize-autoloader`. Un proyecto npm declara el suyo |
  | `db.migrate` | — | — | app | `php artisan migrate --force` (en Laravel con el deployer, usar `deploy:run`, ver 6.3) |
  | `db.seed` | `class` | — | app | `php artisan db:seed --force --class=…` |
  | `command` | `argv`, **`touches_db`** | `touches_storage` | app | Un argv arbitrario. `touches_db` es obligatorio aunque sea `false`: es lo que decide si al revertir se restaura la base |
  | `cache.warm` | — | — | app | Las cachés de Laravel (`config`, `route`, `view`, `event`) |
  | `build` | `argv` | `node_image` (default `node:22`) | runner | Corre **en el runner**, en un container efímero sobre su clon, antes de apagar nada |
  | `artifact.push` | `source`, `dest` | — | host | Sube lo que construyó `build` al destino. **Todavía no implementado** (tramo 4) |
  | `service.restart` | `services` (lista) | — | host | `docker compose restart` de esos servicios, con `env -C {{ target.infra_path }}`. Los nombres son los del `compose.yaml` |
  | `service.rebuild` | `services` (lista) | — | host | `docker compose build` + `up -d` de esos servicios. Opcional con key (p. ej. `rebuild`), tildado cuando cambió el Dockerfile. Va después de `git.pull` y antes de `deps.install` |
  | `unit.restart` | `units` (lista) | — | host | `systemctl --user restart` + `is-active` de esos units. Solo los de `allowed_units` del config del runner; `hermes-runner` nunca. Va inmediatamente después de `git.pull` cuando un daemon del host corre desde el checkout |
  | `git.clone` | — | — | host | Lo pone Hermes al aprovisionar: git clone --branch <rama> -- <repo> <carpeta>. Falla si la carpeta existe con contenido |
  | `env.place` | — | — | host | Lo pone Hermes al aprovisionar: deja el .env del formulario en <carpeta>/.env con modo 600 |

  Cualquier paso acepta además `timeout` (segundos), `optional => true` y `key`. Un paso opcional
  sólo entra al plan si se tilda su `key` al lanzar. `healthcheck`, `maintenance.off`,
  `backup.restore`, `git.clone` y `env.place` no se declaran: los pone Hermes.
- **Los argv son arrays, nunca un string de shell.** No hay `&&`, pipes, `cd` ni redirecciones: cada
  comando es un paso.
- **Las únicas variables** que se pueden usar en el contexto y en los argv son `{{ target.name }}`,
  `{{ target.infra_path }}`, `{{ installation.path }}` e `{{ installation.branch }}`.
- **La carpeta del servidor la crea Hermes al aprovisionar** (acción "Aprovisionar" de la portada): clona el repo, escribe el .env y corre la sección provision del deploy.php. La carpeta tiene que no existir o estar vacía, y caer dentro de allowed_roots del runner. provision acepta los mismos pasos que steps salvo maintenance.on y backup.create (no hay sitio que cerrar ni base que respaldar); los opcionales no corren. Sin provision, se aprovisiona igual: clon y .env. Un aprovisionamiento no se revierte.
- **El .env.example, comentado clave por clave, es el formulario de aprovisionar.** Cada clave es un campo; el bloque de comentarios inmediatamente arriba es su ayuda; un encabezado # ─── Grupo ─── (tres o más ─) abre un grupo. Una clave comentada (# CLAVE=valor) no es un campo. Las claves con PASSWORD, SECRET, KEY, TOKEN o PRIVATE en el nombre se enmascaran al editar el .env después. Sumá al ejemplo toda clave que haga falta completar al aprovisionar, COMPOSE_PROJECT_NAME incluida.
- **Dos copias del mismo repo en un host necesitan nombres de proyecto de Compose distintos** (`COMPOSE_PROJECT_NAME` en el `.env` de cada una). Con el mismo `name:`, desde la carpeta de una, `docker compose exec` entra al container de la otra. Hermes lo detecta y rechaza la release, pero la copia no se puede desplegar hasta corregirlo. Al aprovisionar, Hermes verifica con el .env ya escrito y antes del primer service.rebuild que ninguna otra carpeta tenga containers con ese nombre de proyecto, y si los tiene el aprovisionamiento falla con project_in_use sin tocar la otra copia. Completá un COMPOSE_PROJECT_NAME propio antes de confirmar para no llegar a ese fallo.
- **El checkout del servidor no se toca a mano.** `git.pull` hace `checkout -f`, así que un cambio
  local en un archivo versionado se pierde. Todo lo que se genera en el servidor (`.env`,
  `storage/`, `vendor/`, `node_modules/`, `public/build/`, `dist/`) va en el `.gitignore`.
- **Una ruta de salud** que devuelve 2xx cuando la app anda, alcanzable desde el host del runner y
  con el prefijo si lo hay. Se carga como "URL de salud" de la installation. Hermes la consulta al
  final, **dentro** de la ventana de mantenimiento, y si falla revierte la release. Sin ella, un
  deploy que deja la app en 500 cuenta como exitoso.
- **Versionado con gate de CI**:
  - El número vive en `config/app.php` (Laravel) o en `package.json` (npm).
  - Cada versión tiene su entrada `## vX.Y.Z` en `CHANGELOG.md`.
  - Un workflow `release` rechaza el PR a `main` si el número no subió o falta la entrada, y taggea
    `vX.Y.Z` al mergear.
  - En GitHub, marcar **`release / gate` como required status check** de `main`. Sin eso el gate se
    pone rojo pero no frena ningún merge.
- **Validar el manifiesto con el código del runner antes de dar la tarea por hecha.** Leerlo a ojo
  no alcanza. Con un clon de Hermes al lado:
  ```bash
  php -r '
  spl_autoload_register(function ($c) { $p = "Hermes\\Runner\\";
      if (str_starts_with($c, $p)) { require getenv("HERMES")."/runner/src/".str_replace("\\\\", "/", substr($c, strlen($p))).".php"; } });
  try { Hermes\Runner\Manifest::fromFile(getcwd()."/deploy.php"); echo "manifiesto válido\n"; }
  catch (Hermes\Runner\RunnerError $e) { echo "ERROR: ", $e->getMessage(), "\n"; exit(1); }'
  ```
  con `HERMES=/ruta/al/clon/de/Hermes`. Tiene que decir `manifiesto válido`.

### 6.3 Si es Laravel
- Instalar [hermes-laravel-deployer](https://github.com/MNONM-Software/Hermes-Laravel-Deployer) (no
  está en Packagist, va por VCS) y Spatie Backup:
  ```bash
  composer config repositories.hermes-deployer vcs https://github.com/MNONM-Software/Hermes-Laravel-Deployer
  composer require mnonm/hermes-laravel-deployer
  php artisan hermes:install
  composer require spatie/laravel-backup
  php artisan vendor:publish --provider="Spatie\Backup\BackupServiceProvider"
  php artisan migrate
  ```
- `hermes:install` publica el `deploy.php`, el workflow `release.yml`, `database/operations/`, un test
  de colisión de nombres y `config/hermes-deployer.php`. Después lista lo que queda a mano: la clave
  `'version' => '1.0.0'` en `config/app.php`, la ruta de salud en `routes/web.php` y el fragmento de
  `CLAUDE.md`. **Completarlos hasta que salga con código 0.**
- **La ruta de salud** es `config('hermes-deployer.health_path')` (`/health` por defecto,
  `HERMES_HEALTH_PATH` en `.env`). Devuelve la versión que corre de verdad. Con prefijo, la ruta lo
  lleva: `/ceo/health`.
- **El `deploy.php` que publica `hermes:install` es un punto de partida, no el final.** Ajustarlo al
  proyecto:
  - En el `context`, `service` y `workdir` tienen que coincidir con el `compose.yaml` del proyecto.
  - Las migraciones y los arreglos de datos van por
    `['type' => 'command', 'argv' => ['php', 'artisan', 'deploy:run'], 'touches_db' => true, 'timeout' => 1800]`,
    no por `db.migrate`.
  - Justo después de `deps.install`, borrar las cachés que dejó la release anterior:
    `['type' => 'command', 'argv' => ['php', 'artisan', 'optimize:clear', '--except=cache'], 'touches_db' => false]`.
    `cache.warm` corre al final, así que el deploy siguiente arranca con las rutas y la config
    viejas en caché: wayfinder genera las acciones con las rutas viejas y el build de Vite falla, y
    `deploy:run` corre con config vieja. `--except=cache` porque `cache:clear` vaciaría la caché
    de la app, que no es de la release.
  - Sumar el paso opcional de alta de instalación nueva, antes del `deploy:run`:
    `['type' => 'command', 'argv' => ['php', 'artisan', 'deploy:run', '--baseline'], 'touches_db' => true, 'timeout' => 1800, 'optional' => true, 'key' => 'fresh']`.
    Así el primer deploy de un cliente no corre años de backfills contra una base vacía.
  - Si el Dockerfile cambia entre releases, sumar un `service.rebuild` opcional (`'key' => 'rebuild'`)
    con los servicios que usan la imagen, después de `git.pull` y antes de `deps.install`:
    `composer install` corre dentro del container, y en el viejo instala contra la imagen vieja.
  - `service.restart` lleva **todos** los procesos PHP de larga vida (`php-fpm`, worker, scheduler,
    Horizon…). El que no se reinicia sigue corriendo el código viejo.
  - **nginx delante de php-fpm, en compose**: que resuelva el nombre del servicio en cada request,
    no una sola vez al arrancar. Al reiniciar varios servicios juntos, Docker puede repartirles las
    IPs en otro orden, y un `fastcgi_pass php-fpm:9000` fijo queda apuntando a otro container:
    502 hasta reiniciar nginx, el healthcheck falla y la release se revierte. En el `server`:
    ```nginx
    resolver 127.0.0.11 valid=5s ipv6=off;
    set $php_fpm php-fpm:9000;
    # y en el location de PHP:
    fastcgi_pass $php_fpm;
    ```
    Si la config de nginx vive en el repo montada en el container, `nginx` va también en el
    `service.restart`: si no, un cambio de esa config nunca llega con el deploy.
  - Si el proyecto compila assets con Vite y el código está montado desde el host, van como pasos
    `command` con `'touches_db' => false`: `npm ci` y después `npm run build`.
  - Si el contexto es `shell`, los argv propios apuntan al repo con `{{ installation.path }}`, por
    ejemplo `['php', '{{ installation.path }}/artisan', 'deploy:run']`.
- **Spatie Backup**, que es lo que corre `backup.create`:
  - Sólo la base, sin archivos.
  - Guardado en un disco local propio: `backups`, con raíz en `storage/app/backups`.
  - `backup:clean` agendado a diario en `routes/console.php`.
  - **El cliente de dump en la imagen de PHP**, de la misma versión mayor que la base
    (`postgresql-client-17`, `default-mysql-client`…). Sin él, `backup:run` falla y con él la
    release entera.
- Las reglas del fragmento de `CLAUDE.md` valen desde el primer commit:
  - Los arreglos de datos son operaciones en `database/operations/`, nunca migraciones.
  - Antes del PR a `main` se corre `php artisan deploy:release`.

### 6.4 Si es npm (SPA o server de node)
- No hay paquete: el versionado es `version` en `package.json`, más `CHANGELOG.md` y un
  `.github/workflows/release.yml` igual al de Laravel pero leyendo el número con
  `jq -r .version package.json`. El de `Artisano/Front` sirve de modelo.
- **No lleva `maintenance.on` ni `backup.create`**: los dos corren `php artisan`.
- **SPA estática servida por nginx**, contexto `shell`:
  ```php
  'steps' => [
      ['type' => 'git.pull'],
      ['type' => 'deps.install', 'argv' => ['npm', 'ci', '--prefix', '{{ installation.path }}']],
      ['type' => 'command', 'argv' => ['npm', '--prefix', '{{ installation.path }}', 'run', 'build'], 'touches_db' => false, 'timeout' => 600],
  ],
  ```
  nginx sirve el `dist/` nuevo sin reiniciar nada.
- La alternativa de compilar en el runner (`build`) y subir el resultado (`artifact.push`) es la que
  no deja el sitio a medio compilar, pero `artifact.push` es del tramo 4. Hasta entonces, compilar en
  el destino.
- **Server de node**: igual que la SPA, más
  `['type' => 'service.restart', 'services' => ['<servicio>']]` (o un `service.rebuild` opcional
  antes del `deps.install` si la imagen cambia), y una ruta `/health` que devuelva
  200 con la versión de `package.json`.
- **Ruta de salud de una SPA**: que el build emita un `version.json` con la versión de `package.json`,
  y usar esa URL. Pedir el `index.html` sólo prueba que nginx responde, no que el build nuevo quedó.

### 6.5 Checklist antes de dar el boilerplate por terminado
- [ ] Repo en `MNONM-Software`, pusheado.
- [ ] `deploy.php` en la raíz, validado con el código del runner (6.2).
- [ ] El contexto y los nombres de servicio coinciden con cómo corre de verdad en el servidor.
- [ ] Todo proceso de larga vida está en `service.restart`.
- [ ] nginx en compose resuelve el upstream con `resolver 127.0.0.11` y una variable (6.3).
- [ ] Laravel: `optimize:clear --except=cache` después de `deps.install` (6.3).
- [ ] Ruta de salud que devuelve 2xx, con el prefijo si lo hay.
- [ ] Versión, `CHANGELOG.md` y `release.yml`; `release / gate` marcado como required en GitHub.
- [ ] Laravel: `hermes:install` sale con código 0, Spatie Backup configurado y el cliente de dump en
      la imagen.
- [ ] `.gitignore` cubre todo lo que se genera en el servidor.
- [ ] Anotado en el README qué hay que cargar en Hermes:
  - **Proyecto**: nombre, identificador, URL del repo, rama por defecto y `deploy.php`.
  - **Destino**: carpeta donde está el `compose.yaml`, que es `target.infra_path`.
  - **Installation**: carpeta del checkout en el servidor, rama y URL de salud.

## 7. Seguridad desde el primer commit

### 7.1 Cualquier stack
- **Secretos sólo en variables de entorno.** `.env` en el `.gitignore` desde el primer commit; en el
  repo va sólo el `.env.example` sin valores reales. Nada secreto en el bundle del front: lo que lleva
  `VITE_*` o `NEXT_PUBLIC_*` lo puede leer cualquiera. Un secreto que llegó a Git se rota; borrarlo del
  historial no alcanza.
- **Escaneo de secretos en CI** (`gitleaks`) para frenar el PR que filtra uno.
- **Si el cliente habla directo con la base** (Supabase, Firebase): en el cliente va sólo la key
  pública, y cada tabla tiene RLS activado con sus policies. Una tabla sin RLS queda abierta.
- **Autenticación por defecto.** Toda ruta exige sesión; las públicas son una excepción explícita.
- **Autorización por registro.** Estar logueado no alcanza: cada lectura o escritura verifica que el
  usuario puede tocar *ese* registro.
- **Allowlist de campos escribibles.** `role`, `owner_id`, precios, saldos y flags de estado nunca se
  toman del request.
- **Validar inputs en el server.** La validación del front es UX, no seguridad.
- **Queries parametrizadas.** Nunca un string de SQL con valores interpolados.
- **Escapar el contenido del usuario.** Nunca renderizarlo como HTML crudo (`v-html`,
  `dangerouslySetInnerHTML`, `innerHTML`).
- **Devolver sólo lo necesario.** Las respuestas llevan los campos que usa la pantalla, nunca el
  registro entero (hashes, tokens, datos de otros usuarios).
- **Archivos subidos**: validar tipo y tamaño en el server, guardarlos fuera de la raíz pública y con
  nombre aleatorio; se sirven por una ruta que chequea permisos.
- **Rate limiting** en login, registro, reset de contraseña y en la API.
- **Bots**: si el proyecto tiene formularios públicos (registro, contacto), preguntar si se suma
  honeypot o captcha.
- **Datos sensibles encriptados en la base**: tokens de terceros, documentos, datos personales
  sensibles.
- **Contraseñas** con el hash del framework (bcrypt/argon2). Nunca uno propio.
- **Cookies de sesión** con `Secure`, `HttpOnly` y `SameSite`.
- **HTTPS forzado y security headers** en nginx:
  ```nginx
  add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
  add_header X-Content-Type-Options "nosniff" always;
  add_header X-Frame-Options "SAMEORIGIN" always;
  add_header Referrer-Policy "strict-origin-when-cross-origin" always;
  add_header Content-Security-Policy "default-src 'self'" always;  # ajustar al proyecto
  ```
- **Auditar dependencias en CI** (`npm audit`, `composer audit`…), en `security.yml`.
- **Crear los workflows de GitHub Actions desde el primer commit**, en `.github/workflows/`:
  - `ci.yml`: en cada PR y push a `main`, instala dependencias y corre lint, análisis estático y
    tests del stack (Pint/PHPStan/Pest en Laravel, ESLint/tsc/Vitest en npm).
  - `security.yml`: `gitleaks`, el audit de dependencias y, si hay Dockerfile, `trivy` sobre la imagen
    construida (7.3).
  - `release.yml`: el gate de versión y el tag, si el proyecto usa Hermes (6.2).
  - Cada job que tenga que frenar un merge se marca como **required status check** de `main`.

### 7.2 Si es Laravel
- Las rutas van dentro de un grupo con middleware `auth`; las públicas, afuera y a propósito.
- Una Policy por modelo y `Gate::authorize(...)` / `$this->authorize(...)` en cada acción.
- `$fillable` explícito en cada modelo, nunca `$guarded = []`. Guardar con `$request->validated()`,
  nunca con `$request->all()`.
- Un FormRequest por endpoint que escribe.
- Nada de `whereRaw`, `DB::raw` ni `selectRaw` con valores interpolados: bindings (`?`) siempre.
- En Blade, `{{ }}`. `{!! !!}` sólo con contenido que no viene del usuario.
- Respuestas de API con API Resources, y `$hidden` en el modelo para lo que nunca sale.
- Uploads con reglas `file`, `mimes` y `max`, en el disco `local` (no `public`) y con `store()`, que
  genera el nombre.
- `throttle` en las rutas de auth y de API (`RateLimiter::for` en `AppServiceProvider`).
- Cast `encrypted` para los datos sensibles y cast `hashed` para las contraseñas.
- En el `.env.example`: `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`, `SESSION_SAME_SITE=lax`.
- Si TLS termina en un proxy, configurar `trustProxies` en `bootstrap/app.php`. Si no, Laravel arma
  las URLs con `http://` y las cookies `Secure` no viajan.
- `composer audit` (y `npm audit --audit-level=high` si hay front) como paso de `security.yml`.

### 7.3 Si corre en Docker
- **Sólo nginx publica puertos.** La base, Redis y php-fpm van sin `ports:`, en la red interna de
  compose. Si hace falta llegar desde el host, publicar en loopback: `127.0.0.1:5432:5432`. Docker
  escribe sus propias reglas de iptables y **se saltea `ufw`**: un `5432:5432` queda abierto a
  internet aunque el firewall diga que no.
- **Imágenes con versión fijada al patch**, oficiales o slim (`postgres:17.2-alpine`,
  `php:8.4.27-fpm-bookworm`), nunca `latest` ni un tag de rama: un rebuild no puede traer otra versión
  sin que nadie lo decida (8.2).
- **Multi-stage build.** Composer, node y las herramientas de compilación quedan en la etapa de build;
  la imagen final lleva sólo lo que corre.
- **Ningún secreto en la imagen.** Nada en `ENV` ni `ARG` del Dockerfile (quedan en las capas y en
  `docker history`); el `.env` entra en runtime. `.dockerignore` con `.env`, `.git`, `node_modules`,
  `vendor` y `storage`.
- **Usuario no root.** `USER` en el Dockerfile. Con `code: mounted`, el UID/GID del usuario del
  container tiene que coincidir con el dueño del checkout en el host (build args `UID`/`GID`); si no,
  `storage/` y `bootstrap/cache/` fallan por permisos.
- **Sin privilegios de más.** Nunca `privileged: true`, nunca montar `/var/run/docker.sock`, nunca
  `network_mode: host`. En todos los servicios:
  ```yaml
  security_opt:
    - no-new-privileges:true
  ```
- **Credenciales propias en cada servicio.** Usuario y contraseña de la base que no sean los default,
  `requirepass` en Redis; todo desde el `.env`, con su clave en el `.env.example`.
- **Límites por servicio**, para que uno desbocado no tumbe el host ni llene el disco:
  ```yaml
  mem_limit: 512m
  logging:
    driver: json-file
    options:
      max-size: "10m"
      max-file: "3"
  ```
- **Escanear la imagen en CI** con `trivy image` (falla con vulnerabilidades `HIGH`/`CRITICAL`
  arreglables: `--severity HIGH,CRITICAL --ignore-unfixed --exit-code 1`).

### 7.4 Checklist de seguridad
- [ ] `.env` ignorado, `.env.example` sin valores reales y `gitleaks` en CI.
- [ ] Ningún secreto en variables públicas del front.
- [ ] Rutas autenticadas por defecto y autorización por registro (Policies / RLS).
- [ ] Allowlist de campos escribibles y validación de inputs en el server.
- [ ] Rate limiting en auth y API.
- [ ] Security headers y HTTPS en nginx.
- [ ] Audit de dependencias en `security.yml`.
- [ ] Workflows `ci.yml` y `security.yml` (y `release.yml` con Hermes) creados y marcados como
      required en `main`.
- [ ] Docker: sólo nginx publica puertos; nada en `0.0.0.0` salvo 80/443.
- [ ] Docker: imágenes con versión fijada, usuario no root, `no-new-privileges` y sin secretos en la
      imagen.
- [ ] Docker: `mem_limit`, rotación de logs y `trivy` en CI.

## 8. Seguridad operativa y preparación para incidentes

Estas reglas salen de la respuesta a PHP2Shell (CVE-2026-109618/109619, octubre 2026). Aplican a todo
proyecto nuevo; en un proyecto existente, **señalar** cada una que no cumpla.

### 8.1 Antes de empezar: vulnerabilidades vigentes
- **Al crear el proyecto, buscar en internet las vulnerabilidades actuales** del stack elegido:
  runtime (PHP, Node), framework, imagen base, servidor web y dependencias principales. Fuentes: los
  advisories oficiales del proyecto, GitHub Security Advisories, NVD y el changelog de seguridad de
  cada uno.
- Con lo que aparezca: elegir versiones que ya traigan el parche, aplicar las mitigaciones que
  correspondan desde el primer commit y anotarlo en `docs/SECURITY-BASELINE.md` (fecha de la
  búsqueda, CVE, versión afectada y qué se hizo).
- Si la búsqueda no se puede hacer, **avisar** antes de seguir; no arrancar asumiendo que no hay nada.

### 8.2 Versión del runtime
- **PHP (y cualquier runtime o imagen base) fijado a un patch exacto**, nunca a un tag flotante:
  `php:8.4.27-fpm-bookworm`, no `php:8.4-fpm` ni `latest`. Si el tag no existe, el build falla; no
  construye en silencio una versión vieja.
- **La versión se define en un solo lugar**: un `ARG` en el Dockerfile, con su default en el
  `compose.yaml` y en el `.env.example`, y documentada junto a la fecha de EOL de la rama.
  ```dockerfile
  ARG PHP_VERSION=8.4.27
  FROM php:${PHP_VERSION}-fpm-bookworm
  ```
- **`scripts/verify-runtime-version.sh`**: corre la imagen con `--network none` y falla si la versión
  está por debajo del mínimo. Se llama en `ci.yml` y en cada deploy.
- **Imágenes oficiales como base.** Nada de imágenes de terceros (tipo Laradock) en producción.
- **Ninguna rama de PHP sin soporte.** Si un proyecto hereda una, se abre un ticket de upgrade con
  fecha.

### 8.3 Configuración de PHP
- Todo `.ini` de producción lleva:
  ```ini
  session.upload_progress.enabled = Off  ; Laravel no lo usa
  expose_php = Off
  display_errors = Off
  ```
- **Los `.ini` se montan desde el repo de infra en `:ro`**, no se hornean en la imagen: así una
  mitigación se aplica con un restart y sin rebuild.
  ```yaml
  volumes:
    - ./php/production.ini:/usr/local/etc/php/conf.d/zz-production.ini:ro
  ```

### 8.4 Red y exposición
- Servicios internos (php-fpm, MySQL, Redis): `expose`, nunca `ports`. Si hace falta llegar desde el
  host, `127.0.0.1:PUERTO:PUERTO` (7.3).
- **Docker se saltea `ufw`: el control real es el Cloud Firewall del proveedor.** El repo documenta qué
  puertos tienen que estar abiertos y por qué.
- **Nada de contenedores de desarrollo** (workspace, docker-in-docker, servidores de dev, phpMyAdmin)
  en el compose de producción.

### 8.5 Docker Compose
- **Si el compose usa `profiles`, `COMPOSE_PROFILES` va fijado en el `.env` del server**, así `down`,
  `stop` y `up` cubren todos los servicios. Un `down` sin el profile deja vivos los servicios de ese
  profile.
- **Logs persistentes.** Los de nginx, PHP y Laravel sobreviven a un `docker compose down`: en un
  volumen, en un archivo en el host o en un servicio externo. La evidencia forense no puede depender de
  que el container siga existiendo.
- **Laravel loguea a archivo** (`LOG_STACK=single` o `daily`), no sólo a stderr.

### 8.6 Deploy
- **Con `git.pull`, todo lo commiteado llega a producción.** Por eso: ningún archivo del repo
  (`.env.testing`, `.env.ci`, `.mcp.json`) lleva secretos reales, el `root` de nginx apunta a
  `public/` (o a `dist/` en una SPA) y las dependencias se instalan con `--no-dev`.
- **Las herramientas de deploy (Hermes y similares) son tan críticas como producción:**
  - Clave SSH o deploy key por destino.
  - Usuario de deploy sin root y sin grupo `docker` si no hace falta.
  - Cada deploy notificado a un canal.
- El `.gitignore` del repo de infra excluye certificados y claves (`*.key`, `*.crt`, `*.pem`).

### 8.7 Secretos
- **`docs/SECRETS.md`, sin valores**, con una entrada por secreto:
  - Nombre de la variable y archivo `.env` donde vive.
  - Si está compartido con otro servicio (el mismo valor en dos lugares).
  - Dónde se rota (panel o comando).
  - Qué se rompe mientras se rota.
- Los secretos se generan con `openssl rand -hex 24` (o `32` para tokens).
- **`APP_PREVIOUS_KEYS` sólo si hay datos cifrados guardados** (casts `encrypted`, `Crypt::`). Si no
  los hay, rotar `APP_KEY` es reemplazarla y nada más.
- **Después de cambiar un `.env` en producción, siempre `php artisan config:cache`.**
  `bootstrap/cache/config.php` guarda los secretos en texto plano y tiene prioridad sobre el `.env`.
- Si MySQL corre en Docker y el healthcheck usa `MYSQL_ROOT_PASSWORD`, rotar root exige recrear el
  container.

### 8.8 Preparación para incidentes
- **Todo proyecto con producción tiene `docs/incident-response.md`** con:
  - Cómo contener: firewall, apagado sin `down`, snapshot.
  - Cómo guardar evidencia: `docker logs`, `docker diff`, `docker top`, `git status` y
    `find -newerct` sobre el código montado.
  - Orden de rotación de secretos, con referencia a `docs/SECRETS.md`.
  - Cómo verificar el parche antes de abrir tráfico.
- **Documentado (sin el valor) dónde está la contraseña de sudo de cada server**, para no descubrir
  en medio de un incidente que nadie la tiene.
- **Inventario de servers y proyectos** con su runtime, su versión y la fecha de EOL de la rama.

### 8.9 Checklist operativo
- [ ] Búsqueda de vulnerabilidades vigentes hecha y anotada en `docs/SECURITY-BASELINE.md`.
- [ ] Runtime e imagen base fijados a un patch exacto, en un único `ARG`, con su EOL documentado.
- [ ] `scripts/verify-runtime-version.sh` en CI y en el deploy.
- [ ] `.ini` de producción montado en `:ro` con `session.upload_progress.enabled`, `expose_php` y
      `display_errors` en `Off`.
- [ ] Puertos del Cloud Firewall documentados; ningún container de desarrollo en producción.
- [ ] `COMPOSE_PROFILES` en el `.env` del server si hay profiles.
- [ ] Logs de nginx, PHP y Laravel que sobreviven a un `down`; Laravel loguea a archivo.
- [ ] Ningún secreto real commiteado (`.env.testing`, `.env.ci`, `.mcp.json`); `root` de nginx en
      `public/` o `dist/`; dependencias con `--no-dev`.
- [ ] Deploy key por destino, usuario de deploy sin root y deploys notificados.
- [ ] `docs/SECRETS.md` y `docs/incident-response.md` escritos.
- [ ] Ubicación de la contraseña de sudo documentada y proyecto cargado en el inventario.
