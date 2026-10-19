<?php

namespace App\Integrations\Sef;

use App\Integrations\Sef\Generated\CitizenCoupon;
use App\Integrations\Sef\Generated\CouponItem;
use App\Integrations\Sef\Generated\CouponType;
use App\Integrations\Sef\Generated\Payment;
use App\Integrations\Sef\Generated\PosCoupon;
use App\Integrations\Sef\Generated\TaxGroup;
use App\Models\FiscalConfiguration;
use App\Models\FiscalReceipt;
use App\Models\Invoice;
use App\Models\SefMapping;
use App\Services\InvoiceService;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;

/** Read-only adapter of existing posted source values to the pinned ATK Protobuf schema. */
class SefPayloadBuilder
{
    public function build(Invoice $invoice, FiscalConfiguration $config, string $couponNo, string $verificationNo): array
    {
        $invoice = app(InvoiceService::class)->find($invoice);
        if ((int) $invoice->company_id !== (int) $config->company_id) {
            abort(404);
        }
        if (! $invoice->issued_at || $invoice->currency !== 'EUR') {
            $this->fail('A posted EUR tax document is required.');
        }
        $catalog = json_decode(file_get_contents(resource_path('sef/catalog.json')), true, 512, JSON_THROW_ON_ERROR);
        $units = collect($catalog['units'])->keyBy('id');
        $categories = collect($catalog['categories'])->keyBy('id');
        $maps = SefMapping::query()->get()->keyBy(fn ($m) => $m->kind.':'.$m->local_key);
        $items = [];
        $groups = [];
        $net = '0.00';
        $vat = '0.00';
        $gross = '0.00';
        $discount = '0.00';
        foreach ($invoice->items as $line) {
            $snapshot = $line->tax_snapshot;
            if (! $snapshot || empty($snapshot['sef_tax_group'])) {
                $this->fail($line->description.' has no frozen SEF tax mapping.');
            }
            $tax = $snapshot['sef_tax_group'];
            $expected = ['A' => '0', 'C' => '0', 'D' => '8', 'E' => '18'];
            if (! isset($expected[$tax]) || Money::compareDecimal($snapshot['rate'], $expected[$tax]) !== 0) {
                $this->fail('A frozen tax code disagrees with the published SEF group.');
            }
            $unit = $units->get($maps->get('unit:'.$line->unit)?->official_id);
            if (! $unit) {
                $this->fail($line->description.' has no SEF measurement-unit mapping for '.$line->unit.'.');
            }
            $categoryId = $line->sef_category_id ?: $maps->get('category:'.$line->product?->category_id)?->official_id;
            $category = $categories->get($categoryId);
            if (! $category) {
                $this->fail($line->description.' has no SEF goods/service category mapping.');
            }
            if (isset($catalog['ambiguous_category_codes'][$category['code']])) {
                $this->fail('The official catalog duplicates category '.$category['code'].'. Obtain ATK clarification before using it.');
            }
            if (Money::compare(Money::add($line->taxable_amount, $line->vat_amount), $line->line_total) !== 0) {
                $this->fail('The source line tax totals are inconsistent.');
            }
            // Price uses ten-thousandths of EUR; totals use cents. Discounts remain source evidence.
            $unitGross = $line->pricing_mode === 'inclusive' ? $line->unit_price : Money::product([$line->unit_price, BigDecimal::of('1')->plus(BigDecimal::of((string) $line->vat_rate)->dividedBy('100', 6))], 4);
            $item = new CouponItem;
            $item->setName($line->description);
            $item->setPrice((int) Money::multiply($unitGross, '10000', 0));
            $item->setUnit($unit['code']);
            $quantity=unpack('f',pack('f',(float)$line->quantity))[1];
            if (Money::compareDecimal(Money::normalizeDecimal((string)$quantity,3),(string)$line->quantity)!==0) {
                $this->fail('This quantity cannot be represented at the official protocol precision. Split/review the source quantity before fiscalization.');
            }
            $item->setQuantity($quantity);
            $item->setTotal(Money::minor($line->line_total));
            $item->setTaxRate($tax);
            $item->setType($category['code']);
            $items[] = $item;
            $groups[$tax] ??= ['net' => '0.00', 'vat' => '0.00'];
            $groups[$tax]['net'] = Money::add($groups[$tax]['net'], $line->taxable_amount);
            $groups[$tax]['vat'] = Money::add($groups[$tax]['vat'], $line->vat_amount);
            $net = Money::add($net, $line->taxable_amount);
            $vat = Money::add($vat, $line->vat_amount);
            $gross = Money::add($gross, $line->line_total);
            $discount = Money::add($discount, Money::subtract(Money::multiply($unitGross, $line->quantity), $line->line_total));
        }
        if (Money::compare($net, $invoice->taxable_total) !== 0 || Money::compare($vat, $invoice->vat_total) !== 0 || Money::compare($gross, $invoice->grand_total) !== 0) {
            $this->fail('Document and line totals differ.');
        }
        $payments = $this->payments($invoice, $gross);
        $type = $invoice->document_type === 'credit_note' ? CouponType::PBReturn : CouponType::Sale;
        $reference = '0';
        if ($type === CouponType::PBReturn) {
            $original = FiscalReceipt::where('invoice_id', $invoice->original_invoice_id)->where('environment', $config->environment)->where('status', 'fiscalized')->first();
            if (! $original) {
                $this->fail('A return requires confirmed fiscal evidence for the original sale.');
            }
            $reference = $original->coupon_no;
        }
        $taxGroups = [];
        foreach ($groups as $tax => $totals) {
            $g = new TaxGroup;
            $g->setTaxRate($tax);
            $g->setTotalForTax(Money::minor($totals['net']));
            $g->setTotalTax(Money::minor($totals['vat']));
            $taxGroups[] = $g;
        }
        $pos = new PosCoupon;
        $pos->setBusinessId($config->taxpayer_nui);
        $pos->setCouponId($couponNo);
        $pos->setBranchId($config->branch_id);
        $pos->setLocation($config->location);
        $pos->setOperatorId((string) $invoice->issued_by);
        $pos->setPosId($config->pos_id);
        $pos->setApplicationId($config->application_id);
        $pos->setVerificationNo($verificationNo);
        $pos->setType($type);
        $pos->setTime($invoice->issued_at->getTimestamp());
        $pos->setItems($items);
        $pos->setPayments($payments);
        $pos->setTotal(Money::minor($gross));
        $pos->setTaxGroups($taxGroups);
        $pos->setTotalTax(Money::minor($vat));
        $pos->setTotalNoTax(Money::minor($net));
        $pos->setReferenceNo($reference);
        $pos->setTotalDiscount(Money::minor($discount));
        $citizen = new CitizenCoupon;
        $citizen->setBusinessId($config->taxpayer_nui);
        $citizen->setCouponId($couponNo);
        $citizen->setBranchId($config->branch_id);
        $citizen->setPosId($config->pos_id);
        $citizen->setVerificationNo($verificationNo);
        $citizen->setType($type);
        $citizen->setTime($invoice->issued_at->getTimestamp());
        $citizen->setTotal(Money::minor($gross));
        $citizen->setTaxGroups($taxGroups);
        $citizen->setTotalTax(Money::minor($vat));
        $citizen->setTotalNoTax(Money::minor($net));

        return ['details' => base64_encode($pos->serializeToString()), 'citizen_details' => base64_encode($citizen->serializeToString()), 'source_fingerprint' => $this->fingerprint($invoice)];
    }

