<?php
namespace App\Jobs;
use App\Services\Graphql\CollectionGqlService;
use App\Services\ShopifyCollection\ShopifyCollectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Shopify\Clients\Graphql;
class CollectionFetchAndProcessJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $shop;
    protected $lastFeedRefresh;
    protected $cursor;
    protected $collectionType;
    protected $isFirstPage;
    /**
     * Create a new job instance.
     */
    public function __construct($shop,$lastFeedRefresh=null,$cursor=null,$collectionType,$isFirstPage=true)
    {
        $this->shop = $shop;
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
        $shopifyGqlClient = new Graphql($this->shop->specifier, $this->shop->token);
        $collectionGqlService = new CollectionGqlService($shopifyGqlClient,$this->shop);
        $collectionData = $collectionGqlService->getCollections($this->lastFeedRefresh,$this->cursor,$this->collectionType);
        //Handle Collection Creation and Linking
        $collectionService = new ShopifyCollectionService($this->shop);
        $collectionService->storeShopifyCollection($collectionData,$this->collectionType,$this->isFirstPage);
    }
}
