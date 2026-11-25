<?php

namespace Mnonm\FiscalAdapter;

final readonly class CallContext
{
    /**
     * @param  string|null  $reference  Opaque id of the caller (for example, its own request id). The
     *                                  library does not read it: it copies it to every `CallRecord` of
     *                                  the calls this context triggers, so the caller can tie them back.
     */
    public function __construct(
        public Credential $credential,
        public string $representedTaxId,
        public Environment $environment,
        public ?string $reference = null,
    ) {}
}
