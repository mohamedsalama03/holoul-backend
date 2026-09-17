<?php

declare(strict_types=1);

namespace App\Modules\Documents\Adapters;

use App\Modules\Documents\Contracts\DocumentInspector;
use App\Modules\Documents\Data\DocumentFormat;
use App\Modules\Documents\Data\InspectionVerdict;
use App\Modules\Documents\Exceptions\InspectionUnavailable;
use Throwable;

final readonly class IsolatedDocumentInspector implements DocumentInspector
{
    public function __construct(private string $socketPath) {}

    public function inspect(mixed $stream, int $size, DocumentFormat $format): InspectionVerdict
    {
        try {
            $socket = new BoundedSocket($this->socketPath, 30);
            $socket->write(json_encode(['format' => $format->value, 'size' => $size], JSON_THROW_ON_ERROR)."\n");
            $socket->sendStream($stream, $size);
            $response = json_decode($socket->readLine("\n", 256), true, 4, JSON_THROW_ON_ERROR);
            $socket->close();
            if (! is_array($response) || ! is_bool($response['safe'] ?? null)) {
                throw new InspectionUnavailable;
            }
            if ($response['safe'] && ($response['reason'] ?? null) === null) {
                return new InspectionVerdict(true);
            }
            $reason = $response['reason'] ?? null;
            if ($response['safe'] === false && is_string($reason) && in_array($reason, ['invalid_structure', 'dangerous_content', 'resource_limit', 'encrypted_document', 'format_mismatch'], true)) {
                return new InspectionVerdict(false, $reason);
            }
        } catch (Throwable) {
            throw new InspectionUnavailable;
        }
        throw new InspectionUnavailable;
    }
}
