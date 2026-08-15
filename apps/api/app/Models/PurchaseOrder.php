<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id',
    'station_id',
    'supplier_id',
    'po_number',
    'status',
    'expected_delivery_date',
    'subtotal',
    'total',
    'notes',
    'created_by',
    'approved_by',
    'approved_at',
    'paid_by',
    'paid_at',
    'payment_reference',
    'payment_receipt_path',
    'payment_receipt_name',
    'payment_receipt_mime',
    'payment_receipt_size',
    'sent_at',
])]
class PurchaseOrder extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'expected_delivery_date' => 'date',
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'payment_receipt_size' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
