<?php

namespace Mnonm\FiscalAdapter\Arca\Wsaa;

use Mnonm\FiscalAdapter\Environment;

/**
 * Identifica un ticket: certificado (por huella), servicio y ambiente.
 */
final readonly class TicketKey
{
    public function __construct(
        public string $credentialFingerprint,
        public string $service,
        public Environment $environment,
    ) {}

    public function toString(): string
    {
        return "{$this->credentialFingerprint}:{$this->service}:{$this->environment->value}";
    }
}
