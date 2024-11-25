<?php
namespace dpl\ShopifySync\Jobs;

use dpl\ShopifySync\Models\ShopBulkQueryOperation;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DownloadBulkFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $token,$specifier;
    /**
     * Create a new job instance.
     */
    public function __construct($specifier, $token)
    {
        $this->token = $token;
        $this->specifier = $specifier;
    }
    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $bulkOperation = ShopBulkQueryOperation::where('specifier', $this->specifier)
                            ->whereNotNull('file_url')
                            ->whereIn('status', ['COMPLETED'])
                            ->orderBy('created_at' , 'desc')
                            ->first();

            if (!$bulkOperation) {
                throw new Exception('Bulk operation not found while downloading file');
            }

            $folderName = 'bulk_products_jsonl';
            $fileName = $this->specifier."-bulk-product.jsonl";
            $saveFileToStorage = downloadJsonlFile($bulkOperation->file_url,$fileName,$folderName);

            if (!$saveFileToStorage) {
                throw new Exception('cannot download and save josnl file '. $fileName);
            }

            $bulkOperation->local_file_path = $folderName.'/'.$fileName;
            $bulkOperation->save();
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }   

    }
}
