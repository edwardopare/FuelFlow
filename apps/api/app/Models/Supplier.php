<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'organization_id',
    'code',
    'name',
    'contact_name',
    'email',
    'phone',
    'address',
    'tax_registration_number',
    'bank_details',
    'payment_terms',
    'is_active',
    'on_time_percentage',
    'quality_rating',
    'quantity_accuracy',
])]
class Supplier extends Model
{
    use HasUlids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'bank_details' => 'encrypted',
            'is_active' => 'boolean',
            'on_time_percentage' => 'decimal:2',
            'quality_rating' => 'decimal:2',
            'quantity_accuracy' => 'decimal:2',
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(SupplierProductPrice::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
