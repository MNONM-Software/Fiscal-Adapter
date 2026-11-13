<?php

namespace Mnonm\FiscalAdapter;

final readonly class Reason
{
    public function __construct(public string $code, public string $message) {}
}
