<?php

namespace App\Jobs;

use App\Mail\ShipmentAlertMail;
use App\Models\Shipment;
use App\Services\AimsMailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendShipmentAlertEmailJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $shipmentId,
        public string $eventType,
        public string $recipientEmail,
    ) {}

    public function handle(AimsMailer $mailer): void
    {
        $shipment = Shipment::withoutGlobalScopes()->find($this->shipmentId);

        if (! $shipment) {
            return;
        }

        $mailer->send(
            new ShipmentAlertMail($shipment, $this->eventType),
            $this->recipientEmail
        );
    }
}
