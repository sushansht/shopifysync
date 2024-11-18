<?php

namespace dpl\ShopifySync\Services;

use App\Jobs\InventorySyncJob;
use App\Jobs\ProductUploadJob;
use App\Models\CollectionLinks;
use App\Models\EtsyConfigurationOption;
use App\Models\EtsyProduct;
use App\Models\EtsyProductVariation;
use App\Models\Feed;
use App\Models\Product;
use App\Models\ShopifyCollection;
use App\Services\ProductUpload\ProductUploadService;

class DataModelMappingService
{
    protected $shop;

    public function __construct($shop)
    {
        $this->shop = $shop;
    }

    public function handleMappingAndCreation($data)
    {
        $variationImageList = [];
        $optionData = [];
        $totalQuantity = 0;
        $price = 0;
        $continueSelling = false;
        $shouldSyncImage = true;
        $isVariationProduct = true;
        $shopifyProductVariations = $data['variants'];

        $productMapping = self::mapDataToProductModel($data,$optionData);
        $variationMapping = self::mapDataToVariationModel($data, $variationImageList,$totalQuantity,$continueSelling);
        $imageList = self::formatImageObjects($data['images'], $variationImageList);
        $metafields = self::processMetafields($data['metafields']);
        $attributes = [];
        $attributes['images'] = $imageList;
        $attributes['product_type'] = $productMapping['product_type'];
        $attributes['options'] = $productMapping['options'];

        $productMapping['stock_quantity'] = $totalQuantity;
        $productMapping['price'] = $price;
        if(count($shopifyProductVariations) == 1
            && ($shopifyProductVariations[0]['title'] == 'Default Title'
            || $shopifyProductVariations[0]['title'] == 'Default'))
        {
            $isVariationProduct = false;
            $singleVariant = $shopifyProductVariations[0];
            $variant_id = preg_match('/ProductVariant\/(\d+)/', $singleVariant['id'], $matches) ? $matches[1] : null;
            $inventoryPolicy = self::checkInventoryPolicy($singleVariant['inventoryPolicy'], $singleVariant['inventoryItem']);
            $productMapping["price"] = $singleVariant['price'];
            $productMapping["inventory_tracking"] = $inventoryPolicy;
            $productMapping["stock_quantity"] = $singleVariant['inventoryQuantity'];
            $productMapping["variant_id"] = $variant_id;
            $productMapping["sku"] = !empty($variant['sku']) ? (strlen($singleVariant['sku']) > 32 ? 'ET-'.$variant_id : preg_replace('/[\n|\r|+|\'|\"|$|^]/m', '', trim($singleVariant['sku']))) : 'ET-'.$variant_id;
            $attributes = $variationMapping[0];
            $attributes['title'] = $singleVariant['title'];
            $attributes['inventory_quantity'] = $singleVariant['inventoryQuantity'];
            $attributes['images'] = $imageList;
            $attributes['product_type'] = $productMapping['product_type'];
            $attributes['options'] = $productMapping['options'];

        }
        $productMapping['attributes'] = json_encode($attributes);
        $productMapping['metafields'] = count($metafields) ? json_encode($metafields) : 'NULL';
        $dbProducts = Product::where([
                'shop_id' => $this->shop->id,
                'shopify_id' => $productMapping['shopify_id']
                ])->get();
        $parentDbProduct = $dbProducts->firstWhere('parent_id', 0);
        if($dbProducts && $parentDbProduct){
            $shouldSyncImage = self::checkIfImageIsChanged($parentDbProduct,$imageList);
        }

        $productRow = Product::updateOrCreate(
            [
                'shop_id' => $this->shop->id,
                'shopify_id' => $productMapping['shopify_id'],
            ],
            $productMapping
        );

        if ($productRow && count($variationMapping) && $isVariationProduct) {
            foreach ($variationMapping as $variation) {
                $variation['parent_id'] = $productRow->id;
                Product::updateOrCreate(
                    [
                        'shop_id' => $this->shop->id,
                        'shopify_id' => $variation['shopify_id'],
                        'parent_id' => $variation['parent_id'],
                        'variant_id' => $variation['variant_id']
                    ],
                    $variation
                );
            }
        }

        //Check if Product is linked and set update job accordingly
        $linkedListing = EtsyProduct::select(['id', 'listing_id', 'item_id'])
                        ->where([
                            'created_by' => $this->shop->created_by,
                            'item_id' => $productRow->id
                        ])->first();
        $etsyConfiguration = getEtsyConfiguration($this->shop->created_by,true,true);
        if (intval($etsyConfiguration['order_sync_option']) != 0) {
            self::checkInventorySyncQueue($productMapping,$variationMapping,$dbProducts,$this->shop,$linkedListing);
        }
        $hasUploadedCollection = self::hasUploadedCollection($productRow,$this->shop);
        if (!is_null($linkedListing) || $hasUploadedCollection) {
            // $shouldSendToInactive = false;
            // if ($totalQuantity <= 0 && !$continueSelling) {
            //     $shouldSendToInactive = true;
            // }

            // if (!$shouldSendToInactive) {
                custom_dispatch(new ProductUploadJob($this->shop->id, $productRow->id, 'full', null, false, $shouldSyncImage, false))
                        ->onQueue('product-auto-upload')
                        ->withMeta(['shop_id' => $this->shop->id, 'attribute_id' => $productRow->id]);
            // } else if (!is_null($linkedListing)){
            //     (new ProductUploadService())->setProductInactiveQueue($this->shop->id, $productRow->id, false);
            // }
        }

        $this->shop->update([
            'last_feed_refresh' => now('UTC')->format('Y-m-d H:i:s'),
            'is_bulk_query_in_progress' => false
        ]);
    return $productRow->id;
    }

