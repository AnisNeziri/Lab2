<?php

namespace App\Repositories\Contracts;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Collection;

interface InvoiceRepositoryInterface
{
    public function allLatest(): Collection;

    public function findWithItems(int $id): Invoice;

    public function create(array $data): Invoice;
}
