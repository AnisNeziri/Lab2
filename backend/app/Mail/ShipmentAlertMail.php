<?php

namespace App\Mail;

use App\Mail\Concerns\AimsBrandedEnvelope;
use App\Models\Shipment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ShipmentAlertMail extends Mailable
{
    use AimsBrandedEnvelope, Queueable, SerializesModels;

    public string $eventLabel;

    public function __construct(
        public Shipment $shipment,
        public string $eventType,
    ) {
        $this->eventLabel = str_replace('_', ' ', ucfirst($eventType));
    }

    public function envelope(): Envelope
    {
        return $this->aimsEnvelope("AIMS Shipment Alert: {$this->eventLabel}");
    }

    public function content(): Content
    {
        return new Content(
            html: 'mail.shipment-alert',
            text: 'mail.shipment-alert-text',
        );
    }
}
