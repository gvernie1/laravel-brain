<?php

namespace App\Http\Controllers;

use App\Jobs\FirstJob;

class OrderController
{
    public function store(): void
    {
        FirstJob::dispatch();
    }
}
