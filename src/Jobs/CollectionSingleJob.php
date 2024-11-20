<?php

namespace dpl\ShopifySync\Jobs;

use dpl\ShopifySync\Services\CollectionGqlService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Shopify\Clients\Graphql;

class CollectionSingleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $specifier;
    protected $token;
    protected $collection;
    protected $collectionType;

    /**
     * Create a new job instance.
     */
    public function __construct($specifier, $token, $collection, $collectionType)
    {
        $this->specifier = $specifier;
        $this->token = $token;
        $this->collection = $collection;
        $this->collectionType = $collectionType;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $nodeData = $this->collection['node'];

        $collectionGidRegex = '/gid:\\/\\/shopify\\/Collection\\/(\\d+)/';
          $remoteId = null;
            if (preg_match($collectionGidRegex, $nodeData['id'], $matches)) {
                $remoteId = $matches[1];
            }

             $collection = [
                'title' => $nodeData['title'],
                'remote_id' => $remoteId,
                'type' => $this->collectionType,
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

            $product_has_next_page =  $nodeData['products']['pageInfo']['hasNextPage'];
            while ($product_has_next_page) {
                $endCursor = $nodeData['products']['pageInfo']['endCursor'];
                $collectionGqlService = new CollectionGqlService($shopifyGqlClient,$this->specifier, $this->token);
                $collectionData = $collectionGqlService->getSingleCollectionWithProducts($endCursor,$collection['remote_id']);
                foreach($collectionData['edges']['nodes']['products']['nodes'] as $product){
                    $productId = null;
                    $productGidRegex = '/gid:\\/\\/shopify\\/Product\\/(\\d+)/';
                    if (preg_match($productGidRegex, $product['id'], $matches)) {
                        $productId = $matches[1];
                        $collection['products'][] = $productId;
                    }
                }
            }

            $collectionProcessingClass = config('shopifysync.collection_saving_service');
            $collectionProcessingFunction = config('shopifysync.collection_saving_function');
            $collectionService = new $collectionProcessingClass();
            $collectionService->$collectionProcessingFunction($this->specifier,$collection);
    }
}
