<?php

namespace dpl\ShopifySync;

use dpl\ShopifySync\Console\AddShopsToQueueCommand;
use dpl\ShopifySync\Console\PollBulkOperationStatus;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class ShopifySyncServiceProvider extends ServiceProvider {
    public function boot()
    {
        // Register the command if we are using the application via the CLI
        if ($this->app->runningInConsole()) {
                $this->app->booted(function () {
                $schedule = $this->app->make(Schedule::class);
                $schedule->command('queue:shop --batchSize=500')->everyMinute();
            });

            $this->commands([
                AddShopsToQueueCommand::class,
                PollBulkOperationStatus::class
            ]);

            $this->publishes([
                __DIR__.'/config/config.php' => config_path('shopifysync.php'),
                ], 'config');

               if (! class_exists('CreateShopifySyncTable')) {
                    $this->publishes([
                    __DIR__ . '/../database/migrations/create_shopify_sync_shop_table.php.stub' => database_path('migrations/' . date('Y_m_d_His', time()) . '_create_shopify_sync_shop_table.php'),
                    __DIR__ . '/../database/migrations/create_shop_bulk_query_operations_table.php.stub' => database_path('migrations/' . date('Y_m_d_His', time()) . '_create_shop_bulk_query_operations_table.php')
                    ], 'migrations');
                }
        }
    }

    public function register()
    {
        // Merge any of your default package config (as needed)
        $this->mergeConfigFrom(__DIR__ . '/config/config.php', 'shopifysync');

        // Next we specifically merge the logging channels provided by this package
        $this->mergeLoggingChannels();
    }

    private function mergeLoggingChannels()
    {

        // This is the custom package logging configuration we just created earlier
        $packageLoggingConfig = require __DIR__ . '/config/logging.php';

        $config = $this->app->make('config');

        // For now we manually merge in only the logging channels. We could also merge other logging config here as well if needed.
        // We do this merging manually since mergeConfigFrom() does not do a deep merge and we want to merge only the channels array
        $config->set('logging.channels', array_merge(
            $packageLoggingConfig['channels'] ?? [],
            $config->get('logging.channels', [])
        ));
    }

}
