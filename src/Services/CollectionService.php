<?php

namespace dpl\ShopifySync\Services;

class CollectionService {


    public function processCollection (
            $collectionData, 
            $collectionType, 
            $specifier,
            $token
        ) {
        $collections = [];
        $collectionProcessingClass = config('shopifysync.collection_saving_service');
        $collectionProcessingFunction = config('shopifysync.collection_saving_function');
        $shopifyGqlClient = new Graphql($specifier, $token);

         foreach ($collectionData['edges'] as $edge) {
            $collection = [];
            $nodeData = $edge['node'];
            $collectionGidRegex = '/gid:\\/\\/shopify\\/Collection\\/(\\d+)/';
            $remoteId = null;
            if (preg_match($collectionGidRegex, $nodeData['id'], $matches)) {
                $remoteId = $matches[1];
            }

            $collection = [
                'title' => $nodeData['title'],
                'remote_id' => $remoteId,
                'type' => $collectionType,
                'tally' => $nodeData['productsCount']['count'],
                'products' => []
            ];

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
                $collectionGqlService = new CollectionGqlService($shopifyGqlClient,$specifier, $token);
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

            $collectionService = new $collectionProcessingClass($specifier);
            $collectionService->$collectionProcessingFunction($collectionData,$collection);
        }
    }
}