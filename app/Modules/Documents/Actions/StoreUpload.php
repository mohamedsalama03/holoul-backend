<?php

declare(strict_types=1);

namespace App\Modules\Documents\Actions;

use App\Modules\Documents\Contracts\PrivateObjectStore;
use App\Modules\Documents\Data\DocumentFormat;
use App\Modules\Documents\Data\StoredObject;
use App\Modules\Documents\Data\UploadReservation;
use App\Modules\Documents\DocumentPolicy;
use App\Modules\Documents\Exceptions\StorageUnavailable;
use App\Modules\Documents\Models\Document;
use finfo;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class StoreUpload
{
    public function __construct(private PrivateObjectStore $storage) {}

    /** @param resource $stream */
    public function handle(UploadReservation $reservation, mixed $stream): StoredObject
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Object upload cannot run inside a database transaction.');
        }
        if (! is_resource($stream)) {
            throw new HttpException(422);
        }
        $valid = Document::query()->whereKey($reservation->documentId)->where('storage_key', $reservation->key)
            ->where('expected_size', $reservation->bytes)->where('expected_sha256', $reservation->sha256)
            ->whereIn('state', ['uploading', 'quarantined', 'available'])->where('upload_expires_at', '>', DB::raw('clock_timestamp()'))->exists();
        if (! $valid || $reservation->expiresAt <= now()->toImmutable()) {
            throw new HttpException(409);
        }
        $temporary = tmpfile();
        if ($temporary === false) {
            throw new StorageUnavailable;
        }
        try {
            $hash = hash_init('sha256');
            $bytes = 0;
            $prefix = '';
            while (! feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false || ($chunk === '' && ! feof($stream))) {
                    throw new StorageUnavailable;
                }
                $bytes += strlen($chunk);
                if ($bytes > DocumentPolicy::MAX_BYTES || $bytes > $reservation->bytes) {
                    throw new HttpException(413);
                }
                if (strlen($prefix) < 8192) {
                    $prefix .= substr($chunk, 0, 8192 - strlen($prefix));
                }
                hash_update($hash, $chunk);
                if (fwrite($temporary, $chunk) !== strlen($chunk)) {
                    throw new StorageUnavailable;
                }
            }
            if ($bytes !== $reservation->bytes || ! hash_equals($reservation->sha256, hash_final($hash))) {
                throw new HttpException(422);
            }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($prefix);
            $validFormat = match ($reservation->format) {
                DocumentFormat::Pdf => str_starts_with($prefix, '%PDF-') && $mime === 'application/pdf',
                DocumentFormat::Docx => str_starts_with($prefix, "PK\x03\x04") && in_array($mime, ['application/zip', $reservation->format->mime()], true),
            };
            if (! $validFormat) {
                throw new HttpException(415);
            }
            rewind($temporary);

            return $this->storage->putIfAbsent($reservation->key, $temporary, $bytes, $reservation->sha256, $reservation->format->mime());
        } finally {
            fclose($temporary);
        }
    }
}
