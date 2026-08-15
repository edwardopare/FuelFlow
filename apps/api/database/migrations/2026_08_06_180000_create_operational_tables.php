<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('unit', 24)->default('litres');
            $table->string('tank_grade')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('product_prices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('station_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('price', 15, 2);
            $table->timestamp('effective_from');
            $table->text('reason');
            $table->foreignUlid('changed_by')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['product_id', 'station_id', 'effective_from']);
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('tax_registration_number')->nullable();
            $table->text('bank_details')->nullable();
            $table->string('payment_terms')->nullable();
            $table->boolean('is_active')->default(true);
            $table->decimal('on_time_percentage', 5, 2)->default(100);
            $table->decimal('quality_rating', 3, 2)->default(5);
            $table->decimal('quantity_accuracy', 5, 2)->default(100);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('supplier_product_prices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 15, 4);
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->timestamps();
            $table->index(['supplier_id', 'product_id', 'effective_from']);
        });

        Schema::create('tanks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('station_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('tank_grade')->nullable();
            $table->decimal('capacity_litres', 15, 3);
            $table->decimal('book_stock_litres', 15, 3)->default(0);
            $table->decimal('minimum_safe_litres', 15, 3)->default(0);
            $table->decimal('maximum_safe_litres', 15, 3);
            $table->boolean('atg_enabled')->default(false);
            $table->string('status', 24)->default('active');
            $table->timestamps();
            $table->unique(['station_id', 'code']);
        });

        Schema::create('tank_readings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tank_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('recorded_by')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->decimal('reading_litres', 15, 3);
            $table->decimal('book_stock_litres', 15, 3);
            $table->decimal('variance_litres', 15, 3);
            $table->string('source', 24)->default('manual');
            $table->timestamp('read_at');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['tank_id', 'read_at']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('station_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('tank_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('product_id')->constrained()->restrictOnDelete();
            $table->string('type', 32);
            $table->decimal('quantity_litres', 15, 3);
            $table->decimal('balance_after_litres', 15, 3);
            $table->string('source_type')->nullable();
            $table->string('source_id')->nullable();
            $table->foreignUlid('recorded_by')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['tank_id', 'occurred_at']);
        });

        Schema::create('pumps', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('station_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('status', 32)->default('operational');
            $table->timestamps();
            $table->unique(['station_id', 'code']);
        });

        Schema::create('nozzles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('pump_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('tank_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('product_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('status', 32)->default('operational');
            $table->decimal('current_meter_reading', 18, 3)->default(0);
            $table->decimal('meter_maximum', 18, 3)->nullable();
            $table->timestamps();
            $table->unique(['pump_id', 'code']);
        });

        Schema::create('pump_maintenance_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('pump_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32);
            $table->text('description');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->foreignUlid('recorded_by')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('shifts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('station_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('attendant_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreignUlid('pump_id')->constrained()->restrictOnDelete();
            $table->string('code', 40)->unique();
            $table->string('status', 24)->default('scheduled');
            $table->timestamp('scheduled_start');
            $table->timestamp('scheduled_end');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('expected_cash', 15, 2)->default(0);
            $table->decimal('counted_cash', 15, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['station_id', 'scheduled_start']);
        });

        Schema::create('meter_readings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shift_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('nozzle_id')->constrained()->restrictOnDelete();
            $table->string('type', 16);
            $table->decimal('reading', 18, 3);
            $table->foreignUlid('recorded_by')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->unique(['shift_id', 'nozzle_id', 'type']);
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('station_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('supplier_id')->constrained()->restrictOnDelete();
            $table->string('po_number', 48)->unique();
            $table->string('status', 32)->default('draft');
            $table->date('expected_delivery_date');
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignUlid('created_by')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->foreignUlid('approved_by')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['station_id', 'status', 'expected_delivery_date']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('target_tank_id')->nullable()->references('id')->on('tanks')->nullOnDelete();
            $table->decimal('quantity_litres', 15, 3);
            $table->decimal('unit_price', 15, 4);
            $table->decimal('received_quantity_litres', 15, 3)->default(0);
            $table->timestamps();
        });

        Schema::create('deliveries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('station_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('purchase_order_line_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('tank_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('product_id')->constrained()->restrictOnDelete();
            $table->string('status', 32)->default('pending');
            $table->string('truck_number');
            $table->string('driver_name');
            $table->string('waybill_number');
            $table->timestamp('arrival_time');
            $table->timestamp('departure_time')->nullable();
            $table->decimal('invoiced_quantity_litres', 15, 3);
            $table->decimal('pre_dip_litres', 15, 3);
            $table->decimal('post_dip_litres', 15, 3);
            $table->decimal('received_quantity_litres', 15, 3);
            $table->decimal('variance_percentage', 8, 4)->default(0);
            $table->text('variance_comment')->nullable();
            $table->foreignUlid('signed_off_by')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->foreignUlid('confirmed_by')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['station_id', 'status', 'arrival_time']);
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('station_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('shift_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('attendant_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreignUlid('nozzle_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('tank_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('product_id')->constrained()->restrictOnDelete();
            $table->string('receipt_number', 48)->unique();
            $table->decimal('litres', 15, 3);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('amount', 15, 2);
            $table->string('payment_method', 32);
            $table->string('payment_reference')->nullable();
            $table->string('status', 24)->default('confirmed');
            $table->timestamp('sold_at');
            $table->foreignUlid('reversed_by')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();
            $table->index(['station_id', 'sold_at']);
        });

        Schema::create('reconciliations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('station_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');
            $table->string('status', 24)->default('pending');
            $table->decimal('opening_stock_litres', 15, 3)->default(0);
            $table->decimal('receipts_litres', 15, 3)->default(0);
            $table->decimal('sales_litres', 15, 3)->default(0);
            $table->decimal('closing_book_stock_litres', 15, 3)->default(0);
            $table->decimal('closing_dip_stock_litres', 15, 3)->default(0);
            $table->decimal('tank_variance_litres', 15, 3)->default(0);
            $table->decimal('sales_value', 15, 2)->default(0);
            $table->decimal('expected_cash', 15, 2)->default(0);
            $table->decimal('counted_cash', 15, 2)->default(0);
            $table->decimal('cash_variance', 15, 2)->default(0);
            $table->text('manager_comment')->nullable();
            $table->foreignUlid('reviewed_by')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();
            $table->unique(['station_id', 'business_date']);
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->json('value');
            $table->foreignUlid('updated_by')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('reconciliations');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('deliveries');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('meter_readings');
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('pump_maintenance_events');
        Schema::dropIfExists('nozzles');
        Schema::dropIfExists('pumps');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('tank_readings');
        Schema::dropIfExists('tanks');
        Schema::dropIfExists('supplier_product_prices');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('product_prices');
        Schema::dropIfExists('products');
    }
};
