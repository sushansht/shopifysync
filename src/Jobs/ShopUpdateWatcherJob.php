<?php

namespace dpl\ShopifySync\Jobs;

use dpl\ShopifySync\Services\CollectionCountGqlService;
use dpl\ShopifySync\Services\ProductCountGqlService;
use DateTime;
use DateTimeZone;
use dpl\ShopifySync\Models\ShopifySyncShop;
use GuzzleHttp\Psr7\Request;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Shopify\Clients\Graphql;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Bus;
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
        $conditions = config('shopifysync.active_shop_query');
        $shopModel = config('shopifysync.shop_model');
        $token_model = config('shopifysync.token_model');
        $token_column = config('shopifysync.token_column');
        $specifier_column = config('shopifysync.specifier_column');
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
               $sync_shop =  ShopifySyncShop::create([
                    'specifier' => $shop->specifier,
                    'product_processed_at' => null,
                    'collection_processed_at' => null,
                    'in_process' => 0
                ]);
                $product_processed_at = null;
                $collection_processed_at = $sync_shop->collection_processed_at;
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
                ])->catch(function (Throwable $e) {
                    dd($e);
                })->onQueue('product-sync')
                ->dispatch();

                $sync_shop->update([
                    'is_bulk_query_in_progress' => 1
                ]);
                return;
            }

            //Get collection Count
            $collectionCountService = new CollectionCountGqlService($shopifyGqlClient);
            $smartCollectionCount = $collectionCountService->getCollectionCount($collection_processed_at, $current_processed_time, "smart");
            $customCollectionCount = $collectionCountService->getCollectionCount($collection_processed_at, $current_processed_time, "custom");
            $collection_chain = [];
            if($smartCollectionCount > 0){
                $collection_chain[] = new CollectionFetchAndProcessJob($sync_shop, $token, $collection_processed_at, $current_processed_time, $cursor=null, $collectionType="smart");
            }
            if($customCollectionCount > 0){
                $collection_chain[] = new CollectionFetchAndProcessJob($sync_shop, $token, $collection_processed_at, $current_processed_time, $cursor=null, $collectionType="custom");
            }

            if (count($collection_chain) > 0) {
                Bus::chain($collection_chain)->catch(function (Throwable $e) {
                    dd($e);
                })->onQueue('collection-sync')
                ->dispatch();
            } 

            if ($productCount <= 0 && $smartCollectionCount <= 0 && $customCollectionCount <= 0) {
                 $sync_shop->update([
                    'product_processed_at' => $current_processed_time,
                    'collection_processed_at' => $current_processed_time,
                    'processed_at' => $current_processed_time
                ]);
            } else {
                 $sync_shop->update([
                    'product_processed_at' => $current_processed_time,
                    'collection_processed_at' => $current_processed_time
                ]);
            }
        }
    }
}
