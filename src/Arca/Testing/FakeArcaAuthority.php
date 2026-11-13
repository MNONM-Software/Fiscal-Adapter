<?php

namespace Mnonm\FiscalAdapter\Arca\Testing;

use Closure;
use InvalidArgumentException;
use LogicException;
use Mnonm\FiscalAdapter\Arca\ArcaWaybillAuthority;
use Mnonm\FiscalAdapter\Arca\Wscpe\ArcaWaybill;
use Mnonm\FiscalAdapter\CallContext;
use Mnonm\FiscalAdapter\Contracts\Cancellation;
use Mnonm\FiscalAdapter\Contracts\Issuable;
use Mnonm\FiscalAdapter\Contracts\Query;
use Mnonm\FiscalAdapter\Outcome;
use Mnonm\FiscalAdapter\Testing\FakeAuthority;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Lo que devuelve `Fiscal::fake()`: el {@see FakeAuthority} genérico (respuestas por documento,
 * `assertIssued`, `assertNothingSent`...) más los métodos propios del adaptador ARCA, para poder
 * testear el flujo completo (último número de orden → emitir) sin red.
 *
 * Sin respuesta programada: `lastOrderNumber` devuelve `Authorized` con 0 y los catálogos
 * `Authorized` sin ítems.
 */
final class FakeArcaAuthority implements ArcaWaybillAuthority
{
    /** Métodos del adaptador que devuelven un desenlace programable, con su método WSCPE. */
    private const PROGRAMMABLE = [
        'lastOrderNumber' => 'consultarUltNroOrden',
        'provinces' => 'consultarProvincias',
        'localities' => 'consultarLocalidadesPorProvincia',
        'grainTypes' => 'consultarTiposGrano',
    ];

    private readonly FakeAuthority $documents;

    /** @var array<string, Outcome|Closure(CallContext, int...): Outcome> */
    private array $methodResponses = [];

    /** @var list<array{method: string, context: CallContext, arguments: list<int>}> */
    private array $methodCalls = [];

    public function __construct(string $name = 'fake')
    {
        $this->documents = new FakeAuthority($name);
    }

    /**
     * @param  class-string  $documentClass
     * @param  Outcome|Closure(object, CallContext): Outcome  $response
     */
    public function respondWith(string $documentClass, Outcome|Closure $response): self
    {
        $this->documents->respondWith($documentClass, $response);

        return $this;
    }

    /**
     * Programa la respuesta de `lastOrderNumber`, `provinces`, `localities` o `grainTypes`. El closure
     * recibe el contexto y los argumentos enteros del método (sucursal y tipo; código de provincia).
     *
     * @param  Outcome|Closure(CallContext, int...): Outcome  $response
     *
     * @throws InvalidArgumentException
     */
    public function respondToMethod(string $method, Outcome|Closure $response): self
    {
        if (! array_key_exists($method, self::PROGRAMMABLE)) {
            throw new InvalidArgumentException("El fake de ARCA no programa «{$method}»; acepta: ".implode(', ', array_keys(self::PROGRAMMABLE)).'.');
        }

        $this->methodResponses[$method] = $response;

        return $this;
    }

    public function name(): string
    {
        return $this->documents->name();
    }

    public function supports(object $document): bool
    {
        return $this->documents->supports($document);
    }

    public function issue(CallContext $context, Issuable $document): Outcome
    {
        return $this->documents->issue($context, $document);
    }

    public function cancel(CallContext $context, Cancellation $cancellation): Outcome
    {
        return $this->documents->cancel($context, $cancellation);
    }

    public function query(CallContext $context, Query $query): Outcome
    {
        return $this->documents->query($context, $query);
    }

    public function reconcile(CallContext $context, Issuable|Cancellation $document): Outcome
    {
        return $this->documents->reconcile($context, $document);
    }

    public function lastOrderNumber(CallContext $context, int $branch, int $cpeType = ArcaWaybill::AUTOMOTOR): Outcome
    {
        return $this->answer('lastOrderNumber', $context, [$branch, $cpeType], fn (): Outcome => Outcome::authorized('0', ['last_order_number' => 0]));
    }

    public function provinces(CallContext $context): Outcome
    {
        return $this->answer('provinces', $context, [], fn (): Outcome => $this->emptyCatalog('provinces'));
    }

    public function localities(CallContext $context, int $provinceCode): Outcome
    {
        return $this->answer('localities', $context, [$provinceCode], fn (): Outcome => $this->emptyCatalog('localities'));
    }

    public function grainTypes(CallContext $context): Outcome
    {
        return $this->answer('grainTypes', $context, [], fn (): Outcome => $this->emptyCatalog('grainTypes'));
    }

    public function forgetTicket(CallContext $context): void
    {
        $this->methodCalls[] = ['method' => 'forgetTicket', 'context' => $context, 'arguments' => []];
    }

    /**
     * @param  (Closure(object, CallContext): bool)|null  $filter
     */
    public function assertIssued(?Closure $filter = null): void
    {
        $this->documents->assertIssued($filter);
    }

    /**
     * @param  (Closure(object, CallContext): bool)|null  $filter
     */
    public function assertCancelled(?Closure $filter = null): void
    {
        $this->documents->assertCancelled($filter);
    }

    /**
     * @param  (Closure(CallContext, int...): bool)|null  $filter  recibe el contexto y los argumentos enteros
     */
    public function assertMethodCalled(string $method, ?Closure $filter = null): void
    {
        foreach ($this->methodCalls as $call) {
            if ($call['method'] === $method && ($filter === null || $filter($call['context'], ...$call['arguments']))) {
                return;
            }
        }

        $this->fail("Se esperaba una llamada a «{$method}» que no ocurrió.");
    }

    /**
     * Ningún documento enviado ni consulta del adaptador hecha. Descartar el ticket no cuenta: no
     * habla con el organismo.
     */
    public function assertNothingSent(): void
    {
        $this->documents->assertNothingSent();

        $lookups = array_filter($this->methodCalls, fn (array $call): bool => $call['method'] !== 'forgetTicket');

        if ($lookups !== []) {
            $this->fail('Se esperaba que no se enviara nada, pero hubo '.count($lookups).' consulta(s) del adaptador.');
        }
    }

    /**
     * @return list<array{operation: string, context: CallContext, document: object}>
     */
    public function sent(): array
    {
        return $this->documents->sent();
    }

    /**
     * @return list<array{method: string, context: CallContext, arguments: list<int>}>
     */
    public function methodCalls(): array
    {
        return $this->methodCalls;
    }

    /**
     * @param  list<int>  $arguments
     * @param  Closure(): Outcome  $default
     */
    private function answer(string $method, CallContext $context, array $arguments, Closure $default): Outcome
    {
        $this->methodCalls[] = ['method' => $method, 'context' => $context, 'arguments' => $arguments];

        $response = $this->methodResponses[$method] ?? null;

        if ($response instanceof Closure) {
            return $response($context, ...$arguments);
        }

        return $response ?? $default();
    }

    private function emptyCatalog(string $method): Outcome
    {
        return Outcome::authorized(self::PROGRAMMABLE[$method], ['items' => []]);
    }

    private function fail(string $message): never
    {
        if (class_exists(AssertionFailedError::class)) {
            throw new AssertionFailedError($message);
        }

        throw new LogicException($message);
    }
}
