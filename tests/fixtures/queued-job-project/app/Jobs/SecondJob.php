<?php

namespace App\Jobs;

use App\Services\FulfillmentService;

class SecondJob
{
    public function handle(FulfillmentService $service): void
    {
        $service->run();
        FirstJob::dispatch();
    }
}
