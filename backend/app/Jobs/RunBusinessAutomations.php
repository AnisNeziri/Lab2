<?php
namespace App\Jobs;
use App\Models\BusinessEvent;
use App\Services\AutomationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
class RunBusinessAutomations implements ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries=3;
    public array $backoff=[60,300];
    public function __construct(public int $eventId) {}
    public function handle(): void {
        $event=BusinessEvent::withoutGlobalScopes()->find($this->eventId);
        if ($event) app(AutomationService::class)->consume($event);
    }
}
