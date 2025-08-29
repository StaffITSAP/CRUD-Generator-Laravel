<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Console\Events\CommandFinished;
use App\Listeners\CrudAutoGenerator;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        CommandFinished::class => [
            CrudAutoGenerator::class,
        ],
    ];
}
