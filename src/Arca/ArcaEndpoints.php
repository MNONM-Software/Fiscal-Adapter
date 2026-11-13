<?php

namespace Mnonm\FiscalAdapter\Arca;

use Mnonm\FiscalAdapter\Environment;
use Mnonm\FiscalAdapter\Exceptions\MissingEndpoint;

/**
 * URLs de ARCA por ambiente y servicio. Los overrides pisan los defaults y pueden sumar servicios.
 */
final class ArcaEndpoints
{
    private const DEFAULTS = [
        'homologation' => [
            'wsaa' => 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms',
            'wscpe' => 'https://cpea-ws-qaext.afip.gob.ar/wscpe/services/soap',
        ],
        'production' => [
            'wsaa' => 'https://wsaa.afip.gov.ar/ws/services/LoginCms',
            'wscpe' => 'https://cpea-ws.afip.gob.ar/wscpe/services/soap',
        ],
    ];

    /**
     * @param  array<string, array<string, string>>  $overrides  ambiente => servicio => URL
     */
    public function __construct(private readonly array $overrides = []) {}

    /**
     * @throws MissingEndpoint
     */
    public function url(string $service, Environment $environment): string
    {
        $url = $this->overrides[$environment->value][$service] ?? self::DEFAULTS[$environment->value][$service] ?? null;

        if ($url === null || $url === '') {
            throw new MissingEndpoint("No hay URL configurada para el servicio {$service} de ARCA en el ambiente {$environment->value}.");
        }

        return $url;
    }
}
