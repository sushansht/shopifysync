<?php

namespace dpl\ShopifySync\Services;

use Exception;
use Shopify\Clients\Graphql;
use Illuminate\Support\Facades\Log;

class BulkOperationPollingService
{
    protected $client;

    public function __construct(Graphql $client)
    {
        $this->client = $client;
    }

    /**
     * Poll the bulk operation status from Shopify.
     *
     * @param string $bulkOperationId
     * @return array|null
     */
    public function pollBulkOperationStatus(string $bulkOperationId)
    {
        $graphQL = '
        {
            node(id: "' . $bulkOperationId . '") {
                ... on BulkOperation {
                    id
                    status
                    errorCode
                    createdAt
                    completedAt
                    objectCount
                    fileSize
                    url
                    partialDataUrl
                }
            }
        }';

        $response = $this->client->query($graphQL);
        $responseData = json_decode($response->getBody()->getContents(), true);
        if (!empty($responseData['errors'])) {
            throw new Exception("Bulk query poll error :".  json_encode($response['errors']));
        }
        return $responseData['data']['node'] ?? null;
    }

    public static function pollAndUpdateBulkQueryStatus($bulkQuery, $specifier, $token)
    {
            $shopifyGqlClient = new Graphql($specifier, $token);
            $pollingService = new BulkOperationPollingService($shopifyGqlClient);

            // Poll the bulk operation status
            $pollingResult = $pollingService->pollBulkOperationStatus($bulkQuery->bulk_query_id);

            return $pollingResult;
    }
}
