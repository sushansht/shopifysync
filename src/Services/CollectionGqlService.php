<?php

namespace dpl\ShopifySync\Services;

use dpl\ShopifySync\Jobs\CollectionFetchAndProcessJob;
use Illuminate\Support\Facades\Log;

class CollectionGqlService
{
    protected $client, $token, $specifier;

    public function __construct($client, $specifier, $token)
    {
        $this->client = $client;
        $this->token = $token;
        $this->specifier = $specifier;
    }
    /**
     * Perform a gql to get products count.
     *
     * @return array|null
     */
        
    public function getCollections($updatedAt,$cursor,$collectionType)
    {
        $queryParam = "first: 25";
        if ($cursor !== null) {
            Log::channel("daily")->info("getCollections: cursor is null, not first page");
            $isFirstPage = false;
            $queryParam .= ", after: \"$cursor\"";
        }
    
        if ($updatedAt !== null) {
            $queryParam .= ", query: \"updated_at:>$updatedAt AND published_status:approved AND collection_type:$collectionType\"";
        } else {
            $queryParam .= ", query: \"published_status:approved AND collection_type:$collectionType\"";
        }
        Log::channel("daily")->info("Query params for collectiongql: ". $queryParam);
        $graphQL = '{
            collections('.$queryParam.') {
                edges {
                    node {
                        id
                        title
                        handle
                        updatedAt
                        sortOrder
                        productsCount {
                            count
                        }
                        products(first: 250) {
                            nodes {
                                id
                            }
                            pageInfo {
                                hasNextPage
                                endCursor
                            }
                        }
                    }
                }
                pageInfo{
                    hasNextPage
                    endCursor
                }
            }
        }';
        try {
                $response = $this->client->query($graphQL);
                $responseData = json_decode($response->getBody()->getContents(), true);

                if (
                    isset($responseData['data']['collections']) &&
                    isset($responseData['data']['collections']['pageInfo']) &&
                    $responseData['data']['collections']['pageInfo']['hasNextPage'] == true
                ) {
                    $endCursor = $responseData['data']['collections']['pageInfo']['endCursor'];
                    CollectionFetchAndProcessJob::dispatch($this->specifier, $this->token, $updatedAt,$endCursor,$collectionType, false)
                                ->onQueue('shopifysync-collection-job');
                }
                        
            return $responseData['data']['collections'] ?? 0;
        } catch (\Exception $e) {
            return 0;
        }
    }
    
    public function getSingleCollectionWithProducts($productCursor,$collectionId)
    {
        $productQueryParam = "first: 250";
        if ($productCursor !== null) {
            $productQueryParam .= ", after: \"$productCursor\"";
        }
        $collectionQueryParam = "first:1,query: \"id:$collectionId\"";
     
        Log::channel("daily")->info("productQueryParam: ". $productQueryParam);
        $graphQL = '{
            collections('.$collectionQueryParam.') {
                edges {
                    node {
                        id
                        title
                        handle
                        updatedAt
                        sortOrder
                        productsCount {
                            count
                        }
                        products('.$productQueryParam.') {
                            nodes {
                                id
                            }
                            pageInfo {
                                hasNextPage
                                endCursor
                            }
                        }
                    }
                }
            }
        }';
        try {
            $response = $this->client->query($graphQL);
            $responseData = json_decode($response->getBody()->getContents(), true);
            return $responseData['data']['collections'] ?? 0;
        } catch (\Exception $e) {
            return 0;
        }
    }
}