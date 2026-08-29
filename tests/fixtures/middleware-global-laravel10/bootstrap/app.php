<?php

use App\Http\Middleware\MustNotReplaceKernelMiddleware;

$middleware->append(MustNotReplaceKernelMiddleware::class);
