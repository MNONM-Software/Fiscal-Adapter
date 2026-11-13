<?php

namespace Mnonm\FiscalAdapter;

final readonly class CallContext
{
    public function __construct(
        public Credential $credential,
        public string $representedTaxId,
        public Environment $environment,
    ) {}
}
