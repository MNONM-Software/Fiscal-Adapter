<?php

namespace Mnonm\FiscalAdapter\Contracts;

use Mnonm\FiscalAdapter\CallContext;
use Mnonm\FiscalAdapter\Outcome;

interface FiscalAuthority
{
    public function name(): string;

    public function supports(object $document): bool;

    public function issue(CallContext $context, Issuable $document): Outcome;

    public function cancel(CallContext $context, Cancellation $cancellation): Outcome;

    public function query(CallContext $context, Query $query): Outcome;

    /**
     * Asks the authority what happened to a document whose result was never received.
     *
     * Never returns `Unknown` for not finding it: if the authority confirms it is not there,
     * the result is `Unavailable`.
     */
    public function reconcile(CallContext $context, Issuable|Cancellation $document): Outcome;
}
