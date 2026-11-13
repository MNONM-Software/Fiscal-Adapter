<?php

namespace Mnonm\FiscalAdapter;

class EventCallObserver implements CallObserver
{
    public function record(CallRecord $record): void
    {
        event(new FiscalCallRecorded($record));
    }
}
