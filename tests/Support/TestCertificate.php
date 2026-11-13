<?php

namespace Mnonm\FiscalAdapter\Tests\Support;

/**
 * Generates a self-signed X.509 certificate and its key, to test signing without a real one.
 */
class TestCertificate
{
    /**
     * @return array{certificate: string, private_key: string} absolute paths
     */
    public static function create(string $dir, ?string $passphrase = null): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'Fiscal Adapter Test', 'serialNumber' => 'CUIT 30712345671'], $key);
        $certificate = openssl_csr_sign($csr, null, $key, 365);

        $suffix = bin2hex(random_bytes(4));
        $certificateFile = "{$dir}/cert-{$suffix}.crt";
        $keyFile = "{$dir}/key-{$suffix}.key";

        openssl_x509_export_to_file($certificate, $certificateFile);
        openssl_pkey_export_to_file($key, $keyFile, $passphrase);

        return ['certificate' => $certificateFile, 'private_key' => $keyFile];
    }
}
