<?php
namespace dpl\ShopifySync\Jobs;

use dpl\ShopifySync\Models\ShopBulkQueryOperation;
use dpl\ShopifySync\Services\BulkOperationPollingService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Shopify\Clients\Graphql;

class PollBulkQueryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 10;

    protected $token,$specifier;
    /**
     * Create a new job instance.
     */
    public function __construct($specifier, $token)
    {
        $this->specifier = $specifier;
        $this->token = $token;
    }
    
    /**
     * Execute the job.
     */
    public function handle()
    {
        try {

            $bulkOperation = ShopBulkQueryOperation::where([
                'specifier' => $this->specifier,
                'file_url' => null,
            ])
            ->whereIn('status', ['CREATED', 'RUNNING'])
            ->orderBy('created_at' , 'desc')
            ->first();

            if (!$bulkOperation) {
                throw new Exception('Bulk operation not found while polling');
            }

            $shopifyGqlClient = new Graphql($bulkOperation->specifier, $this->token);
            $pollingService = new BulkOperationPollingService($shopifyGqlClient);
            $pollingResult = $pollingService->pollAndUpdateBulkQueryStatus($bulkOperation, $this->specifier, $this->token);

            // Update the database with the status and URL
            $bulkOperation->update([
                'status' => $pollingResult['status'],
                'file_url' => $pollingResult['url'] ?? null, // Save file URL if exists
                'completed_at' => $pollingResult['completedAt'] ?? null,
            ]);

            if ($pollingResult['status'] != 'COMPLETED' || !$pollingResult['url']) {
                $this->release(30);
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }
}
