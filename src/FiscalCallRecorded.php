<?php

namespace Mnonm\FiscalAdapter;

class FiscalCallRecorded
{
    public function __construct(public readonly CallRecord $record) {}
}
