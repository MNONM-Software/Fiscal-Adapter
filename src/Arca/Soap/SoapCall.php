<?php

namespace Mnonm\FiscalAdapter\Arca\Soap;

use Mnonm\FiscalAdapter\Environment;

final readonly class SoapCall
{
    public function __construct(
        public string $service,
        public string $method,
        public string $url,
        public string $soapAction,
        public string $envelope,
        public Environment $environment,
        public ?string $representedTaxId = null,
        public ?string $reference = null,
    ) {}
}
