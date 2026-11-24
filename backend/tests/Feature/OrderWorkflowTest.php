<?php
namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\{Category, Customer, Product, DailySale, Invoice, InvoiceProfile, JournalEntry, Shipment, Supplier};

class OrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $terms='cash', int $price=5): array
    {
        $this->actingAsApiUser();
        $category=Category::create($this->tenantAttributes(['name'=>'Handles']));
        $warehouse=$this->postJson('/api/warehouses',['name'=>'Main','code'=>'MAIN','is_default'=>true])->assertCreated()->json();
        $p=$this->postJson('/api/products',['name'=>'Door handle','sku'=>'HANDLE','barcode'=>'123456789012','category_id'=>$category->id,'default_warehouse_id'=>$warehouse['id'],'unit'=>'pcs','quantity'=>20,'min_quantity'=>1,'purchase_price'=>2,'selling_price'=>$price,'price'=>$price])->assertCreated()->json();
        $c=Customer::create($this->tenantAttributes(['name'=>'Order buyer','business_name'=>'Order buyer LLC','business_registration_number'=>'811222333','address'=>'Main Street','is_active'=>true,'current_debt'=>0,'current_credit'=>0,'email'=>'order@example.test']));
        $i=$this->postJson('/api/order-hub/orders',['idempotency_key'=>'manual-first','customer_id'=>$c->id,'order_date'=>today()->toDateString(),'payment_type'=>$terms,'items'=>[['product_id'=>$p['id'],'quantity'=>4]]])->assertCreated()->assertJsonPath('state','validated')->json();
        return [$p,$c,$i];
    }

    public function test_fractional_import_can_be_received_delivered_and_invoiced_without_duplicate_stock(): void
    {
        $this->actingAsApiUser();
        $date = \App\Support\CompanyClock::today()->toDateString();
        $category = Category::create($this->tenantAttributes(['name' => 'Fabric']));
        $supplier = Supplier::create($this->tenantAttributes(['name' => 'Wholesale textiles']));
        $warehouse = $this->postJson('/api/warehouses', ['name' => 'Main', 'code' => 'MAIN', 'is_default' => true])->assertCreated()->json('id');
        $product = $this->postJson('/api/products', [
            'name' => 'Imported linen', 'sku' => 'LINEN-M', 'barcode' => '123456789012',
            'category_id' => $category->id, 'supplier_id' => $supplier->id, 'default_warehouse_id' => $warehouse,
            'unit' => 'm', 'quantity' => 0, 'min_quantity' => 1, 'purchase_price' => 2, 'selling_price' => 5, 'price' => 5,
        ])->assertCreated()->json('id');
        $po = $this->postJson('/api/purchase-orders', [
            'supplier_id' => $supplier->id, 'ordered_at' => $date, 'status' => 'ordered',
            'warehouse_id' => $warehouse, 'currency' => 'EUR',
            'items' => [['product_id' => $product, 'description' => 'Linen', 'unit' => 'm', 'quantity' => 12.5, 'unit_price' => 2]],
        ])->assertCreated()->json();
        $shipment = Shipment::create($this->tenantAttributes(['tracking_number' => 'DC38-IMPORT', 'transport_mode' => 'sea', 'status' => 'in_transit']));
        $arrived = $this->putJson('/api/shipments/'.$shipment->id.'/logistics', [
            'purchase_order_id' => $po['id'], 'warehouse_id' => $warehouse, 'arrival_date' => $date,
            'items' => [['purchase_order_item_id' => $po['items'][0]['id'], 'product_id' => $product,
                'description' => 'Linen', 'unit' => 'm', 'quantity' => 12.5, 'loaded_quantity' => 12.5, 'base_quantity' => 12.5]],
        ])->assertOk()->assertJsonPath('purchase_order_id', $po['id'])->json();
        $receiptPath = "/api/purchase-orders/{$po['id']}/receive";
        $receiptLine = ['id' => $po['items'][0]['id'], 'shipment_item_id' => $arrived['items'][0]['id']];
        $receipt = ['idempotency_key' => (string) \Illuminate\Support\Str::uuid(), 'items' => [$receiptLine + ['quantity' => 7.75]]];
        $this->postJson($receiptPath, $receipt)->assertOk()->assertJsonPath('status', 'partially_received');
        $this->postJson($receiptPath, $receipt)->assertOk()->assertJsonPath('status', 'partially_received');
        $this->assertEquals(7.75, Product::findOrFail($product)->quantity);
        $this->postJson($receiptPath, ['idempotency_key' => (string) \Illuminate\Support\Str::uuid(), 'items' => [$receiptLine + ['quantity' => 4.75]]])
            ->assertOk()->assertJsonPath('status', 'received');
        $this->assertDatabaseCount('goods_receipts', 2);

        $buyer = Customer::create($this->tenantAttributes(['name' => 'Curtain shop', 'business_name' => 'Curtain shop LLC',
            'business_registration_number' => '811222333', 'address' => 'Main Street', 'is_active' => true]));
        $intake = $this->postJson('/api/order-hub/orders', [
            'idempotency_key' => 'dc38-order', 'customer_id' => $buyer->id, 'order_date' => $date, 'payment_type' => 'cash',
            'items' => [['product_id' => $product, 'quantity' => 5.5, 'unit' => 'm']],
        ])->assertCreated()->json();
        $path = "/api/order-hub/intakes/{$intake['id']}";
        $this->postJson($path.'/confirm', ['idempotency_key' => 'dc38-confirm'])->assertOk();
        $ready = $this->postJson($path.'/ready', ['idempotency_key' => 'dc38-pack', 'verified' => true])->assertOk()->json('order');
        $outbound = "/api/sales-orders/{$ready['id']}";
        $dispatchPayload = ['idempotency_key' => 'dc38-dispatch', 'package_ids' => [$ready['packages'][0]['id']]];
        $dispatch = $this->postJson($outbound.'/dispatch', $dispatchPayload)->assertOk()->json('dispatches.0');
        $this->postJson($outbound.'/dispatch', $dispatchPayload)->assertOk()->assertJsonCount(1, 'dispatches');
        $this->postJson($outbound.'/delivery', ['idempotency_key' => 'dc38-delivery-part', 'dispatch_id' => $dispatch['id'], 'recipient' => 'Buyer',
            'items' => [['package_item_id' => $dispatch['packages'][0]['items'][0]['id'], 'quantity' => 3.125]]])
            ->assertOk()->assertJsonPath('status', 'partially_delivered');
        $this->postJson($outbound.'/delivery', ['idempotency_key' => 'dc38-delivery-rest', 'dispatch_id' => $dispatch['id'], 'recipient' => 'Buyer'])
            ->assertOk()->assertJsonPath('status', 'delivered');

        InvoiceProfile::create($this->tenantAttributes(['legal_name'=>'Wholesale textiles','business_registration_number'=>'811234567','fiscal_number'=>'600987654','is_vat_registered'=>true,'vat_number'=>'330987654','registered_address'=>'Main 1','municipality'=>'Prishtine','country_code'=>'XK','invoice_prefix'=>'INV','credit_note_prefix'=>'CN','default_language'=>'bilingual','sales_mode'=>'business_only','default_payment_terms_days'=>0]));
        $invoice = $this->postJson($path.'/invoice', ['daily_sale_id' => $dispatch['daily_sale_id']])->assertCreated()->json('id');
        $this->postJson("/api/invoices/{$invoice}/issue")->assertOk()->assertJsonPath('grand_total', '27.50');
        $this->assertEquals(7, Product::findOrFail($product)->quantity);
        $this->assertDatabaseCount('daily_sales', 1);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_search_default_channel_ready_sale_invoice_and_stock_remain_connected(): void
    {
        [$p,$c,$i]=$this->fixture();
        $this->getJson('/api/order-hub/lookups?kind=product&search=123456789012')->assertOk()->assertJsonPath('0.name','Door handle')->assertJsonPath('0.availability.available',fn($v)=>(float)$v===20.0);
        $this->getJson('/api/order-hub/lookups?kind=customer&search=order@example.test')->assertOk()->assertJsonPath('0.id',$c->id);
        $path='/api/order-hub/intakes/'.$i['id'];
        $this->postJson($path.'/confirm',['idempotency_key'=>'confirm'])->assertOk();
        $this->postJson($path.'/ready',['idempotency_key'=>'ready','verified'=>false])->assertUnprocessable();
        $ready=$this->postJson($path.'/ready',['idempotency_key'=>'ready','verified'=>true])->assertOk()->assertJsonPath('order.status','packed')->json();
        $this->postJson($path.'/ready',['idempotency_key'=>'ready','verified'=>true])->assertOk();
        $this->assertDatabaseCount('daily_sales',0);
        $this->assertEquals(20,Product::find($p['id'])->quantity);
        $dispatch='/api/sales-orders/'.$i['order']['id'].'/dispatch';
        $body=['idempotency_key'=>'dispatch','package_ids'=>[$ready['order']['packages'][0]['id']]];
        $this->postJson($dispatch,$body)->assertOk();$this->postJson($dispatch,$body)->assertOk();
        $this->assertDatabaseCount('daily_sales',1);$this->assertEquals(16,Product::find($p['id'])->quantity);
        $this->getJson($path)->assertOk()->assertJsonPath('states.paid_amount','20.00')->assertJsonPath('states.remaining_amount','0.00');
        $sale=DailySale::first();
        $this->getJson('/api/daily-sales?source=order')->assertOk()->assertJsonPath('0.outbound_dispatch.order.intake.id',$i['id']);
        $this->deleteJson('/api/daily-sales/'.$sale->id)->assertUnprocessable();
        InvoiceProfile::create($this->tenantAttributes(['legal_name'=>'AIMS Test','business_registration_number'=>'811234567','fiscal_number'=>'600987654','is_vat_registered'=>true,'vat_number'=>'330987654','registered_address'=>'Main 1','municipality'=>'Prishtine','country_code'=>'XK','invoice_prefix'=>'INV','credit_note_prefix'=>'CN','default_language'=>'bilingual','sales_mode'=>'business_only','default_payment_terms_days'=>0]));
        $invoice=$this->postJson($path.'/invoice',['daily_sale_id'=>$sale->id])->assertCreated()->assertJsonPath('grand_total','20.00')->json();
        $this->postJson($path.'/invoice',['daily_sale_id'=>$sale->id])->assertCreated()->assertJsonPath('id',$invoice['id']);
        $this->postJson('/api/invoices/'.$invoice['id'].'/issue')->assertOk()->assertJsonPath('status','paid')->assertJsonPath('grand_total','20.00');
        $this->postJson('/api/invoices/'.$invoice['id'].'/issue')->assertOk();
        $this->assertDatabaseCount('invoices',1);$this->assertDatabaseCount('daily_sales',1);$this->assertEquals(16,Product::find($p['id'])->quantity);
        $this->assertDatabaseCount('documents',1);
        $document=\App\Models\Document::firstOrFail();
        $this->assertSame('restricted',$document->confidentiality);
        $this->assertTrue($document->legal_hold);
        $this->assertDatabaseHas('document_links',['document_id'=>$document->id,'entity_type'=>'invoice','entity_id'=>$invoice['id']]);
        $this->assertDatabaseHas('document_links',['document_id'=>$document->id,'entity_type'=>'sales-order','entity_id'=>$i['order']['id']]);
        $version=$document->currentFile;
        $this->assertSame($version->checksum,app(\App\Contracts\DocumentStorageProvider::class)->checksum($version->getRawOriginal('storage_key')));
        $this->get('/api/documents/'.$document->id.'/versions/1/file')->assertOk()->assertHeader('Content-Type','application/pdf');
        $this->getJson('/api/order-hub?view=paid')->assertOk()->assertJsonPath('data.0.id',$i['id']);
        $this->assertEquals(0,JournalEntry::where('source_type','invoice')->count());
        $this->assertEquals(1,JournalEntry::where('source_type','order_invoice_tax')->count());
        $this->getJson($path)->assertOk()->assertJsonPath('connections.invoices.0.id',$invoice['id']);
        $order=$this->getJson('/api/sales-orders/'.$i['order']['id'])->assertOk()->json();
        $this->postJson('/api/sales-orders/'.$order['id'].'/delivery',['idempotency_key'=>'delivered','dispatch_id'=>$order['dispatches'][0]['id'],'recipient'=>'Buyer'])->assertOk();
        $r=$this->postJson('/api/sales-orders/'.$order['id'].'/return',['idempotency_key'=>'r1','stage'=>'requested','allocation_id'=>$order['allocations'][0]['id'],'quantity'=>2,'reason'=>'Wrong size'])->assertOk()->json('returns.0.id');
        foreach(['authorized','received','resolved'] as $stage)$this->postJson('/api/sales-orders/'.$order['id'].'/return',['idempotency_key'=>'r-'.$stage,'stage'=>$stage,'return_id'=>$r,'disposition'=>'sellable'])->assertOk();
        $credit=Invoice::where('document_type','credit_note')->firstOrFail();
        $this->assertEquals('10.00',$credit->grand_total);$this->assertEquals('1.53',$credit->vat_total);
        $this->assertEquals(18,Product::find($p['id'])->quantity);
        $this->assertDatabaseCount('documents',2);
        $this->assertEquals(1,JournalEntry::where('source_module','returns')->where('source_type','order_invoice_tax')->count());
    }

    public function test_cancel_releases_stock_and_does_not_create_a_sale(): void
    {
        [$p,$c,$i]=$this->fixture();$path='/api/order-hub/intakes/'.$i['id'];
        $this->postJson($path.'/confirm',['idempotency_key'=>'confirm'])->assertOk();
        $this->postJson($path.'/cancel',['idempotency_key'=>'cancel','reason'=>'Buyer cancelled'])->assertOk();
        $this->assertDatabaseCount('daily_sales',0);$this->assertEquals(20,Product::find($p['id'])->quantity);
    }

    public function test_customer_advance_payment_is_reused_without_duplicate_money(): void
    {
        [$p,$c,$i]=$this->fixture('prepaid');$path='/api/order-hub/intakes/'.$i['id'];
        $this->postJson($path.'/confirm',['idempotency_key'=>'unpaid'])->assertUnprocessable();
        $account=$this->postJson('/api/finance/accounts',['name'=>'Cash','type'=>'cashbox','currency'=>'EUR','opening_balance'=>0,'opening_date'=>today()->toDateString(),'is_active'=>true])->assertCreated()->json();
        $payment=['idempotency_key'=>'receipt','amount'=>'20.00','transaction_date'=>today()->toDateString(),'payment_method'=>'cash','financial_account_id'=>$account['id']];
        $this->postJson($path.'/payment',$payment)->assertOk();$this->postJson($path.'/payment',$payment)->assertOk();
        $this->assertDatabaseCount('financial_account_transactions',1);$this->assertEquals(20,$c->fresh()->current_credit);
        $this->postJson($path.'/confirm',['idempotency_key'=>'paid'])->assertOk();
        $ready=$this->postJson($path.'/ready',['idempotency_key'=>'ready','verified'=>true])->assertOk()->json();
        $this->postJson('/api/sales-orders/'.$i['order']['id'].'/dispatch',['idempotency_key'=>'depart','package_ids'=>[$ready['order']['packages'][0]['id']]])->assertOk();
        $this->assertEquals(0,$c->fresh()->current_credit);$this->assertEquals(0,$c->fresh()->current_debt);
        $this->assertEquals('0.00',DailySale::firstOrFail()->paid_amount);
        $this->getJson($path)->assertOk()->assertJsonPath('states.payment','paid')->assertJsonPath('states.paid_amount','20.00')->assertJsonPath('states.remaining_amount','0.00');
        $this->assertDatabaseCount('daily_sales',1);$this->assertEquals(16,Product::find($p['id'])->quantity);
    }

    public function test_kosovo_order_advance_checks_the_undispatched_order_and_normalizes_the_date(): void
    {
        [, $customer, $intake] = $this->fixture('prepaid', 100);
        $customer->update(['customer_type' => 'business']);
        InvoiceProfile::create($this->tenantAttributes(['legal_name' => 'Synthetic Kosovo Seller', 'country_code' => 'XK', 'registered_address' => 'Synthetic Street', 'municipality' => 'Prishtina']));
        $accounts = app(\App\Services\FinancialAccountService::class);
        $cash = $accounts->createAccount(['name' => 'Cash', 'type' => 'cashbox', 'currency' => 'EUR', 'opening_balance' => 0]);
        $bank = $accounts->createAccount(['name' => 'Bank', 'type' => 'bank', 'currency' => 'EUR', 'opening_balance' => 0]);
        $path = '/api/order-hub/intakes/'.$intake['id'].'/payment';
        $payment = ['idempotency_key' => 'partial-advance', 'amount' => '20.00', 'transaction_date' => '09/20/2026', 'payment_method' => 'cash', 'financial_account_id' => $cash->id];
        $this->postJson($path, $payment)->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $this->assertDatabaseCount('financial_account_transactions', 0);
        $this->postJson($path, [...$payment, 'payment_method' => 'bank_transfer', 'financial_account_id' => $bank->id])->assertOk();
        $this->assertDatabaseCount('financial_account_transactions', 1);
    }

    public function test_order_invoice_displays_customer_ledger_settlement_without_duplicate_receipts(): void
    {
        [$p,$c,$i]=$this->fixture('credit');$path='/api/order-hub/intakes/'.$i['id'];
        $this->postJson($path.'/confirm',['idempotency_key'=>'confirm'])->assertOk();
        $ready=$this->postJson($path.'/ready',['idempotency_key'=>'ready','verified'=>true])->assertOk()->json();
        $this->postJson('/api/sales-orders/'.$i['order']['id'].'/dispatch',['idempotency_key'=>'dispatch','package_ids'=>[$ready['order']['packages'][0]['id']]])->assertOk();
        InvoiceProfile::create($this->tenantAttributes(['legal_name'=>'AIMS Test','business_registration_number'=>'811234567','fiscal_number'=>'600987654','is_vat_registered'=>true,'vat_number'=>'330987654','registered_address'=>'Main 1','municipality'=>'Prishtine','country_code'=>'XK','invoice_prefix'=>'INV','credit_note_prefix'=>'CN','default_language'=>'bilingual','sales_mode'=>'business_only','default_payment_terms_days'=>0]));
        $invoice=$this->postJson($path.'/invoice',['daily_sale_id'=>DailySale::firstOrFail()->id])->assertCreated()->json();
        $this->postJson('/api/invoices/'.$invoice['id'].'/issue')->assertOk()->assertJsonPath('source_settlement.outstanding','20.00');
        $account=$this->postJson('/api/finance/accounts',['name'=>'Order receipts','type'=>'cashbox','currency'=>'EUR','opening_balance'=>0,'opening_date'=>today()->toDateString()])->assertCreated()->json();
        $payment=['idempotency_key'=>'half-paid','amount'=>'10.00','transaction_date'=>today()->toDateString(),'payment_method'=>'cash','financial_account_id'=>$account['id']];
        $this->postJson($path.'/payment',$payment)->assertOk();
        $this->getJson('/api/invoices/'.$invoice['id'])->assertOk()->assertJsonPath('source_settlement.outstanding','10.00')->assertJsonPath('source_settlement.settled','10.00');
        $this->getJson('/api/invoices?payment_status=partially_paid')->assertOk()->assertJsonPath('data.0.id',$invoice['id']);
        $this->getJson('/api/invoices?payment_status=paid')->assertOk()->assertJsonCount(0,'data');
        $this->getJson('/api/order-hub?view=partially_paid')->assertOk()->assertJsonPath('data.0.id',$i['id']);
        $this->getJson($path)->assertOk()->assertJsonPath('states.paid_amount','10.00')->assertJsonPath('states.remaining_amount','10.00');
        $this->getJson('/api/order-hub?view=paid')->assertOk()->assertJsonCount(0,'data');
        $this->postJson($path.'/payment',$payment)->assertOk();
        $this->assertDatabaseCount('financial_account_transactions',1);
        $this->assertDatabaseCount('payment_transactions',0);
        $links=$this->getJson($path)->assertOk()->json('connections');
        $this->assertCount(1,$links['financial_transactions']);
        $this->assertNotEmpty($links['journals']);
        $this->assertContains($links['financial_transactions'][0]['journal_entry_id'],array_column($links['journals'],'id'));

        $this->assertEquals(16,Product::find($p['id'])->quantity);
    }
}
