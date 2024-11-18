<?php

namespace dpl\ShopifySync\Console;

use dpl\ShopifySync\Jobs\ProcessShopSyncJob;
use dpl\ShopifySync\Jobs\ShopUpdateWatcherJob;
use Exception;
use Illuminate\Console\Command;

class AddShopsToQueueCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:update {batchSize=500 : Number of shops per job}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Queue shop';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try{
            $shopModel = config('shopifysync.shop_model');

            $batchSize = (int) $this->argument('batchSize');

            if ($batchSize <= 0) {
                $this->error('Batch size must be a positive integer.');
                return 1;
            }

            $activeShopCount = $shopModel::where(config('shopifysync.active_shop_query'))->count();

            $jobCount = ceil($activeShopCount / $batchSize);

            for ($jobIndex = 0; $jobIndex < $jobCount; $jobIndex++) {
                $start = ($jobIndex * $batchSize)+1;
                $batchTop = ($start + $batchSize)-1 ;
                $end = min($batchTop, $activeShopCount);

                ShopUpdateWatcherJob::dispatch($start, $end)->onQueue('shopifysync-update-watcher');

                $this->info("Dispatched job for shops from {$start} to {$end}");
            }

            $this->info('Finished enqueuing jobs for processing active shops.');
            return 0;
        }
        catch(Exception $e){
            $this->info('Error while queuing shop. error : ' . $e);
        }
    }
}
