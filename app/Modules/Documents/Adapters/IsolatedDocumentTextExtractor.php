<?php

declare(strict_types=1);

namespace App\Modules\Documents\Adapters;

use App\Modules\Documents\Contracts\DocumentTextExtractor;
use App\Modules\Documents\Data\DocumentFormat;
use App\Modules\Documents\Exceptions\DocumentExtractionRejected;
use App\Modules\Documents\Exceptions\InspectionUnavailable;
use InvalidArgumentException;
use Throwable;

final readonly class IsolatedDocumentTextExtractor implements DocumentTextExtractor
{
    public function __construct(private string $socketPath) {}

    public function extract(mixed $stream, int $size, DocumentFormat $format, int $maxCharacters): string
    {
        if ($maxCharacters < 1 || $maxCharacters > 20000) {
            throw new InvalidArgumentException('Document text extraction accepts 1 to 20000 characters.');
        }
        try {
            $socket = new BoundedSocket($this->socketPath, 30);
            $socket->write(json_encode(['operation' => 'extract', 'format' => $format->value, 'size' => $size,
                'max_characters' => $maxCharacters], JSON_THROW_ON_ERROR)."\n");
            $socket->sendStream($stream, $size);
            $response = json_decode($socket->readLine("\n", 240256), true, 4, JSON_THROW_ON_ERROR);
            $socket->close();
            if (! is_array($response) || array_is_list($response)) {
                throw new InspectionUnavailable;
            }
            if (count($response) === 2 && ($response['safe'] ?? null) === true && is_string($response['text'] ?? null)) {
                $text = $response['text'];
                if (! mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0") || mb_strlen($text, 'UTF-8') > $maxCharacters) {
                    throw new InspectionUnavailable;
                }

                return $text;
            }
            $reason = $response['reason'] ?? null;
            if (count($response) === 2 && ($response['safe'] ?? null) === false && is_string($reason)) {
                throw new DocumentExtractionRejected($reason);
            }
        } catch (DocumentExtractionRejected $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new InspectionUnavailable;
        }
        throw new InspectionUnavailable;
    }
}
