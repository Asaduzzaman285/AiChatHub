<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Standard console commands

// Requires `php artisan schedule:work` running continuously (see the
// subscription-scheduler docker-compose service) — schedule:run alone only
// fires whatever's due at the exact moment it's invoked, so something has to
// keep calling it every minute.
Schedule::command('renewals:process')->hourly();
