<?php

namespace dpl\ShopifySync\Services;

use Shopify\Clients\Graphql;
use Illuminate\Support\Facades\Log;

class ProductBulkQueryService
{
    protected $client;

    public function __construct(Graphql $client)
    {
        $this->client = $client;
    }

    /**
     * Perform a bulk operation to fetch products.
     *
     * @return array|null
     */
    public function runBulkOperation($lastFeedRefresh, $specifier, $current_time)
    {
        $queryCondition = "status:active";

        if ($lastFeedRefresh !== null) {
            $queryCondition .= " AND updated_at:>'{$lastFeedRefresh}'";
        }

        $queryCondition .= " AND updated_at:<'{$current_time}'";


        $shop_model = config('shopifysync.shop_model');
        $should_sync_metafield_column = config('shopifysync.should_sync_metafield_column');

        $should_sync_metafield_row = $shop_model::where('specifier', $specifier)->first();
        $should_sync_metafield = $should_sync_metafield_row->$should_sync_metafield_column;


        $metafieldsQuery = $should_sync_metafield == 1 ? '
                        metafields(first: 10) {
                            edges {
                                node {
                                    id
                                    value
                                    key
                                    namespace
                                }
                            }
                        }' : '';

        $graphQL = <<<QUERY
        mutation {
            bulkOperationRunQuery(
                query: """
                {
                    products(query: "{$queryCondition}") {
                        edges {
                            node {
                                id
                                title
                                tags
                                vendor
                                options {
                                    id
                                    name
                                    values
                                    position
                                }
                                productType
                                publishedAt
                                status
                                totalInventory
                                variants {
                                    edges {
                                        node {
                                            id
                                            availableForSale
                                            compareAtPrice
                                            position
                                            price
                                            sku
                                            title
                                            inventoryQuantity
                                            inventoryPolicy
                                            inventoryItem {
                                                id
                                                tracked
                                                measurement{
                                                    weight{
                                                        unit
                                                        value
                                                    }
                                                }
                                            }
                                            image {
                                                id
                                                url
                                                altText
                                            }
                                        }
                                    }
                                }
                                description
                                handle
                                collections(first: 10) {
                                    edges {
                                        node {
                                            id
                                            title
                                        }
                                    }
                                }
                                media(first: 20) {
                                    edges {
                                        node {
                                            id
                                            alt
                                            ... on MediaImage {
                                                image {
                                                    id
                                                    url
                                                }
                                            }
                                            ... on Video {
                                                id
                                                sources {
                                                    url
                                                }
                                            }   
                                        }
                                    }
                                }
                                {$metafieldsQuery}
                            }
                        }
                    }
                }
                """
            ) {
                bulkOperation {
                    id
                    status
                }
                userErrors {
                    field
                    message
                }
            }
        }
        QUERY;

        try {
            $response = $this->client->query($graphQL);
            $responseData = json_decode($response->getBody()->getContents(), true);
            Log::channel("daily")->info("Response Data runBulkOperation Method : " . json_encode($responseData));

            return $responseData['data']['bulkOperationRunQuery']['bulkOperation'] ?? null;
        } catch (\Exception $e) {
            Log::error('GraphQL query error: ' . $e->getMessage());
            return null;
        }
    }
}
