<?php
namespace dpl\ShopifySync\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DownloadBulkFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $jsonl_file_url,$specifier;
    /**
     * Create a new job instance.
     */
    public function __construct($jsonl_file_url,$specifier)
    {
        $this->jsonl_file_url = $jsonl_file_url;
        $this->specifier = $specifier;
    }
    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $folderName = 'bulk_products_jsonl';
        $fileName = $this->specifier."-bulk-product.jsonl";
        $saveFileToStorage = downloadJsonlFile($this->jsonl_file_url,$fileName,$folderName);
        if($saveFileToStorage){
            $filePath = $folderName.'/'.$fileName;
            ProcessBulkProductFileJob::dispatch($this->specifier, $filePath)->onQueue('shopifysync-process-file');
        }
    }
}
