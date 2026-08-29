<?php

use App\Http\Controllers\OrderController;
use App\Jobs\FirstJob;
use Illuminate\Support\Facades\Route;

Route::post('/orders', [OrderController::class, 'store']);
Route::post('/queued-closure', fn () => FirstJob::dispatch());
