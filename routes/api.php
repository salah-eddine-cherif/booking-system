<?php

use App\Http\Controllers\Api\V1\Admin;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AvailabilityController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Public booking flow
    |--------------------------------------------------------------------------
    |
    | No authentication. A booking is addressed by its reference and proved by
    | the email address that created it.
    */

    Route::get('services', [ServiceController::class, 'index'])->name('services.index');
    Route::get('services/{service}', [ServiceController::class, 'show'])->name('services.show');
    Route::get('services/{service}/availability', [AvailabilityController::class, 'index'])
        ->name('services.availability');

    // Booking attempts are rate limited: this endpoint takes a lock on a staff
    // member's calendar, so it is the one worth protecting from hammering.
    Route::post('bookings', [BookingController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('bookings.store');

    Route::get('bookings/{booking}', [BookingController::class, 'show'])->name('bookings.show');
    Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel'])
        ->middleware('throttle:20,1')
        ->name('bookings.cancel');

    /*
    |--------------------------------------------------------------------------
    | Staff authentication
    |--------------------------------------------------------------------------
    */

    Route::post('auth/token', [AuthController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('auth.token');

    Route::delete('auth/token', [AuthController::class, 'destroy'])
        ->middleware('auth:sanctum')
        ->name('auth.revoke');

    /*
    |--------------------------------------------------------------------------
    | Admin / staff calendar
    |--------------------------------------------------------------------------
    */

    Route::middleware('auth:sanctum')->prefix('admin')->name('admin.')->group(function () {
        Route::apiResource('services', Admin\ServiceController::class)->except('show');

        Route::get('bookings', [Admin\BookingController::class, 'index'])->name('bookings.index');
        Route::get('bookings/{booking}', [Admin\BookingController::class, 'show'])->name('bookings.show');
        Route::patch('bookings/{booking}', [Admin\BookingController::class, 'update'])->name('bookings.update');
        Route::post('bookings/{booking}/reschedule', [Admin\BookingController::class, 'reschedule'])
            ->name('bookings.reschedule');
        Route::delete('bookings/{booking}', [Admin\BookingController::class, 'destroy'])->name('bookings.destroy');

        Route::get('staff/{staff}/schedule', [Admin\ScheduleController::class, 'show'])->name('schedule.show');
        Route::post('staff/{staff}/rules', [Admin\ScheduleController::class, 'storeRule'])->name('schedule.rules.store');
        Route::delete('staff/{staff}/rules/{rule}', [Admin\ScheduleController::class, 'destroyRule'])
            ->name('schedule.rules.destroy');
        Route::post('staff/{staff}/exceptions', [Admin\ScheduleController::class, 'storeException'])
            ->name('schedule.exceptions.store');
        Route::delete('staff/{staff}/exceptions/{exception}', [Admin\ScheduleController::class, 'destroyException'])
            ->name('schedule.exceptions.destroy');
    });
});

/*
|--------------------------------------------------------------------------
| Stripe webhook
|--------------------------------------------------------------------------
|
| Unauthenticated by design; the signature check in Cashier's
| VerifyWebhookSignature middleware is what makes it safe. Kept outside the
| versioned prefix because the URL is registered in the Stripe dashboard.
*/

Route::post('stripe/webhook', [StripeWebhookController::class, 'handleWebhook'])
    ->name('cashier.webhook');
