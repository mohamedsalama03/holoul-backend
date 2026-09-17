<?php

declare(strict_types=1);

namespace App\Modules\Documents\Adapters;

use RuntimeException;

/** The whole exchange has one deadline, including clients which make slow partial progress. */
final class BoundedSocket
{
    /** @var resource */
    private mixed $socket;

    private float $deadline;

    public function __construct(string $path, int $seconds = 30)
    {
        $socket = @stream_socket_client('unix://'.$path, $code, $message, 2);
        if ($socket === false) {
            throw new RuntimeException('Processor unavailable.');
        }
        $this->socket = $socket;
        $this->deadline = microtime(true) + $seconds;
    }

    public function write(string $bytes): void
    {
        while ($bytes !== '') {
            $this->timeout();
            $written = @fwrite($this->socket, $bytes);
            if ($written === false || $written === 0) {
                throw new RuntimeException('Processor unavailable.');
            }
            $bytes = substr($bytes, $written);
        }
    }

    public function readLine(string $terminator, int $maximum = 1024): string
    {
        $line = '';
        while (strlen($line) < $maximum) {
            $this->timeout();
            $byte = @fread($this->socket, 1);
            if ($byte === false || $byte === '') {
                throw new RuntimeException('Processor unavailable.');
            }
            if ($byte === $terminator) {
                return $line;
            }
            $line .= $byte;
        }
        throw new RuntimeException('Processor unavailable.');
    }

    /** @param resource $stream */
    public function sendStream(mixed $stream, int $size, bool $clamav = false): void
    {
        if (! is_resource($stream) || $size < 1 || $size > 10485760 || ! rewind($stream)) {
            throw new RuntimeException('Processor unavailable.');
        }
        $remaining = $size;
        while ($remaining > 0) {
            $bytes = fread($stream, min(65536, $remaining));
            if ($bytes === false || $bytes === '') {
                throw new RuntimeException('Processor unavailable.');
            }
            $this->write(($clamav ? pack('N', strlen($bytes)) : '').$bytes);
            $remaining -= strlen($bytes);
        }
        if (fread($stream, 1) !== '') {
            throw new RuntimeException('Processor unavailable.');
        }
        if ($clamav) {
            $this->write(pack('N', 0));
        }
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    private function timeout(): void
    {
        $remaining = $this->deadline - microtime(true);
        if ($remaining <= 0) {
            throw new RuntimeException('Processor unavailable.');
        }
        stream_set_timeout($this->socket, (int) $remaining, (int) (($remaining - floor($remaining)) * 1000000));
    }
}
