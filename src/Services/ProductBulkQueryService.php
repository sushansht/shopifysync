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
    public function runBulkOperation($lastFeedRefresh)
    {
        $graphQL = '
        mutation {
            bulkOperationRunQuery(
                query: """
                {
                    products(query: "status:active AND updated_at:>' . $lastFeedRefresh . '") {
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
                                media(first: 10) {
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
                                        }
                                    }
                                }
                                metafields(first: 10) {
                                    edges {
                                        node {
                                            id
                                            value
                                            key
                                            namespace
                                        }
                                    }
                                }
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
        }';

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
