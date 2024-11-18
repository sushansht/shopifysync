<?php

namespace dpl\ShopifySync\Jobs;

use dpl\ShopifySync\Models\ShopBulkQueryOperation;
use dpl\ShopifySync\Services\ProductBulkQueryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Shopify\Clients\Graphql;

class RequestBulkQueryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $specifier;
    protected $token;
    protected $last_processed_at;
    /**
     * Create a new job instance.
     */
    public function __construct(
        $specifier,
        $token,
        $last_processed_at=null
    )
    {
        $this->specifier = $specifier;
        $this->token = $token;
        $this->last_processed_at = $last_processed_at;
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        $shopifyGqlClient = new Graphql($this->specifier, $this->token);

        try {
            $bulkOperationService = new ProductBulkQueryService($shopifyGqlClient);
            $bulkOperationData = $bulkOperationService->runBulkOperation($this->last_processed_at);

            if ($bulkOperationData) {
                ShopBulkQueryOperation::create([
                    'specifier' => $this->specifier,
                    'bulk_query_id' => $bulkOperationData['id'],
                    'status' => $bulkOperationData['status'],
                ]);
            }
        } catch (\Exception $e) {
            return true;
        }
    }
}
