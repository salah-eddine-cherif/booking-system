<?php

use Illuminate\Support\Facades\Schedule;

// Reminders are stamped as sent the moment they are queued, so running this often
// is safe and keeps the lead time accurate to within the interval.
Schedule::command('bookings:send-reminders')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Holds are short-lived, so sweep them frequently or slots sit idle.
Schedule::command('bookings:release-holds')
    ->everyMinute()
    ->withoutOverlapping();
