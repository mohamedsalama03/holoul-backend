<?php

declare(strict_types=1);

// Authenticated, bounded streaming encryption; never bootstrap the application.
// The caller keeps the random key separate from the encrypted backup bundle.
try {
    $mode = $argv[1] ?? '';
    $context = $argv[2] ?? '';
    if (PHP_SAPI !== 'cli' || ! in_array($mode, ['seal', 'open'], true)
        || preg_match('/\A[a-f0-9]{24}:[a-z_]+\z/D', $context) !== 1) {
        throw new RuntimeException;
    }
    $key = file_get_contents('/run/backup/key');
    if (! is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
        throw new RuntimeException;
    }
    $read = static function (int $size): string {
        $value = '';
        while (strlen($value) < $size && ! feof(STDIN)) {
            $remaining = $size - strlen($value);
            if ($remaining < 1) {
                throw new RuntimeException;
            }
            $part = fread(STDIN, $remaining);
            if ($part === false) {
                throw new RuntimeException;
            }
            $value .= $part;
        }
        if (strlen($value) !== $size) {
            throw new RuntimeException;
        }

        return $value;
    };
    $write = static function (string $value): void {
        while ($value !== '') {
            $size = fwrite(STDOUT, $value);
            if ($size === false || $size === 0) {
                throw new RuntimeException;
            }
            $value = substr($value, $size);
        }
    };
    $magic = "HOLOULB8S1\0";
    if ($mode === 'seal') {
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        if (! is_string($state) || ! is_string($header)) {
            throw new RuntimeException;
        }
        $write($magic.$header);
        while (! feof(STDIN)) {
            $plain = fread(STDIN, 65536);
            if ($plain === false) {
                throw new RuntimeException;
            }
            if ($plain !== '') {
                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $plain, $context);
                $write(pack('N', strlen($cipher)).$cipher);
            }
        }
        $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, '', $context, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
        $write(pack('N', strlen($cipher)).$cipher);
    } else {
        if (! hash_equals($magic, $read(strlen($magic)))) {
            throw new RuntimeException;
        }
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($read(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES), $key);
        while (true) {
            $frame = unpack('Nsize', $read(4));
            if ($frame === false) {
                throw new RuntimeException;
            }
            $size = $frame['size'];
            if (! is_int($size) || $size < 17 || $size > 65553) {
                throw new RuntimeException;
            }
            $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $read($size), $context);
            if ($result === false) {
                throw new RuntimeException;
            }
            [$plain, $tag] = $result;
            if (! is_string($plain) || ! is_int($tag)) {
                throw new RuntimeException;
            }
            if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                if ($plain !== '' || fread(STDIN, 1) !== '') {
                    throw new RuntimeException;
                }
                break;
            }
            if ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE) {
                throw new RuntimeException;
            }
            $write($plain);
        }
    }
    sodium_memzero($key);
} catch (Throwable) {
    fwrite(STDERR, "Backup authentication or stream operation failed.\n");
    exit(1);
}
