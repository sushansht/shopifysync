<?php

namespace dpl\ShopifySync\Services;

use Exception;

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
        
    public function getCollections($collection_processed_at, $current_processed_time,$cursor)
    {
        $queryParam = "first: 25";
        $queryCondtion = "";
        if ($cursor !== null) {
            $queryParam .= ", after: {$cursor}";
        }
        $queryCondtion = "published_status:approved AND updated_at:<'{$current_processed_time}'";

        if ($collection_processed_at !== null) {
            $queryCondtion .= " AND updated_at:>'{$collection_processed_at}'";
        } 

        $graphQL =  <<<QUERY
            query {
                collections({$queryParam}, query: "{$queryCondtion}") {
                    edges {
                        node {
                            id
                            title
                            handle
                            updatedAt
                            sortOrder
                            ruleSet {
                                rules {
                                    condition
                                }
                            }
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
            }
        QUERY;
        $response = $this->client->query($graphQL);
        $responseData = json_decode($response->getBody()->getContents(), true);
        if (!empty($responseData['errors'])) {
            throw new Exception("Collections query error :".  json_encode($response['errors']));
        }
        return $responseData['data']['collections'] ?? []; 
    }
    
    public function getSingleCollectionWithProducts($productCursor, $collectionId)
    {
        $queryParam = "first: 250";

        if ($productCursor !== null) {
            $queryParam .= ", after: {$productCursor}";
        }

        $graphQL = <<<QUERY
            {
                collections(first:1,query:"id:{$collectionId}") {
                    edges {
                        node {
                            id
                            title
                            handle
                            updatedAt
                            sortOrder
                            ruleSet {
                                rules {
                                    condition
                                }
                            }
                            productsCount {
                                count
                            }
                            products({$queryParam}, query :"status:active") {
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
            }
        QUERY;

        $response = $this->client->query($graphQL);
        $responseData = json_decode($response->getBody()->getContents(), true);
        if (!empty($responseData['errors'])) {
            throw new Exception("Single Collections query error :".  json_encode($response['errors']));
        }
        return $responseData['data']['collections'] ?? [];
    }
}