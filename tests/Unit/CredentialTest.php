<?php

use Mnonm\FiscalAdapter\Credential;
use Mnonm\FiscalAdapter\Exceptions\InvalidCredential;
use Mnonm\FiscalAdapter\Tests\Support\TestCertificate;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/fa-'.bin2hex(random_bytes(4));
    mkdir($this->dir);
});

afterEach(function () {
    array_map('unlink', glob($this->dir.'/*'));
    rmdir($this->dir);
});

it('loads from files and from PEM with the same fingerprint', function () {
    $paths = TestCertificate::create($this->dir);

    $fromFiles = Credential::fromFiles($paths['certificate'], $paths['private_key']);
    $fromPem = Credential::fromPem(file_get_contents($paths['certificate']), file_get_contents($paths['private_key']));

    expect($fromFiles->fingerprint())->toBe($fromPem->fingerprint())->toMatch('/^[0-9a-f]{64}$/');
});

it('accepts a key protected with a passphrase', function () {
    $paths = TestCertificate::create($this->dir, 'secret');

    $credential = Credential::fromFiles($paths['certificate'], $paths['private_key'], 'secret');

    expect($credential->passphrase())->toBe('secret');
});

it('rejects a wrong passphrase without leaking it', function () {
    $paths = TestCertificate::create($this->dir, 'secret');

    try {
        Credential::fromFiles($paths['certificate'], $paths['private_key'], 'wrong');
        $this->fail('Expected InvalidCredential');
    } catch (InvalidCredential $e) {
        expect($e->getMessage())->not->toContain('wrong')->not->toContain('PRIVATE KEY');
        expect($e->getPrevious())->toBeNull();
    }
});

it('rejects a key that does not match the certificate', function () {
    $a = TestCertificate::create($this->dir);
    $b = TestCertificate::create($this->dir);

    expect(fn () => Credential::fromFiles($a['certificate'], $b['private_key']))
        ->toThrow(InvalidCredential::class);
});

it('rejects unreadable files', function () {
    $path = $this->dir.'/missing.crt';

    try {
        Credential::fromFiles($path, $this->dir.'/missing.key');
        $this->fail('Expected InvalidCredential');
    } catch (InvalidCredential $e) {
        expect($e->getMessage())->toContain($path);
    }
});

it('rejects content that is not PEM', function () {
    expect(fn () => Credential::fromPem('nope', 'nope'))->toThrow(InvalidCredential::class);
});

it('never exposes the key when dumped or serialized', function () {
    $paths = TestCertificate::create($this->dir, 'secret');
    $credential = Credential::fromFiles($paths['certificate'], $paths['private_key'], 'secret');

    expect(print_r($credential, true))->not->toContain('PRIVATE KEY')->not->toContain('secret');
    expect(fn () => serialize($credential))->toThrow(LogicException::class);
});
