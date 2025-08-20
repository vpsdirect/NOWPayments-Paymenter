<?php

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\Nowpayments\Nowpayments;

Route::post('/extensions/gateways/nowpayments/webhook', [Nowpayments::class, 'webhook'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('extensions.gateways.nowpayments.webhook');