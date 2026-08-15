<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'station_id',
    'business_date',
    'status',
    'opening_stock_litres',
    'receipts_litres',
    'sales_litres',
    'closing_book_stock_litres',
    'closing_dip_stock_litres',
    'tank_variance_litres',
    'sales_value',
    'expected_cash',
    'counted_cash',
    'cash_variance',
    'manager_comment',
    'reviewed_by',
    'reviewed_at',
    'locked_at',
])]
class Reconciliation extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'reviewed_at' => 'datetime',
            'locked_at' => 'datetime',
            'opening_stock_litres' => 'decimal:3',
            'receipts_litres' => 'decimal:3',
            'sales_litres' => 'decimal:3',
            'closing_book_stock_litres' => 'decimal:3',
            'closing_dip_stock_litres' => 'decimal:3',
            'tank_variance_litres' => 'decimal:3',
            'sales_value' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'cash_variance' => 'decimal:2',
        ];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }
}
