<?php

namespace Mnonm\FiscalAdapter;

interface CallObserver
{
    public function record(CallRecord $record): void;
}
