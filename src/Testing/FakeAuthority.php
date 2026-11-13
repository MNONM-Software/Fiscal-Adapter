<?php

namespace Mnonm\FiscalAdapter\Testing;

use Closure;
use LogicException;
use Mnonm\FiscalAdapter\CallContext;
use Mnonm\FiscalAdapter\Contracts\Cancellation;
use Mnonm\FiscalAdapter\Contracts\FiscalAuthority;
use Mnonm\FiscalAdapter\Contracts\Issuable;
use Mnonm\FiscalAdapter\Contracts\Query;
use Mnonm\FiscalAdapter\Outcome;
use PHPUnit\Framework\AssertionFailedError;

final class FakeAuthority implements FiscalAuthority
{
    /** @var array<class-string, Outcome|Closure(object, CallContext): Outcome> */
    private array $responses = [];

    /** @var list<array{operation: string, context: CallContext, document: object}> */
    private array $sent = [];

    private int $counter = 0;

    public function __construct(private readonly string $name = 'fake') {}

    public function name(): string
    {
        return $this->name;
    }

    public function supports(object $document): bool
    {
        return true;
    }

    /**
     * @param  class-string  $documentClass
     * @param  Outcome|Closure(object, CallContext): Outcome  $response
     */
    public function respondWith(string $documentClass, Outcome|Closure $response): self
    {
        $this->responses[$documentClass] = $response;

        return $this;
    }

    public function issue(CallContext $context, Issuable $document): Outcome
    {
        return $this->handle('issue', $context, $document);
    }

    public function cancel(CallContext $context, Cancellation $cancellation): Outcome
    {
        return $this->handle('cancel', $context, $cancellation);
    }

    public function query(CallContext $context, Query $query): Outcome
    {
        return $this->handle('query', $context, $query);
    }

    public function reconcile(CallContext $context, Issuable|Cancellation $document): Outcome
    {
        return $this->handle('reconcile', $context, $document);
    }

    /**
     * @param  (Closure(object, CallContext): bool)|null  $filter
     */
    public function assertIssued(?Closure $filter = null): void
    {
        $this->assertSent('issue', $filter);
    }

    /**
     * @param  (Closure(object, CallContext): bool)|null  $filter
     */
    public function assertCancelled(?Closure $filter = null): void
    {
        $this->assertSent('cancel', $filter);
    }

    public function assertNothingSent(): void
    {
        if ($this->sent !== []) {
            $this->fail('Se esperaba que no se enviara nada, pero hubo '.count($this->sent).' envío(s).');
        }
    }

    /**
     * @return list<array{operation: string, context: CallContext, document: object}>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    private function handle(string $operation, CallContext $context, object $document): Outcome
    {
        $this->sent[] = ['operation' => $operation, 'context' => $context, 'document' => $document];

        $response = $this->responses[$document::class] ?? null;

        if ($response instanceof Closure) {
            return $response($document, $context);
        }

        return $response ?? Outcome::authorized('FAKE-'.++$this->counter);
    }

    /**
     * @param  (Closure(object, CallContext): bool)|null  $filter
     */
    private function assertSent(string $operation, ?Closure $filter): void
    {
        foreach ($this->sent as $entry) {
            if ($entry['operation'] === $operation && ($filter === null || $filter($entry['document'], $entry['context']))) {
                return;
            }
        }

        $this->fail("Se esperaba un envío de tipo «{$operation}» que no ocurrió.");
    }

    private function fail(string $message): never
    {
        if (class_exists(AssertionFailedError::class)) {
            throw new AssertionFailedError($message);
        }

        throw new LogicException($message);
    }
}
