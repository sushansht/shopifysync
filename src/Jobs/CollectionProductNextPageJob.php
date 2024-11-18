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
class CollectionProductNextPageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $shop;
    protected $cursor;
    protected $collectionId;
    protected $collectionType;
    /**
     * Create a new job instance.
     */
    public function __construct($shop,$cursor,$collectionId,$collectionType)
    {
        $this->shop = $shop;
        $this->cursor = $cursor;
        $this->collectionId = $collectionId;
        $this->collectionType = $collectionType;
    }
    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $shopifyGqlClient = new Graphql($this->shop->specifier, $this->shop->token);
        $collectionGqlService = new CollectionGqlService($shopifyGqlClient,$this->shop);
        $collectionData = $collectionGqlService->getSingleCollectionWithProducts($this->cursor,$this->collectionId);
        Log::channel("daily")->info("Collection with product data:". json_encode($collectionData));
        //Handle Collection Creation and Linking
        $collectionService = new ShopifyCollectionService($this->shop);
        $collectionService->storeShopifyCollection($collectionData,$this->collectionType,false);
    }
}
