<?php

namespace dpl\ShopifySync\Services;

use Illuminate\Support\Facades\Log;

class CollectionCountGqlService
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
    
    public function getCollectionCount($updatedAt, $currentProcessAt)
    {
        $queryCondition = "published_status:approved AND updated_at:<'{$currentProcessAt}'";

        if ($updatedAt !== null) {
            $queryCondition .= " AND updated_at:>'{$updatedAt}'";
        }

        $query = <<<QUERY
            query {
                collectionsCount(query: "{$queryCondition}") {
                    count
                }
            }
        QUERY;
            $response = $this->client->query($query);
            $responseData = json_decode($response->getBody()->getContents(), true);
            if (!empty($responseData['errors'])) {
                Log::channel('shopify-sync')->error("Error while getting collection count. error : {error}",[
                    'error' => json_encode($response['errors'])
                ]);
            }
            return $responseData['data']['collectionsCount']['count'] ?? 0;
    }
}