    public function mapDataToProductModel($data,&$optionData)
    {
        if (isset($data['product']) && count($data['product'])) {
            $shopifyProduct = $data['product'];

            foreach ($shopifyProduct['options'] as $key => $options) {
                if (count((array)$options['values']) !== 1
                    || $options['values'][0] !== 'Default Title'
                ) {
                    $optionData['option' . $options['position']] = $options['name'];
                }
            }
            $shopify_id = preg_match('/Product\/(\d+)/', $shopifyProduct['id'], $matches) ? $matches[1] : null;
            $formattedProductData = [
                'title' => preg_replace('/\r\n/', '', $shopifyProduct['title']),
                'description' => $shopifyProduct['description'],
                'shopify_handle' => $shopifyProduct['handle'],
                'shopify_id' => $shopify_id,
                'shop_id' => $this->shop->id,
                'product_type' => $shopifyProduct['productType'],
                'vendor' => $shopifyProduct['vendor'],
                'shop_id' => $this->shop->id,
                'sku' => 'ET-' . $shopify_id,
                'state' => $shopifyProduct['status'] == 'ACTIVE' ? 1 : -2,
                'parent_shopify_id' => 0,
                'tags' => isset($shopifyProduct['tags']) ? implode(',', $shopifyProduct['tags']) : '',
                'published_at' => $shopifyProduct['publishedAt'],
                'variant_id' => 0,
                'options' => $shopifyProduct['options']
            ];
            return $formattedProductData;
        }
        return false;
    }

