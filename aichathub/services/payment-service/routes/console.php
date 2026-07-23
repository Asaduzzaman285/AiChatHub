<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Standard console commands

// Requires `schedule:work` running continuously (see the payment-scheduler
// container in docker-compose.yml) — schedule:run alone only fires what's due
// at the moment it's invoked.
Schedule::command('bkash:reconcile')->everyFifteenMinutes();
