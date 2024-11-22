<?php
namespace dpl\ShopifySync\Models;

use Illuminate\Database\Eloquent\Model;

class ShopifySyncShop extends Model
{
    protected $connection = 'mysql_no_prefix';

    protected $table = 'shopify_sync_shop';

    protected $fillable = [
        'specifier',
        'product_processed_at',
        'collection_processed_at',
        'is_bulk_query_in_progress'
    ];
}
