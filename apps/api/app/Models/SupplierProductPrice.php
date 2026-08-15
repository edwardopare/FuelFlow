<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['supplier_id', 'product_id', 'price', 'effective_from', 'effective_until'])]
class SupplierProductPrice extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:4',
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
