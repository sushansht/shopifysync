<?php
namespace dpl\ShopifySync\Models;

use Illuminate\Database\Eloquent\Model;

class ShopBulkQueryOperation extends Model
{
    protected $connection = 'mysql_no_prefix';
    protected $fillable = [
        'specifier',
        'bulk_query_id',
        'status',
        'file_url',
        'completed_at'
    ];
}
