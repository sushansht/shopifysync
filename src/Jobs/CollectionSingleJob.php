<?php

namespace dpl\ShopifySync\Jobs;

use dpl\ShopifySync\Services\CollectionGqlService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Shopify\Clients\Graphql;

class CollectionSingleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $specifier;
    protected $token;
    protected $collection;

    /**
     * Create a new job instance.
     */
    public function __construct($specifier, $token, $collection)
    {
        $this->specifier = $specifier;
        $this->token = $token;
        $this->collection = $collection;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $nodeData = $this->collection;
            $collectionGidRegex = '/gid:\\/\\/shopify\\/Collection\\/(\\d+)/';
            $remoteId = null;


            if (preg_match($collectionGidRegex, $nodeData['id'], $matches)) {
                $remoteId = $matches[1];
            }

            $collection = [
                'title' => $nodeData['title'],
                'remote_id' => $remoteId,
                'type' => $nodeData['ruleSet'] == null ? 'custom' : 'smart',
                'tally' => $nodeData['productsCount']['count'],
                'products' => []
            ];


            $shopifyGqlClient = new Graphql($this->specifier, $this->token);

            foreach($nodeData['products']['nodes'] as $product){
                $productId = null;
                $productGidRegex = '/gid:\\/\\/shopify\\/Product\\/(\\d+)/';
                if (preg_match($productGidRegex, $product['id'], $matches)) {
                    $productId = $matches[1];
                    $collection['products'][] = $productId;
                }
            }
            $productPageInfo = $nodeData['products']['pageInfo'];
            $hasNextPage = $productPageInfo['hasNextPage'];
            $cursor = $productPageInfo['endCursor'];
            while ($hasNextPage) {
                $collectionGqlService = new CollectionGqlService($shopifyGqlClient,$this->specifier, $this->token);
                $collectionData = $collectionGqlService->getSingleCollectionWithProducts($cursor,$collection['remote_id']);

                foreach($collectionData['edges']['nodes']['products']['nodes'] as $product){
                    $productId = null;
                    $productGidRegex = '/gid:\\/\\/shopify\\/Product\\/(\\d+)/';
                    if (preg_match($productGidRegex, $product['id'], $matches)) {
                        $productId = $matches[1];
                        $collection['products'][] = $productId;
                    }
                }
                if (
                    isset($collectionData['data']['collections']['pageInfo']) &&
                    $collectionData['data']['collections']['pageInfo']['hasNextPage'] == true
                ) {
                    $cursor = $collectionData['data']['collections']['pageInfo']['endCursor'];
                    $hasNextPage = true;
                } else {
                    $hasNextPage = false;
                }
            }

            $collectionProcessingClass = config('shopifysync.collection_saving_service');
            $collectionProcessingFunction = config('shopifysync.collection_saving_function');
            $collectionService = new $collectionProcessingClass();
            $collectionService->$collectionProcessingFunction($this->specifier,$collection);
        } catch (Exception $e) {
            Log::channel('shopify-sync')->error("Error while fetching and processing collection for {shop} with error : {error}",[
                'shop' => $this->specifier,
                'error' => $e->getMessage()
            ]);
            throw new Exception($e);
        }
    }
}