    public function mapDataToVariationModel($data, &$variationImageList,&$totalQuantity,&$continueSelling)
    {
        $shopifyProductVariations = $data['variants'];
        $shopifyProduct = $data['product'];
        $formattedVariationData = [];

        if (isset($shopifyProductVariations) && count($shopifyProductVariations)) {
            foreach ($shopifyProductVariations as $variant) {

                $attributesData = $variant;
                $variantTitle = $variant['title'];
                $inventoryPolicy = self::checkInventoryPolicy($variant['inventoryPolicy'], $variant['inventoryItem']);
                $continueSelling = $inventoryPolicy == 0 ? true : $continueSelling;
                $variant_id = preg_match('/ProductVariant\/(\d+)/', $variant['id'], $matches) ? $matches[1] : null;
                $shopify_id = preg_match('/Product\/(\d+)/', $variant['__parentId'], $matches) ? $matches[1] : null;
                $inventory_item_id = preg_match('/InventoryItem\/(\d+)/', $variant['inventoryItem']['id'], $matches) ? $matches[1] : null;
                $weightData = $variant['inventoryItem']['measurement']['weight'];


                //Prepare Attributes for Variation
                if (strpos($variant['title'], '/') !== false) {
                    $variantTitle = array_map('trim', explode('/', $variant['title']));
                }

                $attributesData['id'] = $inventory_item_id;
                $attributesData['vendor'] = $data['product']['vendor'];
                $attributesData['product_type'] = $data['product']['productType'];

                foreach ($shopifyProduct['options'] as $key => $option) {
                    foreach($option['values'] as $value)
                    {
                        if(is_string($variantTitle) && $variantTitle == $value){
                            $attributesData['variations'][$option['name']] = $value;
                        }
                        if(is_array($variantTitle) && in_array($value,$variantTitle)){
                            $attributesData['variations'][$option['name']] = $value;
                        }
                    }
                }

                $attributes = self::formatVariationAttributes($attributesData);
                //Prepare Attributes for Variation ends

                $totalQuantity += $variant['inventoryQuantity'];

                $formattedVariationData[] = [
                    'shopify_id' => $shopify_id,
                    'variant_id' => $variant_id,
                    'sku' =>  !empty($variant['sku']) ? (strlen($variant['sku']) > 32 ? 'ET-'.$variant_id : preg_replace('/[\n|\r|+|\'|\"|$|^]/m', '', trim($variant['sku']))) : 'ET-'.$variant_id,
                    'shop_id' => $this->shop->id,
                    'shopify_handle' => $shopifyProduct['handle'],
                    'title' => $shopifyProduct['title'],
                    'state' => $shopifyProduct['status'] == 'ACTIVE' ? 1 : -2,
                    'description' => $shopifyProduct['description'],
                    'parent_id' => 0,
                    'attributes' => json_encode($attributes),
                    'price' => $variant['price'],
                    'stock_quantity' => $variant['inventoryQuantity'],
                    'parent_shopify_id' => $shopify_id,
                    'tags' => isset($shopifyProduct['tags']) ? implode(',', $shopifyProduct['tags']) : '',
                    'vendor' => $shopifyProduct['vendor'],
                    'inventory_tracking' => $inventoryPolicy,
                    'published_at' => $shopifyProduct['publishedAt'],
                    'weight' => $weightData['value'],
                    'weight_unit' =>  $weightData['unit'],
                    'inventory_item_id' => $inventory_item_id
                ];
                // Check if the variant has an 'image' field with both ID and URL
                if (isset($variant['image']['id']) && isset($variant['image']['url'])) {
                    $image_id = preg_match('/ProductImage\/(\d+)/', $variant['image']['id'], $imageMatches) ? $imageMatches[1] : null;
                    $image_url = $variant['image']['url']; // Get the image URL
                    if ($image_id && count($variationImageList) <= 10) {
                        $variationImageList[] = [
                            'id' => $image_id,
                            'src' => $image_url,
                            'alt' => $variant['image']['altText'],
                            'variant_id' => $variant_id
                        ];
                    }
                }
            }

            return $formattedVariationData;
        }
        return false;
    }

    public function checkInventoryPolicy($inventoryPolicy, $inventoryItem)
    {
        if (isset($inventoryPolicy) && $inventoryPolicy == 'CONTINUE') {
            return 0;
        }
        if (array_key_exists('tracked', $inventoryItem) && $inventoryItem['tracked'] != true) {
            return 0;
        }
        return 1;
    }

