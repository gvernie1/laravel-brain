<?php

namespace App\Services;

use App\Jobs\SecondJob;
use App\Models\Order;

class OrderWorkflow
{
    public function run(): void
    {
        Order::query();
        SecondJob::dispatch();
    }
}
