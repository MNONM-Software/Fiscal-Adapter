<?php

namespace Mnonm\FiscalAdapter;

use Carbon\CarbonImmutable;

final readonly class CallRecord
{
    public function __construct(
        public string $authority,
        public string $service,
        public string $method,
        public Environment $environment,
        public string $url,
        public ?string $representedTaxId,
        public string $request,
        public ?string $response,
        public ?int $httpStatus,
        public int $durationMs,
        public ?string $error,
        public CarbonImmutable $occurredAt,
        public ?string $reference = null,
    ) {}
}
