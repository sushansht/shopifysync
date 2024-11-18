<?php

namespace dpl\ShopifySync\Console;

use dpl\ShopifySync\Models\ShopBulkQueryOperation;
use dpl\ShopifySync\Services\BulkOperationPollingService;
use Illuminate\Console\Command;
use Shopify\Clients\Graphql;

class PollBulkOperationStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'poll:file';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Poll Started");
        $bulkOperations = ShopBulkQueryOperation::where([
            'file_url' => null
        ])
        ->whereIn('status', ['CREATED', 'RUNNING'])
        ->get();

        $token_model = config('shopifysync.token_model');
        $token_column = config('shopifysync.token_column');

        foreach($bulkOperations as $bulkOperation)
        {
            $token = $token_model::select($token_column)->where('specifier', $bulkOperation->specifier)->first();
            $this->info("Pool Id: ".$bulkOperation->id);
            $shopifyGqlClient = new Graphql($bulkOperation->specifier, $token->token);
            $pollingService = new BulkOperationPollingService($shopifyGqlClient);
            $pollingService->pollAndUpdateBulkQueryStatus($bulkOperation->specifier, $token->token);
        }
        $this->info("Poll Completed");
    }
}