    public function fingerprint(Invoice $invoice): string
    {
        $invoice = app(InvoiceService::class)->find($invoice);
        $payments = $invoice->payments->map(fn ($p) => $p->only(['id', 'amount', 'status', 'payment_method', 'payment_date', 'reversal_date', 'reversed_at']))->all();

        return hash('sha256', json_encode(['invoice_id' => $invoice->id, 'integrity' => $invoice->integrity_hash, 'payments' => $payments, 'source_settlement' => $invoice->source_settlement], JSON_THROW_ON_ERROR));
    }

    private function payments(Invoice $invoice, string $gross): array
    {
        $rows = $invoice->payments->where('status', 'completed')->whereNull('reversed_at');
        if ($invoice->document_type === 'credit_note') {
            $original = $invoice->originalInvoice()->with('payments')->firstOrFail();
            if (Money::compare($gross, $original->grand_total) !== 0) {
                $this->fail('Partial fiscal refunds require a verified payment/refund mapping and remain blocked.');
            }
            $rows = $original->payments->whereNotNull('reversed_at');
        }
        $by = [];
        $sum = '0.00';
        $mapping = ['cash' => 1, 'card' => 2, 'credit_card' => 2, 'voucher' => 3, 'cheque' => 4, 'crypto' => 5, 'bank_transfer' => 6];
        foreach ($rows as $payment) {
            if (! isset($mapping[$payment->payment_method])) {
                $this->fail('A source payment method lacks a verified SEF mapping.');
            }
            $kind = $mapping[$payment->payment_method];
            $by[$kind] = Money::add($by[$kind] ?? 0, $payment->amount);
            $sum = Money::add($sum, $payment->amount);
        }
        if (! $rows->count() && $invoice->daily_sale_id && $invoice->dailySale?->outboundDispatch?->order?->payment_type === 'cash') {
            $by[1] = $gross;
            $sum = $gross;
        }
        if (Money::compare($sum, $gross) !== 0) {
            $this->fail('Source payments/refunds must match this receipt exactly. Unpaid/partial on-credit fiscal mapping needs ATK confirmation.');
        }
        $result = [];
        foreach ($by as $type => $amount) {
            $p = new Payment;
            $p->setType($type);
            $p->setAmount(Money::minor($amount));
            $result[] = $p;
        }

        return $result;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['fiscalization' => $message]);
    }
}
