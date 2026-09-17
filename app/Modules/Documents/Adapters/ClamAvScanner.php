<?php

declare(strict_types=1);

namespace App\Modules\Documents\Adapters;

use App\Modules\Documents\Contracts\MalwareScanner;
use App\Modules\Documents\Data\MalwareVerdict;
use App\Modules\Documents\Exceptions\ScannerUnavailable;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final readonly class ClamAvScanner implements MalwareScanner
{
    public function __construct(private string $socketPath, private int $maxSignatureAgeSeconds = 172800) {}

    public function scan(mixed $stream, int $size): MalwareVerdict
    {
        try {
            // Never interpret a daemon with missing/stale signatures as a clean scanner.
            $version = new BoundedSocket($this->socketPath, 3);
            $version->write("zVERSION\0");
            $reply = $version->readLine("\0", 256);
            $version->close();
            if (preg_match('~\AClamAV [0-9.]+/[0-9]+/(.+)\z~', $reply, $match) !== 1) {
                throw new ScannerUnavailable;
            }
            $date = new DateTimeImmutable($match[1], new DateTimeZone('UTC'));
            $age = time() - $date->getTimestamp();
            if ($age < -300 || $age > $this->maxSignatureAgeSeconds) {
                throw new ScannerUnavailable;
            }
            $socket = new BoundedSocket($this->socketPath, 25);
            $socket->write("zINSTREAM\0");
            $socket->sendStream($stream, $size, true);
            $result = $socket->readLine("\0", 1024);
            $socket->close();
            if ($result === 'stream: OK') {
                return MalwareVerdict::Clean;
            }
            if (preg_match('/\Astream: [^\r\n]+ FOUND\z/', $result) === 1) {
                return MalwareVerdict::Infected;
            }
        } catch (Throwable) {
            throw new ScannerUnavailable;
        }
        throw new ScannerUnavailable;
    }
}
