<?php

namespace App\Jobs;

use App\Services\Platform\BusinessSearchIndexer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class IndexBusinessSearch implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public int $company, public string $type, public int $id)
    {
        $this->onConnection('database')->onQueue('search-index');
    }

    public int $uniqueFor = 60;

    public function uniqueId(): string
    {
        return $this->company.':'.$this->type.':'.$this->id;
    }

    public function handle(): void
    {
        app(BusinessSearchIndexer::class)->index($this->company, $this->type, $this->id);
    }
}
