<?php

namespace App\Notifications;

use App\Models\PurchaseOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PurchaseOrderWorkflowNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public PurchaseOrder $purchaseOrder,
        public string $event,
        public ?string $reason = null,
    ) {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->purchaseOrder->loadMissing([
            'station',
            'supplier',
            'createdBy',
            'approvedBy',
            'paidBy',
        ]);
        $subject = match ($this->event) {
            'submitted' => "PO {$order->po_number} requires approval",
            'approved' => "PO {$order->po_number} was approved for payment",
            'rejected' => "PO {$order->po_number} was rejected",
            'paid' => "PO {$order->po_number} was paid",
            default => "PO {$order->po_number} status changed",
        };
        $summary = match ($this->event) {
            'submitted' => 'A Station Manager submitted this purchase order for Administrator approval.',
            'approved' => 'The Administrator approved this purchase order. It is now available to the Accountant for payment.',
            'rejected' => 'The Administrator rejected this purchase order.',
            'paid' => 'The Accountant recorded payment and attached payment evidence.',
            default => 'The purchase order status has changed.',
        };

        $message = (new MailMessage)
            ->subject($subject)
            ->greeting('FuelFlow purchase order update')
            ->line($summary)
            ->line("PO number: {$order->po_number}")
            ->line('Station: '.($order->station?->name ?? 'Not available'))
            ->line('Supplier: '.($order->supplier?->name ?? 'Not available'))
            ->line('Amount: GHS '.number_format((float) $order->total, 2))
            ->line('Expected delivery: '.$order->expected_delivery_date->format('j F Y'));

        if ($this->event === 'rejected' && $this->reason) {
            $message->line("Rejection reason: {$this->reason}");
        }

        if ($this->event === 'paid') {
            $message
                ->line('Payment reference: '.($order->payment_reference ?: 'Not provided'))
                ->line('Receipt: '.($order->payment_receipt_name ?: 'Attached in FuelFlow'));
        }

        return $message
            ->action('View purchase orders', rtrim((string) config('app.frontend_url'), '/').'/modules/procurement')
            ->line('Sign in to view the full purchase order and audit history.')
            ->salutation('FuelFlow FSMS — © '.now()->year.' S4F. All rights reserved.');
    }
}
