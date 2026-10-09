<?php

use App\Http\Controllers\Api\V1\InquiryController;
use App\Http\Controllers\Api\V1\MarketingEventController;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

RateLimiter::for('api-credential', function (Request $request) {
    $key = (string) $request->header('X-Api-Key', 'anonymous');

    return Limit::perMinutes(1, 60, 'api-credential:'.$key);
});

Route::middleware('throttle:api-credential')->group(function () {
    Route::post('/v1/inquiries', [InquiryController::class, 'store']);
    Route::post('/v1/events', [MarketingEventController::class, 'store']);
});
