<?php

use Mnonm\FiscalAdapter\Arca\Cuit;
use Mnonm\FiscalAdapter\Exceptions\InvalidDocument;

it('normalizes dashes and spaces', fn () => expect(Cuit::from('30-71234567-1')->value)->toBe('30712345671'));

it('accepts the fixture CUITs', fn (string $cuit) => expect(Cuit::from($cuit)->value)->toBe($cuit))
    ->with(['30712345671', '20123456786', '30798765430', '30500000003', '20301234563']);

it('rejects a wrong check digit', fn () => expect(fn () => Cuit::from('30712345678'))->toThrow(InvalidDocument::class));

it('includes the offending input in the error message', function () {
    expect(fn () => Cuit::from('30712345678'))->toThrow(InvalidDocument::class, '30712345678');
});

it('rejects an unknown prefix even with a valid check digit', fn () => expect(Cuit::tryFrom('31000000003'))->toBeNull());

it('maps check digit 10 to 9', fn () => expect(Cuit::from('20000000019')->value)->toBe('20000000019'));

it('rejects wrong length and null', fn () => expect(Cuit::tryFrom('3071234567'))->toBeNull()->and(Cuit::tryFrom(null))->toBeNull());

it('casts to its digits string', fn () => expect((string) Cuit::from('30-71234567-1'))->toBe('30712345671'));
