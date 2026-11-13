<?php

namespace Mnonm\FiscalAdapter\Arca;

use Mnonm\FiscalAdapter\Exceptions\InvalidDocument;

/**
 * CUIT en el formato que piden los webservices de ARCA: 11 dígitos, sin guiones.
 *
 * Una CUIT con prefijo inexistente o dígito verificador mal no se normaliza: ARCA la rechaza con un
 * error de esquema, así que falla acá con un mensaje entendible.
 */
final readonly class Cuit
{
    private const PREFIXES = ['20', '23', '24', '25', '26', '27', '30', '33', '34'];

    private const WEIGHTS = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];

    public string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * @throws InvalidDocument
     */
    public static function from(string $value): self
    {
        $digits = self::digits($value);

        if ($digits === null) {
            throw new InvalidDocument("La CUIT «{$value}» no es válida: debe tener 11 dígitos, un prefijo conocido y un dígito verificador correcto.");
        }

        return new self($digits);
    }

    public static function tryFrom(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        $digits = self::digits($value);

        return $digits === null ? null : new self($digits);
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private static function digits(string $value): ?string
    {
        $digits = preg_replace('/\D/', '', $value);

        if (strlen($digits) !== 11 || ! in_array(substr($digits, 0, 2), self::PREFIXES, true)) {
            return null;
        }

        return (int) $digits[10] === self::checkDigit(substr($digits, 0, 10)) ? $digits : null;
    }

    private static function checkDigit(string $firstTen): int
    {
        $sum = 0;

        foreach (str_split($firstTen) as $position => $digit) {
            $sum += (int) $digit * self::WEIGHTS[$position];
        }

        $remainder = 11 - ($sum % 11);

        return match ($remainder) {
            11 => 0,
            10 => 9,
            default => $remainder,
        };
    }
}
