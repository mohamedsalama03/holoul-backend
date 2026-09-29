<?php

declare(strict_types=1);

namespace App\Modules\PublicPortfolio\Adapters;

use App\Modules\PublicPortfolio\Contracts\ImageProcessor;
use App\Modules\PublicPortfolio\Data\ProcessedImage;
use App\Modules\PublicPortfolio\PortfolioPolicy;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final readonly class IsolatedImageProcessor implements ImageProcessor
{
    public function __construct(private string $socketPath) {}

    public function process(string $bytes, string $mediaType): ProcessedImage
    {
        $socket = @stream_socket_client('unix://'.$this->socketPath, $code, $message, 2);
        if ($socket === false) {
            throw new HttpException(503);
        }
        $deadline = microtime(true) + 40;
        try {
            $payload = json_encode(['size' => strlen($bytes), 'media_type' => $mediaType], JSON_THROW_ON_ERROR)."\n".$bytes;
            while ($payload !== '') {
                $this->timeout($socket, $deadline);
                $written = @fwrite($socket, $payload);
                if ($written === false || $written === 0) {
                    throw new HttpException(503);
                }
                $payload = substr($payload, $written);
            }
            $header = '';
            while (strlen($header) < 1024) {
                $byte = $this->read($socket, 1, $deadline);
                if ($byte === "\n") {
                    break;
                }
                $header .= $byte;
            }
            $response = json_decode($header, true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($response) || ! is_bool($response['safe'] ?? null)) {
                throw new HttpException(503);
            }
            if (! $response['safe']) {
                $reason = $response['reason'] ?? null;
                if (! in_array($reason, ['invalid_image', 'resource_limit'], true)) {
                    throw new HttpException(503);
                }

                return new ProcessedImage($reason);
            }
            $metadata = $response['variants'] ?? null;
            if (! is_array($metadata) || array_keys($metadata) !== ['card', 'gallery']) {
                throw new HttpException(503);
            }
            $variants = [];
            foreach ($metadata as $name => $info) {
                if (! is_string($name) || ! is_array($info) || ! is_int($info['width'] ?? null) || ! is_int($info['height'] ?? null) || ! is_int($info['size'] ?? null)
                    || $info['width'] < 1 || $info['height'] < 1 || $info['size'] < 1 || $info['size'] > PortfolioPolicy::MAX_BYTES
                    || $info['width'] > ($name === 'card' ? 640 : 1800) || $info['height'] > ($name === 'card' ? 640 : 1400)) {
                    throw new HttpException(503);
                }
                $output = $this->read($socket, $info['size'], $deadline);
                if (substr($output, 0, 4) !== 'RIFF' || substr($output, 8, 4) !== 'WEBP') {
                    throw new HttpException(503);
                }
                $variants[$name] = ['bytes' => $output, 'width' => $info['width'], 'height' => $info['height']];
            }

            return new ProcessedImage(null, $variants);
        } catch (Throwable) {
            throw new HttpException(503);
        } finally {
            fclose($socket);
        }
    }

    /** @param resource $socket */
    private function read(mixed $socket, int $size, float $deadline): string
    {
        $bytes = '';
        while (strlen($bytes) < $size) {
            $this->timeout($socket, $deadline);
            $chunk = @fread($socket, max(1, min(65536, $size - strlen($bytes))));
            if ($chunk === false || $chunk === '') {
                throw new HttpException(503);
            }
            $bytes .= $chunk;
        }

        return $bytes;
    }

    /** @param resource $socket */
    private function timeout(mixed $socket, float $deadline): void
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw new HttpException(503);
        }
        stream_set_timeout($socket, (int) $remaining, (int) (($remaining - floor($remaining)) * 1000000));
    }
}
