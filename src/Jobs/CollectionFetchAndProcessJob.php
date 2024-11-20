<?php

namespace dpl\ShopifySync\Jobs;

use dpl\ShopifySync\Services\CollectionGqlService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Shopify\Clients\Graphql;

class CollectionFetchAndProcessJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $specifier;
    protected $token;
    protected $lastFeedRefresh;
    protected $cursor;
    protected $collectionType;
    protected $isFirstPage;

    /**
     * Create a new job instance.
     */
    public function __construct($specifier, $token,$lastFeedRefresh=null,$cursor=null,$collectionType,$isFirstPage=true)
    {
        $this->specifier = $specifier;
        $this->token = $token;
        $this->lastFeedRefresh = $lastFeedRefresh;
        $this->cursor = $cursor;
        $this->collectionType = $collectionType;
        $this->isFirstPage = $isFirstPage;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $shopifyGqlClient = new Graphql($this->specifier, $this->token);
        $collectionGqlService = new CollectionGqlService($shopifyGqlClient,$this->specifier, $this->token);
        $collectionData = $collectionGqlService->getCollections($this->lastFeedRefresh,$this->cursor,$this->collectionType);
        $collections = $collectionData['edges'];

        foreach ($collections as $collection) {
            $id = $collection['node']['id'];
            CollectionSingleJob::dispatch($this->specifier, $this->token, $collection, $this->collectionType)->onQueue('shopifysync-collection-single');
        }

        if ($collectionData['pageInfo']['hasNextPage']) {
             CollectionFetchAndProcessJob::dispatch($this->specifier, $this->token, $this->lastFeedRefresh ,$collectionData['pageInfo']['endCursor'],$this->collectionType);
        }
    }
}
