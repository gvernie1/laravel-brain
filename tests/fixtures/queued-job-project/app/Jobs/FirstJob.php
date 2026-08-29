<?php

namespace App\Jobs;

use App\Services\OrderWorkflow;

class FirstJob
{
    public function handle(OrderWorkflow $workflow): void
    {
        $workflow->run();
    }
}
