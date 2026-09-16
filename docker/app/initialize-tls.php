<?php

declare(strict_types=1);

// Local-only, self-signed SAN certificate. Trust the exported public certificate
// explicitly in smoke clients; a deployed environment supplies managed TLS.
function initializeLocalTls(): void
{
    $directory = '/run/holoul-tls';
    if (is_file($directory.'/certificate.pem') && is_file($directory.'/private-key.pem')) {
        return;
    }
    $configuration = <<<'INI'
[req]
distinguished_name = distinguished_name
x509_extensions = extensions
prompt = no
[distinguished_name]
CN = localhost
[extensions]
subjectAltName = DNS:localhost,IP:127.0.0.1
basicConstraints = critical,CA:TRUE
keyUsage = critical,digitalSignature,keyEncipherment,keyCertSign
extendedKeyUsage = serverAuth
INI;
    $path = '/tmp/holoul-local-tls.cnf';
    if (file_put_contents($path, $configuration) === false) {
        throw new RuntimeException('TLS initialization failed.');
    }
    $options = ['config' => $path, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 3072, 'digest_alg' => 'sha256'];
    $key = openssl_pkey_new($options);
    $csr = $key === false ? false : openssl_csr_new(['commonName' => 'localhost'], $key, $options);
    $certificate = $csr === false ? false : openssl_csr_sign($csr, null, $key, 365, $options, random_int(1, PHP_INT_MAX));
    if ($key === false || $certificate === false || ! openssl_pkey_export($key, $private, null, $options)
        || ! openssl_x509_export($certificate, $public)) {
        throw new RuntimeException('TLS initialization failed.');
    }
    if (file_put_contents($directory.'/private-key.pem', $private, LOCK_EX) === false
        || file_put_contents($directory.'/certificate.pem', $public, LOCK_EX) === false) {
        throw new RuntimeException('TLS initialization failed.');
    }
    chmod($directory.'/private-key.pem', 0444);
    chmod($directory.'/certificate.pem', 0444);
    unlink($path);
}
