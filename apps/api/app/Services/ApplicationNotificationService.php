<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\VendorUser;
use App\Notifications\CompanyOnboardedNotification;
use App\Notifications\LicenseExpiryNotification;
use App\Notifications\PurchaseOrderWorkflowNotification;
use App\Notifications\UserOnboardedNotification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Notification;

class ApplicationNotificationService
{
    public function companyOnboarded(Organization $organization): void
    {
        $organization->loadMissing('users');

        if ($organization->contact_email) {
            Notification::route('mail', [
                strtolower($organization->contact_email) => $organization->name,
            ])->notify(new CompanyOnboardedNotification($organization));
        }

        $organization->users->each(
            fn (User $user) => $this->userOnboarded($user),
        );
    }

    public function userOnboarded(User $user): void
    {
        $user->notify(new UserOnboardedNotification($user));
    }

    public function purchaseOrderSubmitted(PurchaseOrder $order): void
    {
        $this->notifyUsers(
            $this->usersWithRoles($order->organization_id, ['administrator']),
            new PurchaseOrderWorkflowNotification($order, 'submitted'),
        );
    }

    public function purchaseOrderApproved(PurchaseOrder $order): void
    {
        $recipients = $this->usersWithRoles($order->organization_id, ['accountant']);
        $this->addUserById($recipients, $order->created_by);

        $this->notifyUsers(
            $recipients,
            new PurchaseOrderWorkflowNotification($order, 'approved'),
        );
    }

    public function purchaseOrderRejected(PurchaseOrder $order, string $reason): void
    {
        $recipients = new EloquentCollection;
        $this->addUserById($recipients, $order->created_by);

        $this->notifyUsers(
            $recipients,
            new PurchaseOrderWorkflowNotification($order, 'rejected', $reason),
        );
    }

    public function purchaseOrderPaid(PurchaseOrder $order): void
    {
        $recipients = $this->usersWithRoles($order->organization_id, ['administrator']);
        $this->addUserById($recipients, $order->created_by);
        $this->addUserById($recipients, $order->approved_by);

        $this->notifyUsers(
            $recipients,
            new PurchaseOrderWorkflowNotification($order, 'paid'),
        );
    }

    /** @return array<string, string> Email address keyed to recipient name. */
    public function licenseRecipients(Organization $organization): array
    {
        $recipients = [];

        if ($organization->contact_email) {
            $recipients[strtolower($organization->contact_email)] = $organization->name;
        }

        foreach ($this->usersWithRoles($organization->id, ['administrator', 'owner']) as $user) {
            $recipients[strtolower($user->email)] = $user->name;
        }

        VendorUser::query()
            ->where('role', 'super_user')
            ->whereIn('status', $this->notifiableStatuses())
            ->get(['name', 'email'])
            ->each(function (VendorUser $user) use (&$recipients): void {
                $recipients[strtolower($user->email)] = $user->name;
            });

        return $recipients;
    }

    /** @param array<string, string> $recipients */
    public function licenseExpiring(
        Organization $organization,
        int $daysRemaining,
        array $recipients,
    ): void {
        foreach ($recipients as $email => $name) {
            Notification::route('mail', [$email => $name])->notify(
                new LicenseExpiryNotification($organization, $daysRemaining),
            );
        }
    }

    /** @param list<string> $roleSlugs */
    private function usersWithRoles(
        string $organizationId,
        array $roleSlugs,
    ): EloquentCollection {
        return User::query()
            ->where('organization_id', $organizationId)
            ->whereIn('status', $this->notifiableStatuses())
            ->whereHas(
                'roleAssignments.role',
                fn ($query) => $query->whereIn('slug', $roleSlugs),
            )
            ->get()
            ->unique('id')
            ->values();
    }

    private function addUserById(EloquentCollection $recipients, ?string $userId): void
    {
        if (! $userId || $recipients->contains('id', $userId)) {
            return;
        }

        $user = User::query()
            ->whereKey($userId)
            ->whereIn('status', $this->notifiableStatuses())
            ->first();

        if ($user) {
            $recipients->push($user);
        }
    }

    private function notifyUsers(
        EloquentCollection $recipients,
        PurchaseOrderWorkflowNotification $notification,
    ): void {
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, $notification);
        }
    }

    /** @return list<string> */
    private function notifiableStatuses(): array
    {
        return [
            UserStatus::Active->value,
            UserStatus::PendingFirstLogin->value,
        ];
    }
}
