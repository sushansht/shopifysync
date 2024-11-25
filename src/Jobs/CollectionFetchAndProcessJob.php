<?php

namespace dpl\ShopifySync\Jobs;

use dpl\ShopifySync\Models\ShopifySyncShop;
use dpl\ShopifySync\Services\CollectionGqlService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Shopify\Clients\Graphql;
use Throwable;

class CollectionFetchAndProcessJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $specifier;
    protected $token;
    protected $collection_processed_at;
    protected $current_processed_time;

    /**
     * Create a new job instance.
     */
    public function __construct(
        $specifier,
        $token,
        $collection_processed_at=null,
        $current_processed_time=null,
    )
    {
        $this->specifier = $specifier;
        $this->token = $token;
        $this->collection_processed_at = $collection_processed_at;
        $this->current_processed_time = $current_processed_time;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $shopifyGqlClient = new Graphql($this->specifier, $this->token);    
            $collectionGqlService = new CollectionGqlService($shopifyGqlClient,$this->specifier, $this->token);
            $cursor = null;
            $hasNextPage = true;

            while ($hasNextPage) {
                 $collectionData = $collectionGqlService->getCollections(
                    $this->collection_processed_at, 
                    $this->current_processed_time,
                    $cursor
                );
                
                $collections = $collectionData['edges'];
                foreach ($collections as $collection) {
                    $collection = $collection['node'];
                    CollectionSingleJob::dispatch(
                        $this->specifier, 
                        $this->token, 
                        $collection, 
                    )->onQueue('shopifysync-collection-single');
                }
                if (
                     isset($responseData['data']['collections']['pageInfo']) &&
                      $responseData['data']['collections']['pageInfo']['hasNextPage'] == true
                ) {
                    $cursor = $responseData['data']['collections']['pageInfo']['endCursor'];
                    $hasNextPage = true;
                } else {
                    $hasNextPage = false;
                }
            }

            $sync_shop = ShopifySyncShop::where('specifier', $this->specifier)->update([
                    'is_bulk_query_in_progress' => 0,
                    'collection_processed_at' => $this->current_processed_time
            ]);
        } catch (Throwable $e) {
            Log::channel('shopify-sync')->error("Error while fetching and processing collection for {shop} with error : {error}",[
                'shop' => $this->specifier,
                'error' => $e->getMessage()
            ]);
            throw new Exception($e);
        }
        
    }
}
