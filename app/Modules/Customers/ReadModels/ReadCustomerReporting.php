<?php

declare(strict_types=1);

namespace App\Modules\Customers\ReadModels;

use App\Modules\Customers\Contracts\CustomerReportingReader;
use Illuminate\Support\Facades\DB;
use stdClass;

final class ReadCustomerReporting implements CustomerReportingReader
{
    public function read(string $from, string $until): array
    {
        $cohort = DB::table('customers')->where('created_at', '>=', $from)->where('created_at', '<', $until);
        $growth = (clone $cohort)->selectRaw("to_char(created_at AT TIME ZONE 'UTC','YYYY-MM-DD') AS day,count(*)::int AS customers")
            ->groupByRaw("to_char(created_at AT TIME ZONE 'UTC','YYYY-MM-DD')")->orderBy('day')->limit(366)->get();

        return ['total_customers' => $cohort->count(), 'growth' => $growth->map(static fn (stdClass $row): array => get_object_vars($row))->all()];
    }
}
