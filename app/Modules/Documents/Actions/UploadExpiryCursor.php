<?php

declare(strict_types=1);

namespace App\Modules\Documents\Actions;

use Illuminate\Support\Facades\DB;

final class UploadExpiryCursor
{
    public function value(): ?string
    {
        $value = DB::table('document_reconciliation_cursors')->where('id', 1)->value('intake_upload_cursor');

        return is_string($value) ? $value : null;
    }

    public function advance(?string $previous, ?string $next): void
    {
        DB::table('document_reconciliation_cursors')->where('id', 1)->where('intake_upload_cursor', $previous)
            ->update(['intake_upload_cursor' => $next, 'updated_at' => now()]);
    }
}
