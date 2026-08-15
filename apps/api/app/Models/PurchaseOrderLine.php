<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'purchase_order_id',
    'product_id',
    'target_tank_id',
    'quantity_litres',
    'unit_price',
    'received_quantity_litres',
])]
class PurchaseOrderLine extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'quantity_litres' => 'decimal:3',
            'unit_price' => 'decimal:4',
            'received_quantity_litres' => 'decimal:3',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function targetTank(): BelongsTo
    {
        return $this->belongsTo(Tank::class, 'target_tank_id');
    }
}
