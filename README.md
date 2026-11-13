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

```php
// Se completa en la Task 11, cuando existan el manager y la fachada.
```

## Desarrollo

Ver `CLAUDE.md`. Resumen: `composer test` (pest + pint + phpstan), corrido dentro de la imagen
`Dockerfile.test`.
