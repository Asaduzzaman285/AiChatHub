<?php

namespace App\Providers;

use App\Events\UserRegistered;
use App\Listeners\SendVerificationEmail;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        UserRegistered::class => [
            SendVerificationEmail::class,
        ],
    ];

    public function boot(): void
    {
        parent::boot();
    }
}
