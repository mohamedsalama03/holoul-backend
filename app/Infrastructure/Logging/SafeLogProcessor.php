<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Illuminate\Support\Str;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

final class SafeLogProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $context = [];

        foreach ($record->context as $key => $value) {
            if (in_array($key, ['request_id', 'operation_id'], true) && is_string($value) && Str::isUuid($value)) {
                $context[$key] = $value;
            }

            if (in_array($key, ['exception_type', 'error_code', 'queue', 'family', 'method'], true)
                && is_string($value) && preg_match('/\A[A-Za-z0-9_.\\\\-]{1,160}\z/D', $value) === 1) {
                $context[$key] = $value;
            }

            if (in_array($key, ['attempt', 'status', 'duration_ms', 'query_count', 'sql_duration_ms'], true) && is_int($value) && $value >= 0) {
                $context[$key] = $value;
            }
        }

        return $record->with(
            message: preg_match('/\A[a-z][a-z0-9_.]{0,79}\z/D', $record->message) === 1 ? $record->message : 'application.event',
            context: $context,
            extra: [],
        );
    }
}
