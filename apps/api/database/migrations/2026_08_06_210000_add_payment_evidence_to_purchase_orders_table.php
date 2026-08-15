<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignUlid('paid_by')
                ->nullable()
                ->after('approved_at')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->timestamp('paid_at')->nullable()->after('paid_by');
            $table->string('payment_reference', 120)->nullable()->after('paid_at');
            $table->string('payment_receipt_path')->nullable()->after('payment_reference');
            $table->string('payment_receipt_name')->nullable()->after('payment_receipt_path');
            $table->string('payment_receipt_mime', 100)->nullable()->after('payment_receipt_name');
            $table->unsignedBigInteger('payment_receipt_size')->nullable()->after('payment_receipt_mime');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeign(['paid_by']);
            $table->dropColumn([
                'paid_by',
                'paid_at',
                'payment_reference',
                'payment_receipt_path',
                'payment_receipt_name',
                'payment_receipt_mime',
                'payment_receipt_size',
            ]);
        });
    }
};
