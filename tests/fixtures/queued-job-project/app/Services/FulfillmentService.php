<?php

namespace App\Services;

use App\Models\Order;

class FulfillmentService
{
    public function run(): void
    {
        Order::where('status', 'pending');
    }
}
