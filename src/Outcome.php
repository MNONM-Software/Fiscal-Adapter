<?php

namespace Mnonm\FiscalAdapter;

use InvalidArgumentException;

final readonly class Outcome
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<Reason>  $reasons
     */
    private function __construct(
        public OutcomeStatus $status,
        public ?string $authorityId = null,
        public array $data = [],
        public array $reasons = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<Reason>  $reasons
     */
    public static function authorized(string $authorityId, array $data = [], array $reasons = []): self
    {
        return new self(OutcomeStatus::Authorized, $authorityId, $data, $reasons);
    }

    /**
     * @param  list<Reason>  $reasons
     */
    public static function rejected(array $reasons): self
    {
        if ($reasons === []) {
            throw new InvalidArgumentException('Un rechazo debe traer al menos un motivo.');
        }

        return new self(OutcomeStatus::Rejected, null, [], $reasons);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function pending(?string $authorityId = null, array $data = []): self
    {
        return new self(OutcomeStatus::Pending, $authorityId, $data);
    }

    public static function unavailable(Reason $reason): self
    {
        return new self(OutcomeStatus::Unavailable, null, [], [$reason]);
    }

    public static function unknown(Reason $reason): self
    {
        return new self(OutcomeStatus::Unknown, null, [], [$reason]);
    }

    public function isAuthorized(): bool
    {
        return $this->status === OutcomeStatus::Authorized;
    }

    public function isRejected(): bool
    {
        return $this->status === OutcomeStatus::Rejected;
    }

    public function isPending(): bool
    {
        return $this->status === OutcomeStatus::Pending;
    }

    public function isUnavailable(): bool
    {
        return $this->status === OutcomeStatus::Unavailable;
    }

    public function isUnknown(): bool
    {
        return $this->status === OutcomeStatus::Unknown;
    }

    /**
     * @return list<string>
     */
    public function reasonCodes(): array
    {
        return array_map(fn (Reason $reason): string => $reason->code, $this->reasons);
    }
}
