<?php

namespace Mnonm\FiscalAdapter;

use LogicException;
use Mnonm\FiscalAdapter\Exceptions\InvalidCredential;

final class Credential
{
    private function __construct(
        private readonly string $certificatePem,
        #[\SensitiveParameter] private readonly string $privateKeyPem,
        #[\SensitiveParameter] private readonly ?string $passphrase,
        private readonly string $fingerprint,
    ) {}

    public static function fromFiles(string $certificatePath, string $privateKeyPath, #[\SensitiveParameter] ?string $passphrase = null): self
    {
        return self::fromPem(
            self::read($certificatePath, 'certificado'),
            self::read($privateKeyPath, 'clave privada'),
            $passphrase,
        );
    }

    public static function fromPem(string $certificatePem, #[\SensitiveParameter] string $privateKeyPem, #[\SensitiveParameter] ?string $passphrase = null): self
    {
        $certificate = @openssl_x509_read($certificatePem);
        if ($certificate === false) {
            throw new InvalidCredential('El certificado no es un PEM X.509 válido.');
        }

        $key = @openssl_pkey_get_private($privateKeyPem, $passphrase ?? '');
        if ($key === false) {
            throw new InvalidCredential('No se pudo leer la clave privada: el formato o la contraseña no son válidos.');
        }

        if (! openssl_x509_check_private_key($certificate, $key)) {
            throw new InvalidCredential('La clave privada no corresponde al certificado.');
        }

        $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');
        if ($fingerprint === false) {
            throw new InvalidCredential('No se pudo calcular la huella del certificado.');
        }

        return new self($certificatePem, $privateKeyPem, $passphrase, $fingerprint);
    }

    /** SHA-256 (hex) of the certificate in DER form. */
    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    /** @internal */
    public function certificatePem(): string
    {
        return $this->certificatePem;
    }

    /** @internal */
    public function privateKeyPem(): string
    {
        return $this->privateKeyPem;
    }

    /** @internal */
    public function passphrase(): ?string
    {
        return $this->passphrase;
    }

    /**
     * @return array{fingerprint: string}
     */
    public function __debugInfo(): array
    {
        return ['fingerprint' => $this->fingerprint];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('Una credencial no se serializa: contiene la clave privada.');
    }

    private static function read(string $path, string $label): string
    {
        $contents = is_file($path) && is_readable($path) ? @file_get_contents($path) : false;
        if ($contents === false) {
            throw new InvalidCredential("No se pudo leer el {$label} en {$path}.");
        }

        return $contents;
    }
}
