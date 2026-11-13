<?php

namespace Mnonm\FiscalAdapter\Arca\Wsaa;

use Carbon\CarbonImmutable;

/**
 * Ticket de acceso de WSAA para un servicio: token y firma que van en cada llamada.
 */
final readonly class AccessTicket
{
    public function __construct(
        #[\SensitiveParameter] public string $token,
        #[\SensitiveParameter] public string $sign,
        public CarbonImmutable $expiresAt,
    ) {}

    public function isUsable(int $marginSeconds = 300): bool
    {
        return $this->expiresAt->greaterThan(now()->toImmutable()->addSeconds($marginSeconds));
    }

    /**
     * @return array{expiresAt: string}
     */
    public function __debugInfo(): array
    {
        return ['expiresAt' => $this->expiresAt->toIso8601String()];
    }
}