    //Formatting
    public function formatImageObjects($imageData, $variantImages)
    {
        $productImages = [];
        $finalImages = [];

        // Format product images
        $productImages = array_map(function ($image) {
            return ['src' => $image['image']['url'], 'alt' => $image['alt'], 'variant_ids' => ""];
        }, $imageData);

        // Merge variant images with same URL by combining variant IDs
        $uniqueVariantImages = [];
        foreach ($variantImages as $variantImage) {
            $url = $variantImage['src'];

            // Initialize unique variant image if it doesn't exist
            if (!isset($uniqueVariantImages[$url])) {
                $uniqueVariantImages[$url] = [
                    'src' => $url,
                    'alt' => $variantImage['alt'], // Store the first non-empty alt text
                    'variant_ids' => $variantImage['variant_id'] // Start with the first variant_id
                ];
            } else {
                // Merge variant IDs for the same URL
                $uniqueVariantImages[$url]['variant_ids'] .= ',' . $variantImage['variant_id'];

                // Only update alt if it is still empty
                if (empty($uniqueVariantImages[$url]['alt'])) {
                    $uniqueVariantImages[$url]['alt'] = $variantImage['alt'];
                }
            }
        }

        // Get the first product image, then merge up to 9 variant images
        $finalImages = [array_shift($productImages)];

        // Prepare variant images array
        $variantImages = array_values($uniqueVariantImages);
        $variantImages = array_slice($variantImages, 0, 9);
        $finalImages = array_merge($finalImages, $variantImages);

        // Fill remaining slots with product images, excluding ones with the same URL as variant images
        $remainingSlots = 10 - count($finalImages);
        $filteredProductImages = array_filter($productImages, function ($productImage) use ($variantImages) {
            return !in_array($productImage['src'], array_column($variantImages, 'url'));
        });

        // Merge final images with the filtered product images
        return array_merge($finalImages, array_slice($filteredProductImages, 0, $remainingSlots));
    }

    public function formatVariationAttributes($attributes)
    {
        $keyMapping = [
            'inventoryQuantity' => 'inventory_quantity',
            'inventoryPolicy' => 'inventory_policy',
            'availableForSale' => 'available_for_sale',
        ];

        $formattedAttributes = $attributes;
        foreach ($keyMapping as $originalKey => $formattedKey) {
            if (isset($attributes[$originalKey])) {
                $formattedAttributes[$formattedKey] = $attributes[$originalKey];
                unset($formattedAttributes[$originalKey]);
            }
        }

        return $formattedAttributes;
    }

    public function checkIfImageIsChanged($product, $imageUrl) {
        $attributes = json_decode($product->attributes, true);
        $current_images = isset($attributes['images']) ? $attributes['images'] : false;
        if (!$current_images) {
            return true;
        }


        if(is_array($current_images) && is_array($imageUrl)) {
            if (checkIfMultiDimArraySame($current_images, $imageUrl)) {
                return false;
            }
        }
        return true;
    }

