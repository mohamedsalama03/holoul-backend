<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\RecordsContractResponses;

abstract class TestCase extends BaseTestCase
{
    use RecordsContractResponses;
}
