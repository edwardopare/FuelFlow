<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'station_id', 'price', 'effective_from', 'reason', 'changed_by'])]
class ProductPrice extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'effective_from' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
