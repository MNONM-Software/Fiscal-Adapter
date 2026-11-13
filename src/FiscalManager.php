<?php

namespace Mnonm\FiscalAdapter;

use Closure;
use Mnonm\FiscalAdapter\Contracts\FiscalAuthority;
use Mnonm\FiscalAdapter\Exceptions\UnknownAuthority;

final class FiscalManager
{
    /** @var array<string, FiscalAuthority> */
    private array $resolved = [];

    private ?FiscalAuthority $forced = null;

    /**
     * @param  array<string, Closure(): FiscalAuthority>  $factories
     */
    public function __construct(private array $factories = []) {}

    /**
     * A manager that answers every name with the same authority (used to fake it in tests).
     */
    public static function alwaysReturning(FiscalAuthority $authority): self
    {
        $manager = new self;
        $manager->forced = $authority;

        return $manager;
    }

    /**
     * @param  Closure(): FiscalAuthority  $factory
     */
    public function extend(string $name, Closure $factory): void
    {
        $this->factories[$name] = $factory;
        unset($this->resolved[$name]);
    }

    public function authority(string $name): FiscalAuthority
    {
        if ($this->forced !== null) {
            return $this->forced;
        }

        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        if (! isset($this->factories[$name])) {
            throw new UnknownAuthority("No hay una autoridad fiscal registrada con el nombre «{$name}».");
        }

        return $this->resolved[$name] = ($this->factories[$name])();
    }
}
