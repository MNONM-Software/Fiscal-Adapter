<?php

namespace Mnonm\FiscalAdapter\Arca\Wscpe;

use Carbon\CarbonImmutable;
use Mnonm\FiscalAdapter\Reason;

/**
 * Lectura de las respuestas de WSCPE (autorizar, anular y consultar comparten forma).
 */
final class WaybillResult
{
    /**
     * No lanza: sin `<respuesta>` devuelve null y quien llama lo convierte en `unknown`.
     *
     * @return array{respuesta: ?\SimpleXMLElement, errors: list<Reason>}
     */
    public static function fromResponse(string $xml): array
    {
        $document = @simplexml_load_string($xml);
        $found = $document === false ? [] : $document->xpath('//*[local-name()="respuesta"]');
        $respuesta = $found[0] ?? null;

        $errors = [];
        foreach ($respuesta?->errores->error ?? [] as $error) {
            $errors[] = new Reason(trim((string) $error->codigo), trim((string) $error->descripcion));
        }

        return ['respuesta' => $respuesta, 'errors' => $errors];
    }

    /**
     * Lo que importa de una carta de porte. Chofer, patentes y bruto la identifican; la respuesta de
     * anular no los trae y quedan en null. Las fechas vienen sin offset, en hora argentina.
     *
     * @return array{ctg: ?string, state: ?string, issued_at: ?CarbonImmutable, expires_at: ?CarbonImmutable, pdf: ?string, driver_cuit: ?string, plates: ?list<string>, gross_kg: ?int}
     */
    public static function data(\SimpleXMLElement $respuesta): array
    {
        $value = fn (?\SimpleXMLElement $node): ?string => $node === null || trim((string) $node) === '' ? null : trim((string) $node);

        $plates = [];
        foreach ($respuesta->transporte->dominio ?? [] as $plate) {
            $plates[] = trim((string) $plate);
        }
        $grossKg = $value($respuesta->datosCarga->pesoBruto ?? null);

        return [
            'ctg' => $value($respuesta->cabecera->nroCTG ?? null),
            'state' => $value($respuesta->cabecera->estado ?? null),
            'issued_at' => self::date($value($respuesta->cabecera->fechaEmision ?? null)),
            'expires_at' => self::date($value($respuesta->cabecera->fechaVencimiento ?? null)),
            'pdf' => $value($respuesta->pdf ?? null),
            'driver_cuit' => $value($respuesta->transporte->cuitChofer ?? null),
            'plates' => $plates !== [] ? $plates : null,
            'gross_kg' => $grossKg !== null ? (int) $grossKg : null,
        ];
    }

    private static function date(?string $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, WscpeXml::TIMEZONE);
        } catch (\Throwable) {
            return null;
        }
    }
}
