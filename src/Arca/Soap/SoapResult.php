<?php

namespace Mnonm\FiscalAdapter\Arca\Soap;

use Mnonm\FiscalAdapter\Outcome;

final readonly class SoapResult
{
    private function __construct(public ?string $body, public ?Outcome $failure) {}

    public static function ok(string $body): self
    {
        return new self($body, null);
    }

    public static function failed(Outcome $failure): self
    {
        return new self(null, $failure);
    }

    public function succeeded(): bool
    {
        return $this->failure === null;
    }
}
