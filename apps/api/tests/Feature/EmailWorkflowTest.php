<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Jobs\SendScheduledReport;
use App\Models\Organization;
use App\Models\PurchaseOrder;
use App\Models\ReportSchedule;
use App\Models\Role;
use App\Models\Station;
use App\Models\Supplier;
use App\Models\User;
use App\Models\VendorUser;
use App\Notifications\CompanyOnboardedNotification;
use App\Notifications\LicenseExpiryNotification;
use App\Notifications\PurchaseOrderWorkflowNotification;
use App\Notifications\ScheduledReportNotification;
use App\Notifications\UserOnboardedNotification;
use App\Services\ApplicationNotificationService;
use App\Services\ReportCsvService;
use App\Services\ReportDataService;
use App\Services\ReportScheduleTimingService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EmailWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_company_and_new_users_receive_onboarding_emails_without_plaintext_passwords(): void
    {
        Notification::fake();
        $vendor = $this->vendor();

        $response = $this
            ->actingAs($vendor, 'vendor')
            ->postJson('/api/v1/vendor/organizations', $this->onboardingPayload())
            ->assertCreated();

        $organization = Organization::query()->findOrFail($response->json('data.id'));
        $administrator = User::query()->where('email', 'admin@mailer.test')->firstOrFail();
        $manager = User::query()->where('email', 'manager@mailer.test')->firstOrFail();

        Notification::assertSentOnDemand(
            CompanyOnboardedNotification::class,
            fn (CompanyOnboardedNotification $notification, array $channels, object $notifiable) => $notification->organization->is($organization)
                && $channels === ['mail']
                && array_key_exists('contact@mailer.test', $notifiable->routes['mail']),
        );
        Notification::assertSentTo($administrator, UserOnboardedNotification::class);
        Notification::assertSentTo($manager, UserOnboardedNotification::class);
        Notification::assertSentTo(
            $administrator,
            UserOnboardedNotification::class,
            function (UserOnboardedNotification $notification, array $channels, User $notifiable): bool {
                $mail = $notification->toMail($notifiable);

                return ! str_contains(
                    json_encode([$mail->introLines, $mail->outroLines]),
                    'TemporaryPassword1!',
                );
            },
        );

        $station = $organization->stations()
            ->where('station_number', 'STN-MAIL-01')
            ->firstOrFail();
        $this
            ->actingAs($vendor, 'vendor')
            ->postJson("/api/v1/vendor/organizations/{$organization->id}/accounts", [
                'name' => 'Added Attendant',
                'email' => 'attendant@mailer.test',
                'phone' => '+233200000004',
                'role_slug' => 'cashier_attendant',
                'station_id' => $station->id,
                'password' => 'TemporaryPassword1!',
                'password_confirmation' => 'TemporaryPassword1!',
            ])
            ->assertCreated();

        Notification::assertSentTo(
            User::query()->where('email', 'attendant@mailer.test')->firstOrFail(),
            UserOnboardedNotification::class,
        );
    }

    public function test_purchase_order_events_notify_only_the_roles_that_must_act_or_be_informed(): void
    {
        Notification::fake();
        $organization = Organization::factory()->create();
        $station = Station::factory()->for($organization)->create();
        $manager = $this->roleUser($organization, $station, 'station_manager', $station->id);
        $administrator = $this->roleUser($organization, $station, 'administrator', null);
        $accountant = $this->roleUser($organization, $station, 'accountant', null);
        $owner = $this->roleUser($organization, $station, 'owner', null);
        $supplier = Supplier::query()->create([
            'organization_id' => $organization->id,
            'code' => 'MAIL-BDC',
            'name' => 'Mailer Bulk Distributor',
        ]);
        $order = PurchaseOrder::query()->create([
            'organization_id' => $organization->id,
            'station_id' => $station->id,
            'supplier_id' => $supplier->id,
            'po_number' => 'PO-MAIL-001',
            'status' => 'paid',
            'expected_delivery_date' => now()->addDay(),
            'subtotal' => 25000,
            'total' => 25000,
            'created_by' => $manager->id,
            'approved_by' => $administrator->id,
            'approved_at' => now(),
            'paid_by' => $accountant->id,
            'paid_at' => now(),
            'payment_reference' => 'GMAIL-TEST-01',
            'payment_receipt_name' => 'receipt.pdf',
        ]);
        $notifications = app(ApplicationNotificationService::class);

        $notifications->purchaseOrderSubmitted($order);
        $notifications->purchaseOrderApproved($order);
        $notifications->purchaseOrderRejected($order, 'The requested quantity exceeds the approved station plan.');
        $notifications->purchaseOrderPaid($order);

        Notification::assertSentTo(
            $administrator,
            PurchaseOrderWorkflowNotification::class,
            fn (PurchaseOrderWorkflowNotification $notification) => $notification->event === 'submitted',
        );
        Notification::assertSentTo(
            $accountant,
            PurchaseOrderWorkflowNotification::class,
            fn (PurchaseOrderWorkflowNotification $notification) => $notification->event === 'approved',
        );
        Notification::assertSentTo(
            $manager,
            PurchaseOrderWorkflowNotification::class,
            fn (PurchaseOrderWorkflowNotification $notification) => $notification->event === 'approved',
        );
        Notification::assertSentTo(
            $manager,
            PurchaseOrderWorkflowNotification::class,
            fn (PurchaseOrderWorkflowNotification $notification) => $notification->event === 'rejected'
                && str_contains((string) $notification->reason, 'approved station plan'),
        );
        Notification::assertSentTo(
            [$administrator, $manager],
            PurchaseOrderWorkflowNotification::class,
            fn (PurchaseOrderWorkflowNotification $notification) => $notification->event === 'paid',
        );
        Notification::assertNothingSentTo($owner);
    }

    public function test_license_expiry_alerts_are_sent_at_threshold_once_per_license_term(): void
    {
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-08-15 08:00:00', 'Africa/Accra'));
        $this->vendor('vendor-alerts@mailer.test');
        $organization = Organization::factory()->create([
            'name' => 'License Alert Fuels',
            'contact_email' => 'contact-alerts@mailer.test',
            'license_expires_at' => now()->addDays(7),
        ]);
        $station = Station::factory()->for($organization)->create();
        $this->roleUser($organization, $station, 'administrator', null);
        $this->roleUser($organization, $station, 'owner', null);

        $this->artisan('emails:send-license-expiry-alerts')
            ->expectsOutput('Queued 4 license expiry email(s).')
            ->assertSuccessful();

        Notification::assertSentOnDemandTimes(LicenseExpiryNotification::class, 4);
        Notification::assertSentOnDemand(
            LicenseExpiryNotification::class,
            fn (LicenseExpiryNotification $notification) => $notification->organization->is($organization)
                && $notification->daysRemaining === 7,
        );
        $this->assertDatabaseHas('license_notification_dispatches', [
            'organization_id' => $organization->id,
            'days_before_expiry' => 7,
        ]);

        $this->artisan('emails:send-license-expiry-alerts')
            ->expectsOutput('Queued 0 license expiry email(s).')
            ->assertSuccessful();
        Notification::assertSentOnDemandTimes(LicenseExpiryNotification::class, 4);
        $this->assertDatabaseCount('license_notification_dispatches', 1);
    }

    public function test_due_report_schedule_emails_csv_to_each_recipient_once(): void
    {
        Notification::fake();
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-08-15 08:00:00', 'Africa/Accra'));
        $organization = Organization::factory()->create([
            'name' => 'Scheduled Reports Fuels',
        ]);
        Station::factory()->for($organization)->create();
        $schedule = ReportSchedule::query()->create([
            'organization_id' => $organization->id,
            'station_id' => null,
            'name' => 'Daily Sales Email',
            'report_type' => 'daily-sales',
            'frequency' => 'daily',
            'send_time' => '07:00',
            'recipients' => ['finance@mailer.test', 'owner@mailer.test'],
            'is_active' => true,
        ]);

        $this->artisan('reports:dispatch-scheduled')
            ->expectsOutput('Queued 1 scheduled report job(s).')
            ->assertSuccessful();

        $queuedJob = null;
        Queue::assertPushed(
            SendScheduledReport::class,
            function (SendScheduledReport $job) use (&$queuedJob): bool {
                $queuedJob = $job;

                return true;
            },
        );
        $this->assertInstanceOf(SendScheduledReport::class, $queuedJob);
        $queuedJob->handle(
            app(ReportDataService::class),
            app(ReportCsvService::class),
            app(ReportScheduleTimingService::class),
        );

        Notification::assertSentOnDemandTimes(ScheduledReportNotification::class, 2);
        Notification::assertSentOnDemand(
            ScheduledReportNotification::class,
            fn (ScheduledReportNotification $notification) => $notification->filename === 'daily-sales-email-2026-08-15.csv'
                && str_starts_with($notification->csv, 'date,receipt,station,product,attendant'),
        );
        $this->assertDatabaseCount('report_schedule_deliveries', 2);
        $this->assertDatabaseMissing('report_schedule_deliveries', [
            'report_schedule_id' => $schedule->id,
            'status' => 'pending',
        ]);
        $this->assertNotNull($schedule->fresh()->last_sent_at);

        $this->artisan('reports:dispatch-scheduled')
            ->expectsOutput('Queued 0 scheduled report job(s).')
            ->assertSuccessful();
        Notification::assertSentOnDemandTimes(ScheduledReportNotification::class, 2);
        Queue::assertPushedTimes(SendScheduledReport::class, 1);
    }

    private function roleUser(
        Organization $organization,
        Station $station,
        string $roleSlug,
        ?string $stationId,
    ): User {
        $user = User::factory()->for($organization)->create([
            'status' => UserStatus::Active,
        ]);
        $user->stations()->attach($station->id, ['is_primary' => true]);
        $user->roleAssignments()->create([
            'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
            'organization_id' => $organization->id,
            'station_id' => $stationId,
        ]);

        return $user;
    }

    private function vendor(string $email = 'vendor@mailer.test'): VendorUser
    {
        return VendorUser::query()->create([
            'name' => 'Vendor Mail Administrator',
            'email' => $email,
            'role' => 'super_user',
            'status' => UserStatus::Active,
            'must_change_password' => false,
            'email_verified_at' => now(),
            'password' => Hash::make('VendorPassword1!'),
        ]);
    }

    /** @return array<string, mixed> */
    private function onboardingPayload(): array
    {
        return [
            'company' => [
                'name' => 'Mailer Fuels Limited',
                'slug' => 'mailer-fuels',
                'registration_number' => 'MAIL-001',
                'contact_email' => 'contact@mailer.test',
                'phone' => '+233200000001',
                'address' => 'Accra, Ghana',
                'timezone' => 'Africa/Accra',
            ],
            'license' => ['duration' => 1, 'unit' => 'years'],
            'station' => [
                'name' => 'Mailer Station',
                'code' => 'MAIL-STATION',
                'station_number' => 'STN-MAIL-01',
                'phone' => '+233200000002',
                'address' => 'Tema, Ghana',
            ],
            'accounts' => [
                [
                    'name' => 'Mailer Administrator',
                    'email' => 'admin@mailer.test',
                    'phone' => '+233200000002',
                    'role_slug' => 'administrator',
                    'password' => 'TemporaryPassword1!',
                    'password_confirmation' => 'TemporaryPassword1!',
                ],
                [
                    'name' => 'Mailer Station Manager',
                    'email' => 'manager@mailer.test',
                    'phone' => '+233200000003',
                    'role_slug' => 'station_manager',
                    'password' => 'TemporaryPassword1!',
                    'password_confirmation' => 'TemporaryPassword1!',
                ],
            ],
        ];
    }
}
