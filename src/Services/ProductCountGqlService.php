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

    public function getProductsCount($updatedAt)
    {
        $queryCondition = 'status:active';

        if ($updatedAt !== null) {
            $queryCondition .= " AND updated_at:>"."'".$updatedAt."'";
        }
  
        $graphQL = <<<QUERY
            query {
                productsCount(query: "' . $queryCondition . '") {
                    count
                }
            }
        QUERY;

        try {
            $response = $this->client->query($graphQL);
            $responseData = json_decode($response->getBody()->getContents(), true);
            return $responseData['data']['productsCount']['count'] ?? 0;
        } catch (\Exception $e) {
            return 0;
        }
    }
}
