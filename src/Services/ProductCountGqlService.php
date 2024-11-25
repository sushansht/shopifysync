<?php

namespace dpl\ShopifySync\Services;

use Illuminate\Support\Facades\Log;

class ProductCountGqlService
{
    protected $client;

    public function __construct($client)
    {
        $this->client = $client;
    }

    /**
     * Perform a gql to get products count.
     *
     * @return array|null
     */

    public function getProductsCount($updatedAt, $current_processed_time)
    {
        $queryCondition = "status:active AND updated_at:<'{$current_processed_time}'";

        if ($updatedAt !== null) {
            $queryCondition .= " AND updated_at:>'{$updatedAt}'";
        }

        $graphQL = <<<QUERY
            query {
                productsCount(query: "{$queryCondition}") {
                    count
                }
            }
        QUERY;
            $response = $this->client->query($graphQL);
            $responseData = json_decode($response->getBody()->getContents(), true);
            if (!empty($responseData['errors'])) {
                Log::channel('shopify-sync')->error("Error while getting product count. error : {error}",[
                    'error' => json_encode($response['errors'])
                ]);
            }
            return $responseData['data']['productsCount']['count'] ?? 0;
    }
}
