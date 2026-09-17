<?php

declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';

use Aws\Exception\AwsException;
use Aws\S3\S3Client;

function storageSecret(string $path): string
{
    $value = file_get_contents($path);
    if ($value === false || trim($value) === '') {
        throw new RuntimeException('Storage bootstrap secret unavailable.');
    }

    return trim($value);
}

/** @param callable(): mixed $attempt */
function mustDeny(callable $attempt): void
{
    try {
        $attempt();
    } catch (AwsException $error) {
        if (in_array($error->getStatusCode(), [400, 403], true)) {
            return;
        }
        throw $error;
    }
    throw new RuntimeException('Private storage access control failed.');
}

$phase = 'client';
try {
    $client = new S3Client([
        'version' => '2006-03-01', 'region' => 'us-east-1', 'endpoint' => 'https://storage:8333', 'use_path_style_endpoint' => true,
        'credentials' => ['key' => storageSecret('/run/holoul-storage-admin/access_key'), 'secret' => storageSecret('/run/holoul-storage-admin/secret_key')],
        'http' => ['verify' => '/run/holoul-storage/client-ca.crt', 'connect_timeout' => 3, 'timeout' => 10, 'allow_redirects' => false],
        'retries' => 1, 'request_checksum_calculation' => 'when_required', 'response_checksum_validation' => 'when_required',
    ]);
    $bucket = 'holoul-documents';
    $phase = 'bucket';
    try {
        $client->headBucket(['Bucket' => $bucket]);
    } catch (AwsException $error) {
        if ($error->getStatusCode() !== 404) {
            throw $error;
        }
        $client->createBucket(['Bucket' => $bucket, 'ACL' => 'private']);
    }
    $phase = 'versioning';
    $client->putBucketVersioning(['Bucket' => $bucket, 'VersioningConfiguration' => ['Status' => 'Enabled']]);
    $phase = 'public-access';
    // SeaweedFS 4.47 does not implement AWS PublicAccessBlock. Disable ACL grants
    // instead, with no anonymous identity and no ACL/admin actions for runtime.
    $client->putBucketAcl(['Bucket' => $bucket, 'ACL' => 'private']);
    $client->putBucketOwnershipControls(['Bucket' => $bucket, 'OwnershipControls' => ['Rules' => [['ObjectOwnership' => 'BucketOwnerEnforced']]]]);
    $phase = 'encryption';
    $client->putBucketEncryption(['Bucket' => $bucket, 'ServerSideEncryptionConfiguration' => ['Rules' => [['ApplyServerSideEncryptionByDefault' => ['SSEAlgorithm' => 'AES256']]]]]);
    $phase = 'verify';
    if ($client->getBucketVersioning(['Bucket' => $bucket])->get('Status') !== 'Enabled') {
        throw new RuntimeException('Storage versioning unavailable.');
    }
    $ownership = $client->getBucketOwnershipControls(['Bucket' => $bucket])->get('OwnershipControls');
    $encryption = $client->getBucketEncryption(['Bucket' => $bucket])->get('ServerSideEncryptionConfiguration');
    if (($ownership['Rules'][0]['ObjectOwnership'] ?? null) !== 'BucketOwnerEnforced'
        || ($encryption['Rules'][0]['ApplyServerSideEncryptionByDefault']['SSEAlgorithm'] ?? null) !== 'AES256') {
        throw new RuntimeException('Private storage configuration failed.');
    }
    $settings = [
        'version' => '2006-03-01', 'region' => 'us-east-1', 'endpoint' => 'https://storage:8333', 'use_path_style_endpoint' => true,
        'http' => ['verify' => '/run/holoul-storage/client-ca.crt', 'connect_timeout' => 3, 'timeout' => 10, 'allow_redirects' => false],
        'retries' => 0, 'request_checksum_calculation' => 'when_required', 'response_checksum_validation' => 'when_required',
    ];
    $runtime = new S3Client($settings + ['credentials' => ['key' => storageSecret('/run/holoul-secrets/storage_access_key'), 'secret' => storageSecret('/run/holoul-secrets/storage_secret_key')]]);
    $anonymous = new S3Client($settings + ['credentials' => false]);
    $key = '__bootstrap/'.bin2hex(random_bytes(16));
    $version = null;
    $inlineVersion = null;
    try {
        $phase = 'private-object';
        $written = $runtime->putObject(['Bucket' => $bucket, 'Key' => $key, 'Body' => 'private bootstrap probe', 'IfNoneMatch' => '*', 'ServerSideEncryption' => 'AES256']);
        $version = $written->get('VersionId');
        if (! is_string($version) || $version === '' || $version === 'null' || $written->get('ServerSideEncryption') !== 'AES256') {
            throw new RuntimeException('Storage object identity unavailable.');
        }
        $phase = 'anonymous-denied';
        mustDeny(fn () => $anonymous->getObject(['Bucket' => $bucket, 'Key' => $key, 'VersionId' => $version]));
        mustDeny(fn () => $anonymous->listObjectsV2(['Bucket' => $bucket, 'MaxKeys' => 1]));
        $phase = 'public-bucket-acl-denied';
        mustDeny(fn () => $runtime->putBucketAcl(['Bucket' => $bucket, 'ACL' => 'public-read']));
        $phase = 'public-object-acl-denied';
        mustDeny(fn () => $runtime->putObjectAcl(['Bucket' => $bucket, 'Key' => $key, 'VersionId' => $version, 'ACL' => 'public-read']));
        $phase = 'public-inline-acl-denied';
        // This provider ignores canned PUT ACLs with BucketOwnerEnforced. Prove the
        // resulting object grants remain private and cannot be read anonymously.
        try {
            $inline = $runtime->putObject(['Bucket' => $bucket, 'Key' => $key.'/public', 'Body' => 'must not become public', 'ACL' => 'public-read', 'IfNoneMatch' => '*']);
            $inlineVersion = $inline->get('VersionId');
            if (! is_string($inlineVersion) || $inlineVersion === '' || $inlineVersion === 'null') {
                throw new RuntimeException('Private storage identity failed.');
            }
            $acl = $client->getObjectAcl(['Bucket' => $bucket, 'Key' => $key.'/public', 'VersionId' => $inlineVersion])->get('Grants');
            if (! is_array($acl) || count($acl) !== 1 || ($acl[0]['Grantee']['Type'] ?? null) !== 'CanonicalUser' || ($acl[0]['Permission'] ?? null) !== 'FULL_CONTROL') {
                throw new RuntimeException('Private storage ACL failed.');
            }
            mustDeny(fn () => $anonymous->getObject(['Bucket' => $bucket, 'Key' => $key.'/public', 'VersionId' => $inlineVersion]));
        } catch (AwsException $error) {
            if (! in_array($error->getStatusCode(), [400, 403], true)) {
                throw $error;
            }
        }
        mustDeny(fn () => $anonymous->getObject(['Bucket' => $bucket, 'Key' => $key, 'VersionId' => $version]));
    } finally {
        $client->putBucketAcl(['Bucket' => $bucket, 'ACL' => 'private']);
        if (is_string($inlineVersion) && $inlineVersion !== '' && $inlineVersion !== 'null') {
            $client->deleteObject(['Bucket' => $bucket, 'Key' => $key.'/public', 'VersionId' => $inlineVersion]);
        }
        if (is_string($version) && $version !== '' && $version !== 'null') {
            $client->deleteObject(['Bucket' => $bucket, 'Key' => $key, 'VersionId' => $version]);
        }
    }
    fwrite(STDOUT, "Private versioned storage is ready.\n");
} catch (Throwable $error) {
    $code = $error instanceof AwsException ? $error->getAwsErrorCode() : null;
    $safeCode = is_string($code) && preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $code) === 1 ? $code : 'Unavailable';
    $status = $error instanceof AwsException ? $error->getStatusCode() : null;
    fwrite(STDERR, json_encode(['phase' => $phase, 'status' => $status, 'code' => $safeCode], JSON_THROW_ON_ERROR)."\n");
    exit(1);
}
