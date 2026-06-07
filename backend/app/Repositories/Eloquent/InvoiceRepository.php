<?php

namespace App\Repositories\Eloquent;

use App\Models\Invoice;
use App\Repositories\Contracts\InvoiceRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class InvoiceRepository implements InvoiceRepositoryInterface
{
    public function allLatest(): Collection
    {
        return Invoice::with('payments')->latest('issued_at')->get();
    }

    public function findWithItems(int $id): Invoice
    {
        return Invoice::with(['items.product', 'payments'])->findOrFail($id);
    }

    public function create(array $data): Invoice
    {
        return Invoice::create($data);
    }
}
