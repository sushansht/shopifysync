<?php
namespace dpl\ShopifySync\Jobs;

use dpl\ShopifySync\Services\JsonlFileReaderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessBulkProductFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $jsonLFilePath, $specifier;
    /**
     * Create a new job instance.
     */
    public function __construct($specifier,$jsonLFilePath)
    {
        $this->jsonLFilePath = $jsonLFilePath;
        $this->specifier = $specifier;
    }
    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $readerService = new JsonlFileReaderService();
        $readerService->processJsonlByProductGid($this->specifier,$this->jsonLFilePath);
        Log::channel("shopify_bulk_query")->info("We should now Read and Save JSONL File");
    }
}
