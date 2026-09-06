<?php
namespace Tests\Feature;
use App\Models\ApprovalRule;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcurementApprovalTest extends TestCase {
 use RefreshDatabase;
 public function test_multi_item_request_moves_through_approval_rfq_quotes_award_and_purchase_order(): void {
  [$first,$second,$supplierA,$supplierB]=$this->catalogue();
  ApprovalRule::create($this->tenantAttributes(['rule_type'=>'purchase_request','threshold_amount'=>'100.00','currency'=>'EUR','required_role'=>'manager','separation_of_duties'=>true,'is_active'=>true]));
  $request=$this->postJson('/api/purchase-requests',$this->requestPayload($first,$second))->assertCreated()->assertJsonCount(2,'items');
  $this->postJson('/api/purchase-requests/'.$request->json('id').'/submit')->assertOk()->assertJsonPath('status','submitted');
  $this->postJson('/api/purchase-requests/'.$request->json('id').'/rfqs',['supplier_ids'=>[$supplierA->id]])->assertUnprocessable();
  $manager=User::factory()->create(['company_id'=>$this->apiCompany->id,'role'=>'manager','email_verified_at'=>now(),'api_token'=>hash('sha256','manager-token')]);
  $this->withHeader('Authorization','Bearer manager-token')->getJson('/api/approvals/pending')->assertOk()->assertJsonCount(1);
  $approvalId=$this->withHeader('Authorization','Bearer manager-token')->getJson('/api/approvals/pending')->json('0.id');
  $this->withHeader('Authorization','Bearer manager-token')->postJson('/api/approvals/'.$approvalId.'/decision',['decision'=>'approved','comment'=>'Budget approved'])->assertOk();
  $this->withHeader('Authorization','Bearer manager-token')->postJson('/api/purchase-requests/'.$request->json('id').'/rfqs',['supplier_ids'=>[$supplierA->id,$supplierB->id],'response_due_at'=>now()->addDays(10)->toDateString()])->assertCreated();
  $rfqId=$this->withHeader('Authorization','Bearer manager-token')->getJson('/api/purchase-requests/'.$request->json('id'))->json('rfqs.0.id');
  $this->withHeader('Authorization','Bearer manager-token')->postJson('/api/rfqs/'.$rfqId.'/issue')->assertOk();
  $firstItem=$request->json('items.0.id');$secondItem=$request->json('items.1.id');
  $quoteA=$this->withHeader('Authorization','Bearer manager-token')->postJson('/api/rfqs/'.$rfqId.'/quotes',$this->quotePayload($supplierA,[$firstItem=>['quantity'=>3,'price'=>'10.10'],$secondItem=>['quantity'=>4,'price'=>'12.20']]))->assertCreated();
  $quoteB=$this->withHeader('Authorization','Bearer manager-token')->postJson('/api/rfqs/'.$rfqId.'/quotes',$this->quotePayload($supplierB,[$firstItem=>['quantity'=>3,'price'=>'9.99']]))->assertCreated();
  $comparison=$this->withHeader('Authorization','Bearer manager-token')->getJson('/api/rfqs/'.$rfqId.'/comparison')->assertOk()->json();
  $this->assertCount(2,$comparison['quotes']);
  $selected=[$quoteB->json('items.0.id'),$quoteA->json('items.1.id')];
  $this->withHeader('Authorization','Bearer manager-token')->postJson('/api/rfqs/'.$rfqId.'/award',['quote_item_ids'=>$selected])->assertOk()->assertJsonPath('status','awarded');
  $converted=$this->withHeader('Authorization','Bearer manager-token')->postJson('/api/rfqs/'.$rfqId.'/convert',['idempotency_key'=>'2fc2e62c-171f-4e94-adc6-ec8d4f271001'])->assertOk()->json('purchase_order_ids');
  $this->assertCount(2,$converted);
  $this->withHeader('Authorization','Bearer manager-token')->postJson('/api/rfqs/'.$rfqId.'/convert',['idempotency_key'=>'2fc2e62c-171f-4e94-adc6-ec8d4f271001'])->assertOk()->assertJsonCount(2,'purchase_order_ids');
 }
 public function test_self_approval_is_blocked_and_company_isolation_applies(): void {[$first,$second]=$this->catalogue();ApprovalRule::create($this->tenantAttributes(['rule_type'=>'purchase_request','threshold_amount'=>'0','required_role'=>'admin','separation_of_duties'=>true,'is_active'=>true]));$request=$this->postJson('/api/purchase-requests',$this->requestPayload($first,$second))->assertCreated();$this->postJson('/api/purchase-requests/'.$request->json('id').'/submit')->assertOk();$approval=$this->getJson('/api/approvals/pending')->assertOk()->json('0.id');$this->postJson('/api/approvals/'.$approval.'/decision',['decision'=>'approved'])->assertUnprocessable();$other=User::factory()->create(['company_id'=>\App\Models\Company::factory()->create()->id,'role'=>'admin','api_token'=>hash('sha256','other-token'),'email_verified_at'=>now()]);$this->withToken('other-token')->getJson('/api/purchase-requests/'.$request->json('id'))->assertNotFound();}
 public function test_purchase_order_approval_blocks_ordering_once_until_another_user_approves(): void {[$product,,$supplier]=$this->catalogue();ApprovalRule::create($this->tenantAttributes(['rule_type'=>'purchase_order','threshold_amount'=>'100.00','required_role'=>'manager','separation_of_duties'=>true,'is_active'=>true]));$order=$this->postJson('/api/purchase-orders',['supplier_id'=>$supplier->id,'ordered_at'=>now()->toDateString(),'currency'=>'EUR','status'=>'draft','items'=>[['product_id'=>$product->id,'description'=>$product->name,'unit'=>'pcs','quantity'=>2,'unit_price'=>100]]])->assertCreated();$this->patchJson('/api/purchase-orders/'.$order->json('id').'/status',['status'=>'ordered'])->assertUnprocessable();$this->patchJson('/api/purchase-orders/'.$order->json('id').'/status',['status'=>'ordered'])->assertUnprocessable();$this->assertDatabaseCount('approval_requests',1);$manager=User::factory()->create(['company_id'=>$this->apiCompany->id,'role'=>'manager','api_token'=>hash('sha256','po-manager'),'email_verified_at'=>now()]);$approval=$this->withToken('po-manager')->getJson('/api/approvals/pending')->json('0.id');$this->withToken('po-manager')->postJson('/api/approvals/'.$approval.'/decision',['decision'=>'approved'])->assertOk();$this->withToken('test-token-admin')->patchJson('/api/purchase-orders/'.$order->json('id').'/status',['status'=>'ordered'])->assertOk()->assertJsonPath('status','ordered');}
 private function catalogue():array{$this->actingAsApiUser('admin');$category=Category::create($this->tenantAttributes(['name'=>'Procurement']));$first=Product::create($this->tenantAttributes(['category_id'=>$category->id,'name'=>'First','sku'=>'PR-1','quantity'=>0,'unit'=>'pcs','price'=>1]));$second=Product::create($this->tenantAttributes(['category_id'=>$category->id,'name'=>'Second','sku'=>'PR-2','quantity'=>0,'unit'=>'pcs','price'=>1]));$a=Supplier::create($this->tenantAttributes(['name'=>'Supplier A']));$b=Supplier::create($this->tenantAttributes(['name'=>'Supplier B']));return [$first,$second,$a,$b];}
 private function requestPayload($a,$b):array{return ['requested_at'=>now()->toDateString(),'required_by'=>now()->addDays(5)->toDateString(),'currency'=>'EUR','notes'=>'Two items required.','items'=>[['product_id'=>$a->id,'description'=>$a->name,'unit'=>'pcs','quantity'=>3,'estimated_unit_price'=>'50.00'],['product_id'=>$b->id,'description'=>$b->name,'unit'=>'pcs','quantity'=>4,'estimated_unit_price'=>'40.00']]];}
 private function quotePayload($supplier,array $items):array{return ['supplier_id'=>$supplier->id,'currency'=>'EUR','exchange_rate'=>1,'payment_terms'=>'30 days','shipping_terms'=>'DAP','valid_until'=>now()->addDays(20)->toDateString(),'items'=>collect($items)->map(fn($row,$id)=>['purchase_request_item_id'=>$id,'offered_quantity'=>$row['quantity'],'unit_price'=>$row['price'],'minimum_order_quantity'=>1,'lead_time_days'=>7])->values()->all()];}
}
