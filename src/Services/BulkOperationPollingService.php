<?php

namespace dpl\ShopifySync\Services;

use dpl\ShopifySync\Models\ShopBulkQueryOperation;
use dpl\ShopifySync\Jobs\DownloadBulkFileJob;
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

        try {
            $response = $this->client->query($graphQL);
            $responseData = json_decode($response->getBody()->getContents(), true);

            return $responseData['data']['node'] ?? null;
        } catch (\Exception $e) {
            Log::error('GraphQL polling error: ' . $e->getMessage());
            return null;
        }
    }

    public static function pollAndUpdateBulkQueryStatus($specifier, $token)
    {
        // $shop = Shop::where(['id' => $shop_id])->first();
        $bulkQuery = ShopBulkQueryOperation::where('specifier', $specifier)
                        ->whereIn('status', ['CREATED', 'RUNNING'])
                        ->where('file_url', null)
                        ->first();
        if (!$bulkQuery) {
            return "Not bulk query found";
        }
        try {
            $shopifyGqlClient = new Graphql($specifier, $token);
            $pollingService = new BulkOperationPollingService($shopifyGqlClient);

            // Poll the bulk operation status
            $pollingResult = $pollingService->pollBulkOperationStatus($bulkQuery->bulk_query_id);

            if ($pollingResult) {
                // Update the database with the status and URL
                $bulkQuery->update([
                    'status' => $pollingResult['status'],
                    'file_url' => $pollingResult['url'] ?? null, // Save file URL if exists
                    'completed_at' => $pollingResult['completedAt'] ?? null,
                ]);

                if($pollingResult['url'] && $pollingResult['status'] == "COMPLETED"){
                    DownloadBulkFileJob::dispatch($pollingResult['url'],$specifier)->onQueue('shopifysync-download-file');
                }
            }
        } catch (\Exception $e) {
            Log::error('Exception occurred while polling: ' . $e->getMessage());
        }
    }
}
