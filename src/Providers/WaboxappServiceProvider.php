<?php

namespace BotMan\Drivers\Waboxapp\Providers;

use BotMan\Drivers\Waboxapp\WaboxappDriver;
use Illuminate\Support\ServiceProvider;
use BotMan\BotMan\Drivers\DriverManager;
use BotMan\Studio\Providers\StudioServiceProvider;

class WaboxappServiceProvider extends ServiceProvider
{
    /**
     * Perform post-registration booting of services.
     *
     * @return void
     */
    public function boot()
    {
        if (! $this->isRunningInBotManStudio()) {
            $this->loadDrivers();

            $this->publishes([
                __DIR__.'/../../stubs/waboxapp.php' => config_path('botman/waboxapp.php'),
            ]);

            $this->mergeConfigFrom(__DIR__.'/../../stubs/waboxapp.php', 'botman.waboxapp');
        }
    }

    /**
     * Load BotMan drivers.
     */
    protected function loadDrivers()
    {
        DriverManager::loadDriver(WaboxappDriver::class);
    }

    /**
     * @return bool
     */
    protected function isRunningInBotManStudio()
    {
        return class_exists(StudioServiceProvider::class);
    }
}
