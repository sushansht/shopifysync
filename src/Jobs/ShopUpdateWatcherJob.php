<?php

namespace dpl\ShopifySync\Jobs;

use dpl\ShopifySync\Services\CollectionCountGqlService;
use dpl\ShopifySync\Services\ProductCountGqlService;
use dpl\ShopifySync\Models\ShopifySyncShop;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Shopify\Clients\Graphql;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

class ShopUpdateWatcherJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $start;

    public $end;

    public $uniqueFor = 1;

    /**
     * Create a new job instance.
     */
    public function __construct($start,$end)
    {
        $this->start = $start;
        $this->end = $end;
    }

    public function uniqueId()
    {
        return $this->start;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $conditions = config('shopifysync.active_shop_query');
            $shopModel = config('shopifysync.shop_model');
            $token_model = config('shopifysync.token_model');
            $token_column = config('shopifysync.token_column');
            $specifier_column = config('shopifysync.specifier_column');
            $last_updated_model = config('shopifysync.last_updated_model');
            $last_updated_column = config('shopifysync.last_updated_column');
            $shops = $shopModel::where($conditions)->skip($this->start-1)->take($this->end)->get();
            foreach ($shops as $shop) {
                $sync_shop = ShopifySyncShop::where('specifier', $shop->specifier)->first();
                $token = $token_model::select($token_column)->where('specifier', $shop->$specifier_column)->first();
                $token = $token->$token_column;
                $specifier = $shop->$specifier_column;

                if ($sync_shop) {
                    $product_processed_at = $sync_shop->product_processed_at;
                    $collection_processed_at = $sync_shop->collection_processed_at;
                } else {
                    $last_updated = $last_updated_model::where($specifier_column, $specifier)->first();
                    if ($last_updated) {
                        $last_updated_at = $last_updated->$last_updated_column;
                    } else {
                        $last_updated_at = null;
                    }
                    $sync_shop =  ShopifySyncShop::create([
                        'specifier' => $shop->specifier,
                        'product_processed_at' => $last_updated_at,
                        'collection_processed_at' => $last_updated_at,
                        'in_process' => 0
                    ]);
                    $product_processed_at = $last_updated_at;
                    $collection_processed_at = $last_updated_at;
                }

                if ($sync_shop->is_bulk_query_in_progress) {
                    return;
                }

                $shopifyGqlClient = new Graphql($specifier, $token);
                $productCountService = new ProductCountGqlService($shopifyGqlClient);
                $product_processed_at = is_null($product_processed_at) ? null : $product_processed_at;
                $current_processed_time = new \DateTime("now", new \DateTimeZone("UTC"));
                $current_processed_time = $current_processed_time->format('Y-m-d H:i:s');
                $productCount = $productCountService->getProductsCount($product_processed_at, $current_processed_time);

                if($productCount > 0){
                    Bus::chain([
                            new RequestBulkQueryJob($specifier, $token, $product_processed_at, $current_processed_time),
                            new PollBulkQueryJob($specifier, $token),
                            new DownloadBulkFileJob($specifier, $token),
                            new ProcessBulkProductFileJob($specifier, $current_processed_time),
                            function () use ($current_processed_time, $specifier)  {
                            $sync_shop = ShopifySyncShop::where('specifier', $specifier)->update([
                                    'is_bulk_query_in_progress' => 0,
                                    'product_processed_at' => $current_processed_time
                                    ]);
                            }
                    ])->catch(function (Throwable $e) use ($specifier, $sync_shop) {
                        Log::channel('shopify-sync')->error("error : {error}, shop : {shop}",[
                            'shop' => $specifier,
                            'error' => $e->getMessage()
                        ]);
                        $sync_shop->update([
                            'is_bulk_query_in_progress' => 0
                        ]);
                    })->onQueue('shopifysync-product-sync')
                    ->dispatch();

                    $sync_shop->update([
                        'is_bulk_query_in_progress' => 1
                    ]);
                    return;
                }

                //Get collection Count
                $collectionCountService = new CollectionCountGqlService($shopifyGqlClient);
                $collectionCount = $collectionCountService->getCollectionCount($collection_processed_at, $current_processed_time);
                
                if($collectionCount > 0){
                    CollectionFetchAndProcessJob::dispatch($specifier, $token, $collection_processed_at, $current_processed_time)
                        ->onQueue('shopifysync-collection-sync');
                }
            

                if ($productCount <= 0 && $collectionCount <= 0) {
                    $sync_shop->update([
                        'product_processed_at' => $current_processed_time,
                        'collection_processed_at' => $current_processed_time,
                    ]);
                } 
            }
    } catch  (Exception $e) {
        Log::channel('shopify-sync')->error("Error on update watch and add job. error : {error}",[
            'error' => $e->getMessage()
        ]);
    }
    }
}
