<?php

declare(strict_types=1);

// Included only by the existing local-development initializer.
$server = '/run/holoul-storage-server';
$admin = '/run/holoul-storage-admin';
$client = '/run/holoul-storage';
$access = secret('/run/holoul-app/storage_access_key', fn (): string => bin2hex(random_bytes(16)));
$password = secret('/run/holoul-app/storage_secret_key', fn (): string => bin2hex(random_bytes(32)));
$adminAccess = secret($admin.'/access_key', fn (): string => bin2hex(random_bytes(16)));
$adminPassword = secret($admin.'/secret_key', fn (): string => bin2hex(random_bytes(32)));
$encryption = secret($server.'/encryption_key', fn (): string => bin2hex(random_bytes(32)));
$jwt = secret($server.'/jwt_key', fn (): string => bin2hex(random_bytes(32)));
secret($server.'/s3.json', fn (): string => json_encode(['identities' => [
    ['name' => 'holoul-bootstrap', 'credentials' => [['accessKey' => $adminAccess, 'secretKey' => $adminPassword]], 'actions' => ['Admin', 'Read', 'List', 'Write']],
    ['name' => 'holoul-runtime', 'credentials' => [['accessKey' => $access, 'secretKey' => $password]], 'actions' => ['Read:holoul-documents', 'List:holoul-documents', 'Write:holoul-documents']],
]], JSON_THROW_ON_ERROR));
if (! is_file($server.'/certificate.pem')) {
    $config = <<<'INI'
[req]
distinguished_name = dn
x509_extensions = extensions
prompt = no
[dn]
CN = storage
[extensions]
subjectAltName = DNS:storage,DNS:localhost,IP:127.0.0.1
basicConstraints = critical,CA:TRUE
keyUsage = critical,digitalSignature,keyEncipherment,keyCertSign
extendedKeyUsage = serverAuth,clientAuth
INI;
    $configFile = '/tmp/holoul-storage-tls.cnf';
    file_put_contents($configFile, $config);
    $options = ['config' => $configFile, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 3072, 'digest_alg' => 'sha256'];
    $key = openssl_pkey_new($options);
    $request = $key === false ? false : openssl_csr_new(['commonName' => 'storage'], $key, $options);
    $certificate = $request === false ? false : openssl_csr_sign($request, null, $key, 365, $options, random_int(1, PHP_INT_MAX));
    if ($key === false || $certificate === false || ! openssl_pkey_export($key, $private, null, $options) || ! openssl_x509_export($certificate, $public)) {
        throw new RuntimeException('Storage TLS initialization failed.');
    }
    secret($server.'/private-key.pem', fn (): string => $private);
    secret($server.'/certificate.pem', fn (): string => $public);
    unlink($configFile);
}
$public = file_get_contents($server.'/certificate.pem');
if ($public === false) {
    throw new RuntimeException('Storage TLS initialization failed.');
}
secret($client.'/client-ca.crt', fn (): string => $public);
$security = "[grpc]\nca = \"$server/certificate.pem\"\n";
foreach (['master', 'volume', 'filer', 's3', 'client'] as $component) {
    $security .= "[grpc.$component]\ncert = \"$server/certificate.pem\"\nkey = \"$server/private-key.pem\"\n";
}
$security .= "[jwt.signing]\nkey = \"$jwt\"\n[jwt.filer_signing]\nkey = \"$jwt\"\n[jwt.filer_signing.read]\nkey = \"$jwt\"\n[s3.sse]\nkek = \"$encryption\"\n[access]\nui = false\n[filer.expose_directory_metadata]\nenabled = false\n";
secret($server.'/security.toml', fn (): string => $security);
fwrite(STDOUT, "Private storage development secrets are ready.\n");
