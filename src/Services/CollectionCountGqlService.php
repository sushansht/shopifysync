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
    
    public function getCollectionCount($updatedAt, $currentProcessAt,$type)
    {
        $queryCondition = "published_status:approved AND collection_type:{$type} AND updated_at:<'{$currentProcessAt}'";

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

        try {
            $response = $this->client->query($query);
            $responseData = json_decode($response->getBody()->getContents(), true);
            Log::channel("graphql")->info("Response Data: " . json_encode($responseData));
            return $responseData['data']['collectionsCount']['count'] ?? 0;
        } catch (\Exception $e) {
            Log::error('GraphQL query error: ' . $e->getMessage());
            return 0;
        }
    }
}

