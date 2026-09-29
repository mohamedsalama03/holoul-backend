<?php

declare(strict_types=1);

namespace App\Modules\Contact\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class ReconcileContactDelivery
{
    public function __construct(private RecordAuditEvent $audit) {}

    public function handle(int $limit): int
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Limit must be 1..100.');
        }
        $rows = DB::table('contact_deliveries')->whereIn('state', ['pending', 'sending'])->whereIn('operation_id',
            DB::table('async_operations')->where('state', 'failed')->select('id'))->orderBy('id')->limit($limit)->get();
        $count = 0;
        foreach ($rows as $candidate) {
            $count += DB::transaction(function () use ($candidate): int {
                if (DB::table('async_operations')->where('id', $candidate->operation_id)->where('state', 'failed')->lockForUpdate()->first() === null) {
                    return 0;
                }
                $row = DB::table('contact_deliveries')->where('id', $candidate->id)->lockForUpdate()->firstOrFail();
                if (! in_array($row->state, ['pending', 'sending'], true) || ! is_string($row->id)) {
                    return 0;
                }
                $state = $row->state === 'sending' ? 'uncertain' : 'blocked';
                DB::table('contact_deliveries')->where('id', $row->id)->update(['state' => $state]);
                DB::table('contact_delivery_attempts')->insert(['id' => (string) Str::uuid7(), 'delivery_id' => $row->id, 'fence' => $row->send_fence ?? 0, 'outcome' => $state]);
                $this->audit->handle('contact.mail_'.$state, 'contact.delivery', $row->id, (string) Str::uuid7());

                return 1;
            });
        }

        return $count;
    }
}
