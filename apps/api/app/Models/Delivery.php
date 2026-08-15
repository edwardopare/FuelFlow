<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'station_id',
    'purchase_order_id',
    'purchase_order_line_id',
    'tank_id',
    'product_id',
    'status',
    'truck_number',
    'driver_name',
    'waybill_number',
    'arrival_time',
    'departure_time',
    'invoiced_quantity_litres',
    'pre_dip_litres',
    'post_dip_litres',
    'received_quantity_litres',
    'variance_percentage',
    'variance_comment',
    'signed_off_by',
    'confirmed_by',
    'confirmed_at',
])]
class Delivery extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'arrival_time' => 'datetime',
            'departure_time' => 'datetime',
            'confirmed_at' => 'datetime',
            'invoiced_quantity_litres' => 'decimal:3',
            'pre_dip_litres' => 'decimal:3',
            'post_dip_litres' => 'decimal:3',
            'received_quantity_litres' => 'decimal:3',
            'variance_percentage' => 'decimal:4',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    public function tank(): BelongsTo
    {
        return $this->belongsTo(Tank::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
