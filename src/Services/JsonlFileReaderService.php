<?php

namespace dpl\ShopifySync\Services;

use Exception;
use Illuminate\Support\Facades\Log;

class JsonlFileReaderService
{

    public function processJsonlByProductGid($specifier,$jsonFilePath)
    {
        // $dataModelMappingService = new DataModelMappingService($specifier);
        $dataModelMappingClass = config('shopifysync.data_saving_class');
        $dataModelMappingFunction = config('shopifysync.data_saving_function');

        $currentProductId = null;
        $currentShopifyProduct = "";
        $currentGroup = [
            'product' => null,
            'variants' => [],
            'images' => [],
            'videos' => []
        ];

        // Regular expressions to extract product ID and type
        $productGidRegex = '/gid:\\/\\/shopify\\/Product\\/(\\d+)/';
        $variantGidRegex = '/gid:\\/\\/shopify\\/ProductVariant\\/(\\d+)/';
        $imageGidRegex = '/gid:\\/\\/shopify\\/MediaImage\\/(\\d+)/';
        $videoGidRegex = '/gid:\\/\\/shopify\\/Video\\/(\\d+)/';
        $metafieldGidRegex = '/gid:\\/\\/shopify\\/Metafield\\/(\\d+)/';

        $filePath = storage_path('app/public/'.$jsonFilePath);
        // Check if the file exists
        if (!file_exists($filePath)) {
            Log::channel('shopify_bulk_query')->error("File does not exist: " . $filePath);
            return;
        }

        // Open the JSONL file for reading
        $file = fopen($filePath, 'r');

        if ($file) {
            while (($line = fgets($file)) !== false) {
                $record = json_decode($line, true);

                if (isset($record['id']) && preg_match($productGidRegex, $record['id'], $matches)) {
                    $productId = $matches[1];
                    if ($currentProductId && $currentProductId !== $productId) {
                        (new $dataModelMappingClass($specifier))->$dataModelMappingFunction($currentGroup);
                    }
                    $currentProductId = $productId;
                    $currentShopifyProduct = $record;
                    $currentGroup = [
                        'product' => $record,
                        'variants' => [],
                        'images' => [],
                        'videos' => [],
                        'metafields' => []
                    ];
                } elseif (isset($record['id']) && isset($record['__parentId']) && $record['__parentId'] == $currentShopifyProduct['id']) {
                    // Check if the record is a ProductVariant
                    if (preg_match($variantGidRegex, $record['id'], $matches)) {
                        if ($currentProductId) {
                            $currentGroup['variants'][] = $record; // Store variant
                        }
                    }
                    // Check if the record is a MediaImage
                    elseif (preg_match($imageGidRegex, $record['id'], $matches)) {
                        if ($currentProductId) {
                            $currentGroup['images'][] = $record; // Store image
                        }
                    }
                    // Check if the record is a MediaVideo
                    elseif (preg_match($videoGidRegex, $record['id'], $matches)) {
                        if ($currentProductId) {
                            $currentGroup['videos'][] = $record; // Store video
                        }
                    }

                    // Check if the record is a Metafield
                    elseif (preg_match($metafieldGidRegex, $record['id'], $matches)) {
                        if ($currentProductId) {
                            $currentGroup['metafields'][] = $record; // Store metafields
                        }
                    }
                }
            }

            // Save the last product group after exiting the loop
            if (!empty($currentGroup['product'])) {
                (new $dataModelMappingClass($specifier))->$dataModelMappingFunction($currentGroup);
            }

            fclose($file);
        } else {
            Log::channel('shopify_bulk_query')->error("Could not open file: " . $filePath);
            throw new Exception("Unable to open the file: $filePath");
        }

        return $currentGroup;
    }
}
