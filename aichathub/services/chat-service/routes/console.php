<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Standard console commands

// Requires `schedule:work` running continuously (see the chat-scheduler container
// in docker-compose.yml) — schedule:run alone only fires what's due at the moment
// it's invoked. Tighter interval than wallet's 15 minutes since the shortest private
// chat duration preset is 60 minutes and the sidebar countdown should feel reasonably
// live, not stale for up to a quarter of the shortest possible chat's whole lifetime.
Schedule::command('chat:expire-private-sessions')->everyFiveMinutes();
