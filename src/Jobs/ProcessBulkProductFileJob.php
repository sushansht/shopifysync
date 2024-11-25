<?php
namespace dpl\ShopifySync\Jobs;

use dpl\ShopifySync\Models\ShopBulkQueryOperation;
use dpl\ShopifySync\Services\JsonlFileReaderService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessBulkProductFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $specifier, $current_processed_time;

    /**
     * Create a new job instance.
     */
    public function __construct($specifier, $current_processed_time)
    {
        $this->specifier = $specifier;
        $this->current_processed_time = $current_processed_time;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $bulkOperation = ShopBulkQueryOperation::where('specifier', $this->specifier)
                        ->whereNotNull('file_url')
                        ->whereNotNull('local_file_path')
                        ->whereIn('status', ['COMPLETED'])
                        ->orderBy('created_at' , 'desc')
                        ->first();
                        
            if (!$bulkOperation) {
                throw new Exception('Bulk Query operation not found while processing the file.');
            }

            $filePath = $bulkOperation->local_file_path;
            $readerService = new JsonlFileReaderService();
            $readerService->processJsonlByProductGid($this->specifier,$filePath);
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
      
    }
}
