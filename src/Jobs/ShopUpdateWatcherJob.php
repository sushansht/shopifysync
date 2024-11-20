<?php

namespace dpl\ShopifySync\Jobs;

use dpl\ShopifySync\Services\CollectionCountGqlService;
use dpl\ShopifySync\Services\ProductCountGqlService;
use DateTime;
use DateTimeZone;
use dpl\ShopifySync\Models\ShopifySyncShop;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Shopify\Clients\Graphql;
use Illuminate\Contracts\Queue\ShouldBeUnique;

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
            $token = $token_model::select($token_column)->where('specifier', $shop->$specifier_column)->first();
            $token = $token->$token_column;
            $specifier = $shop->$specifier_column;
            $sync_shop = ShopifySyncShop::where('specifier', $shop->specifier)->first();

            if ($sync_shop) {
                $last_processed_at = $sync_shop->last_processed_at;
            } else {
               $sync_shop =  ShopifySyncShop::create([
                    'specifier' => $shop->specifier,
                    'last_processed_at' => null,
                    'in_process' => 0
                ]);
                $last_processed_at = null;
            }

            if ($sync_shop->is_bulk_query_in_progress) {
                return;
            }

            $shopifyGqlClient = new Graphql($specifier, $token);
            $productCountService = new ProductCountGqlService($shopifyGqlClient);
            $last_processed_at = is_null($sync_shop->last_processed_at) ? null : $sync_shop->last_processed_at;
            $productCount = $productCountService->getProductsCount($last_processed_at);
            if($productCount > 0){
                RequestBulkQueryJob::dispatch($specifier, $token, $last_processed_at)->onQueue('shopifysync-request-bulkquery');
                $sync_shop->update([
                    'is_bulk_query_in_progress' => 1
                ]);
                return;
            }

            //Get collection Count
            $collectionCountService = new CollectionCountGqlService($shopifyGqlClient);
            $smartCollectionCount = $collectionCountService->getCollectionCount($last_processed_at,"smart");
            $customCollectionCount = $collectionCountService->getCollectionCount($last_processed_at,"custom");
            if($smartCollectionCount > 0){
                CollectionFetchAndProcessJob::dispatch($sync_shop, $token, $last_processed_at,$cursor=null,$collectionType="smart")->onQueue('shopifysync-request-bulkquery');
            }
            if($customCollectionCount > 0){
                CollectionFetchAndProcessJob::dispatch($sync_shop, $token, $last_processed_at,$cursor=null,$collectionType="custom")->onQueue('shopifysync-request-bulkquery');
            }
        }
    }
}
