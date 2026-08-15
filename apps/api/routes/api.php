<?php

use App\Http\Controllers\Api\AuditEventController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DeliveryController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PumpController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\ReconciliationController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ReportScheduleController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\StationController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\TankController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\Vendor\VendorAuthController;
use App\Http\Controllers\Api\Vendor\VendorDashboardController;
use App\Http\Controllers\Api\Vendor\VendorOrganizationController;
use App\Http\Controllers\Api\Vendor\VendorRoleController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:api')->group(function (): void {
    Route::get('/health', HealthController::class);

    Route::middleware('web')->group(function (): void {
        Route::post('/vendor/auth/login', [VendorAuthController::class, 'login'])
            ->middleware('throttle:vendor-login');

        Route::middleware(['auth:vendor', 'vendor.idle'])->prefix('vendor')->group(function (): void {
            Route::get('/me', [VendorAuthController::class, 'me']);
            Route::post('/auth/change-password', [VendorAuthController::class, 'changePassword']);
            Route::post('/auth/logout', [VendorAuthController::class, 'logout']);

            Route::middleware('vendor.active')->group(function (): void {
                Route::get('/dashboard', VendorDashboardController::class);
                Route::get('/roles', VendorRoleController::class);
                Route::get('/organizations', [VendorOrganizationController::class, 'index']);
                Route::post('/organizations', [VendorOrganizationController::class, 'store']);
                Route::get('/organizations/{organization}', [VendorOrganizationController::class, 'show']);
                Route::post('/organizations/{organization}/accounts', [VendorOrganizationController::class, 'storeUser']);
                Route::post('/organizations/{organization}/suspend', [VendorOrganizationController::class, 'suspend']);
                Route::post('/organizations/{organization}/activate', [VendorOrganizationController::class, 'activate']);
                Route::patch('/organizations/{organization}/license', [VendorOrganizationController::class, 'updateLicense']);
                Route::post('/organizations/{organization}/license/deactivate', [VendorOrganizationController::class, 'deactivateLicense']);
            });
        });

        Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])
            ->middleware('throttle:password-reset');
        Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])
            ->middleware('throttle:password-reset');
        Route::post('/auth/login', [AuthController::class, 'login'])
            ->middleware('throttle:login');

        Route::middleware(['auth:sanctum', 'idle.session'])->group(function (): void {
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
            Route::post('/auth/logout', [AuthController::class, 'logout']);

            Route::middleware('active')->group(function (): void {
                Route::get('/stations', [StationController::class, 'index'])
                    ->middleware('permission:stations.view');
                Route::post('/stations', [StationController::class, 'store']);
                Route::patch('/stations/{station}', [StationController::class, 'update']);
                Route::post('/stations/{station}/deactivate', [StationController::class, 'deactivate']);

                Route::get('/roles', [RoleController::class, 'index'])
                    ->middleware('permission:users.manage');

                Route::get('/users', [UserController::class, 'index'])
                    ->middleware('permission:users.view');
                Route::post('/users', [UserController::class, 'store']);
                Route::get('/users/{user}', [UserController::class, 'show'])
                    ->middleware('permission:users.view');
                Route::patch('/users/{user}', [UserController::class, 'update']);
                Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate']);
                Route::post('/users/{user}/activate', [UserController::class, 'activate']);
                Route::delete('/users/{user}', [UserController::class, 'destroy']);

                Route::get('/audit-events', [AuditEventController::class, 'index'])
                    ->middleware('permission:audit.events.view');

                Route::get('/dashboard', [DashboardController::class, 'index']);

                Route::get('/suppliers', [SupplierController::class, 'index']);
                Route::post('/suppliers', [SupplierController::class, 'store']);
                Route::patch('/suppliers/{supplier}', [SupplierController::class, 'update']);
                Route::post('/suppliers/{supplier}/deactivate', [SupplierController::class, 'deactivate']);
                Route::post('/suppliers/{supplier}/prices', [SupplierController::class, 'addPrice']);

                Route::get('/products', [ProductController::class, 'index']);
                Route::post('/products', [ProductController::class, 'store']);
                Route::post('/products/{product}/prices', [ProductController::class, 'setPrice']);
                Route::post('/products/{product}/deactivate', [ProductController::class, 'deactivate']);

                Route::get('/tanks', [TankController::class, 'index']);
                Route::post('/tanks', [TankController::class, 'store']);
                Route::post('/tanks/{tank}/readings', [TankController::class, 'addReading']);
                Route::get('/tanks/{tank}/movements', [TankController::class, 'movements']);
                Route::post('/tank-transfers', [TankController::class, 'transfer']);

                Route::get('/pumps', [PumpController::class, 'index']);
                Route::post('/pumps', [PumpController::class, 'store']);
                Route::post('/pumps/{pump}/status', [PumpController::class, 'setStatus']);

                Route::get('/shifts', [ShiftController::class, 'index']);
                Route::post('/shifts', [ShiftController::class, 'store']);
                Route::post('/shifts/{shift}/open', [ShiftController::class, 'open']);
                Route::post('/shifts/{shift}/close', [ShiftController::class, 'close']);

                Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
                Route::post('/purchase-orders', [PurchaseOrderController::class, 'store']);
                Route::post('/purchase-orders/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit']);
                Route::post('/purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve']);
                Route::post('/purchase-orders/{purchaseOrder}/reject', [PurchaseOrderController::class, 'reject']);
                Route::post('/purchase-orders/{purchaseOrder}/pay', [PurchaseOrderController::class, 'pay']);
                Route::get('/purchase-orders/{purchaseOrder}/payment-receipt', [PurchaseOrderController::class, 'paymentReceipt']);
                Route::post('/purchase-orders/{purchaseOrder}/send', [PurchaseOrderController::class, 'send']);
                Route::post('/purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel']);

                Route::get('/deliveries', [DeliveryController::class, 'index']);
                Route::post('/deliveries', [DeliveryController::class, 'store']);
                Route::post('/deliveries/{delivery}/confirm', [DeliveryController::class, 'confirm']);

                Route::get('/sales', [SaleController::class, 'index']);
                Route::post('/sales/quote', [SaleController::class, 'quote']);
                Route::post('/sales', [SaleController::class, 'store']);
                Route::post('/sales/{sale}/reverse', [SaleController::class, 'reverse']);

                Route::get('/reconciliations', [ReconciliationController::class, 'index']);
                Route::post('/reconciliations/generate', [ReconciliationController::class, 'generate']);
                Route::post('/reconciliations/{reconciliation}/sign-off', [ReconciliationController::class, 'signOff']);
                Route::post('/reconciliations/{reconciliation}/reopen', [ReconciliationController::class, 'reopen']);

                Route::get('/reports', [ReportController::class, 'index']);
                Route::get('/report-schedules', [ReportScheduleController::class, 'index']);
                Route::post('/report-schedules', [ReportScheduleController::class, 'store']);
                Route::put('/report-schedules/{reportSchedule}', [ReportScheduleController::class, 'update']);
                Route::post('/report-schedules/{reportSchedule}/toggle', [ReportScheduleController::class, 'toggle']);
                Route::get('/settings', [SettingsController::class, 'index']);
                Route::put('/settings', [SettingsController::class, 'update']);
            });
        });
    });
});
