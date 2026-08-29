<?php

use Illuminate\Support\Facades\Route;

Route::get('/public', fn () => 'public');
Route::post('/account', fn () => 'account')->middleware('route-specific');
