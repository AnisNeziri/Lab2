<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth,DB};
use App\Models\{PilotEvidence,Company,User};
class DC11CommissioningTest extends TestCase
{
    use RefreshDatabase;
    public function test_an_orphan_line_cannot_attach_to_a_new_po_when_its_parent_identifier_is_reused(): void
    {
        $this->actingAsApiUser();$this->getJson('/api/me')->assertOk();
        $supplier=\App\Models\Supplier::create($this->tenantAttributes(['name'=>'Fixture supplier']));
        DB::statement('PRAGMA defer_foreign_keys=ON');
        DB::table('purchase_order_items')->insert(['purchase_order_id'=>1,'description'=>'Preserved orphan witness','unit'=>'pcs','quantity'=>1,'unit_price'=>2,'line_total'=>2,'created_at'=>now(),'updated_at'=>now()]);
        $this->assertFalse(app(\App\Services\InstallationBackupService::class)->reconcile()['checks']['relational_links']);
        $this->postJson('/api/purchase-orders',['supplier_id'=>$supplier->id,'currency'=>'EUR','ordered_at'=>today()->toDateString(),'status'=>'draft','items'=>[['description'=>'New fixture line','unit'=>'pcs','quantity'=>1,'unit_price'=>2]]])->assertUnprocessable()->assertJsonValidationErrors('integrity');
        $this->assertDatabaseCount('purchase_orders',0);$this->assertDatabaseCount('purchase_order_items',1);
    }
    public function test_notes_dates_generic_evidence_and_foreign_reviews_cannot_authorize_a_pilot(): void
    {
        $this->actingAsApiUser();$this->getJson('/api/me')->assertOk();$owner=Auth::user();
        $foreign=Company::factory()->create();$foreignUser=User::factory()->create(['company_id'=>$foreign->id]);
        DB::table('pilot_evidence')->insert(['company_id'=>$foreign->id,'kind'=>'commissioning_scope','observed_on'=>today(),'outcome'=>'confirmed','checks'=>json_encode([['name'=>'Fiscalization scope','result'=>'confirmed'],['name'=>'Payroll scope','result'=>'confirmed'],['name'=>'Runtime security commissioning','result'=>'confirmed']]),'prepared_by'=>$foreignUser->id,'reviewed_by'=>$owner->id,'reviewed_at'=>now(),'is_historical'=>false,'created_at'=>now(),'updated_at'=>now()]);
        $this->assertFalse(app(\App\Services\PilotService::class)->commissioningScope()['fiscalization']);
        $r=$this->getJson('/api/system-integrity')->assertOk()->json('operational_readiness.commissioning');
        $this->assertCount(11,$r['checks']);$this->assertFalse($r['ready_for_pilot']);$this->assertFalse($r['commercial_ready']);
        $this->assertSame('PENDING',collect($r['checks'])->firstWhere('key','pilot_authorization')['status']);
        $this->apiCompany->update(['accounting_start_date'=>today(),'accounting_opening_finalized_at'=>now()]);
        $this->getJson('/api/system-integrity')->assertOk()->assertJsonPath('operational_readiness.financial_opening.status','BLOCKED_SOURCE_REQUIRED');
        $this->assertDatabaseCount('journal_entries',0);
        $this->getJson('/api/commercial?company_id='.$this->apiCompany->id)->assertForbidden();
        $this->putJson('/api/pilot/plan',['revision'=>0,'status'=>'active','cutover_date'=>today()->toDateString(),'timezone'=>'UTC','business_days'=>[1,2,3,4,5],'workstreams'=>['inventory'],'participants'=>[['user_id'=>$owner->id,'responsibility'=>'owner','tasks'=>'Supervised synthetic review only']],'telemetry_enabled'=>false,'attest_real_data'=>false,'source_document_ids'=>[]])->assertUnprocessable()->assertJsonValidationErrors('status')->assertSee('commissioning checklist');
    }
}