    public function checkInventorySyncQueue($shopifyProduct, $shopifyVariations, $existingProducts, $shop, $linkedListing)
    {
        $existingProductsGrouped = [];

        foreach ($existingProducts as $existingProduct) {
            $existingProductsGrouped[$existingProduct->variant_id] = $existingProduct;
        }

        $dbShopifyProduct = Product::select('id')
            ->where(['shop_id' => $shop->id, 'shopify_id' => $shopifyProduct['shopify_id']])
            ->first();

        $itemId = $dbShopifyProduct->id ?? null;
        $changedSkus = [];
        $skusQuantity = [];
        $changedVariantIds = [];
        $variationInventories = [];

        foreach ($shopifyVariations as $variant) {
            if (isset($existingProductsGrouped[$variant['variant_id']])) {
                $existingVariant = $existingProductsGrouped[$variant['variant_id']];

                if ($variant['stock_quantity'] != $existingVariant->stock_quantity) {
                    $thisVariantSku = (strlen($variant['sku']) > 32 || $variant['sku'] == "")
                        ? 'ET-' . $variant['id']
                        : preg_replace('/[\n|\r|+|\'|\"|$|^]/m', '', trim($variant['sku']));

                    $changedSkus[] = $thisVariantSku;
                    $skusQuantity[$thisVariantSku] = $variant['stock_quantity'];
                    $changedVariantIds[] = $variant['variant_id'];
                    $variationInventories[$variant['variant_id']] = $variant['stock_quantity'];
                }
            }
        }

        if (count($changedSkus)) {
            $uniqueChangedSkus = implode("','", array_unique($changedSkus));
            $duplicates = 0;

            if (!is_null($linkedListing) && !is_null($linkedListing->listing_id)) {
                $duplicates = EtsyProductVariation::where(['shop_id' => $shop->id, 'created_by' => $shop->created_by])
                    ->where('listing_id', '!=', $linkedListing->listing_id)
                    ->whereRaw("`sku` in ('{$uniqueChangedSkus}')")
                    ->count();

                if ($duplicates == 0) {
                    $duplicates = EtsyProduct::where(['has_variants' => 0, 'created_by' => $shop->created_by])
                        ->where('item_id', '!=', $linkedListing->item_id)
                        ->whereRaw("`sku` in ('{$uniqueChangedSkus}')")
                        ->count();
                }
            } else {
                $duplicates = EtsyProductVariation::where(['shop_id' => $shop->id, 'created_by' => $shop->created_by])
                    ->whereRaw("`sku` in ('{$uniqueChangedSkus}')")
                    ->count();

                if ($duplicates == 0) {
                    $duplicates = EtsyProduct::where(['has_variants' => 0, 'created_by' => $shop->created_by])
                        ->whereRaw("`sku` in ('{$uniqueChangedSkus}')")
                        ->count();
                }
            }
            if ($duplicates > 0) {
                $skus = explode(',', $uniqueChangedSkus);
                $excludeItemId = $itemId;
                foreach ($skus as $sku) {
                    if (isset($skusQuantity[$sku])) {
                        custom_dispatch(new InventorySyncJob($this->shop->id, $sku, (int) $skusQuantity[$sku],$excludeItemId))
                                                ->onQueue('inventory-sync-job')
                                                ->withMeta(['shop_id' => $this->shop->id, 'attribute_id' => $sku]);
                    }
                }
            }
        }
    }

    public function hasUploadedCollection($product,$shop)
    {
        $uploadedCollections = self::getUploadedCollection($shop);
        $productCollection = self::collectionAssigned($shop->id,$product);
        if($product->id){
            foreach ($productCollection as $item) {
                if (in_array($item->collection, $uploadedCollections)) {
                    return true;
                }
            }
        }
        return false;
    }

    public function getUploadedCollection($shop)
    {
        $categoryResult = ShopifyCollection::where('shop_id', $shop->id)
        ->where('submit_status', 1)
        ->pluck('id')
        ->toArray();

        $feedResults = Feed::where('created_by', $shop->created_by)
            ->where('submit_status', 1)
            ->where('feed_type', 0)
            ->pluck('category')
            ->toArray();

        $result = array_unique(array_merge($categoryResult, $feedResults));
        return $result;
    }

    public function collectionAssigned($shop_id,$product)
    {

        $collections = CollectionLinks::where('shop_id', $shop_id)
            ->where('product_id', $product->shopify_id)
            ->select('local_id as collection')
            ->get();

        return $collections;
    }

    public function processMetafields($metafields)
    {
        $preparedMetafields = [];
        if(count($metafields) > 0){
            foreach($metafields as $metafield){
               $preparedMetafields[$metafield['key']] = $metafield['value'];
            }
        }
        return $preparedMetafields;
    }
}
