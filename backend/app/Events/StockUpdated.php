<?php

namespace App\Events;

use App\Models\StockMovement;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StockUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $companyId,
        public StockMovement $movement
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('company.' . $this->companyId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'stock.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'movement' => $this->movement->toArray(),
            'product' => $this->movement->product?->only(['id', 'name', 'sku', 'quantity']),
        ];
    }
}
