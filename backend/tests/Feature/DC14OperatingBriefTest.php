<?php
namespace Tests\Feature;

use App\Models\{BusinessEvent,Company,Customer};
use App\Services\{ActionCenterService,FinancialControlService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

class DC14OperatingBriefTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(bool $finance=true,bool $linkedTask=false): array
    {
        $this->actingAsApiUser();
        $permissions=['tasks.view','debts.view','customers.manage','daily_sales.manage'];
        if($finance)$permissions=[...$permissions,'accounting.reports.view','accounting.integrity.view'];
        $this->apiCompany->update(['role_permissions'=>['admin'=>$permissions]]);
        $tasks=$linkedTask?[['id'=>99,'source_type'=>'Customer','source_id'=>1,'title'=>'Existing collection','priority'=>'normal','outcome'=>['automation'=>['trigger'=>'customer.payment_overdue']]]]:[];
        $this->mock(ActionCenterService::class,fn($m)=>$m->shouldReceive('snapshot')->andReturn(['tasks'=>new LengthAwarePaginator($tasks,count($tasks),12),'attention'=>[
            ['kind'=>'customer','id'=>1,'title'=>'Overdue customer','description'=>'Customer payment overdue','url'=>'/customer-debts?customer=1','due_at'=>today()->subDay()->toDateString(),'values'=>['overdue'=>'120.00']]
        ]]));
        return ['opening_readiness'=>['status'=>'not_verified'],'currency'=>'EUR','summary'=>['cash'=>'0.00','bank'=>'0.00','net_profit'=>'0.00'],
            'controls_reconciled'=>false,'books_balanced'=>true,'integrity_status'=>'BLOCKED','attention'=>[], 'working_capital'=>['cash_bank'=>'0.00','total'=>'0.00'],
            'checked_at'=>now()->toIso8601String(),'reconciliation'=>['rows'=>[
                ['control'=>'accounts_receivable','operational_amount'=>'868.86','gl_amount'=>'0.00','difference'=>'868.86','reconciled'=>false],
                ['control'=>'supplier_advances','operational_amount'=>'445000.00','gl_amount'=>'0.00','difference'=>'445000.00','reconciled'=>false]]],
            'cash_outlook'=>['state'=>'ready','as_of'=>today()->toDateString(),'stale'=>false,'cash_complete'=>true,'expected_collections'=>'100.00','scheduled_outflows'=>'50.00','expected_closing_cash'=>'50.00']];
    }

    public function test_unverified_books_never_become_owner_cash_profit_or_a_purchase_forecast(): void
    {
        $f=$this->fixture(true,true);$this->mock(FinancialControlService::class,fn($m)=>$m->shouldReceive('summary')->once()->andReturn($f));
        $r=$this->getJson('/api/operating-brief?view=management&days=7')->assertOk()->assertJsonPath('read_only',true)
            ->assertJsonPath('books.values',null)->assertJsonPath('forecast.values',null)->assertJsonPath('forecast.state','blocked_opening_or_reconciliation')
            ->assertJsonPath('operations.rows.0.amount','868.86')->assertJsonPath('operations.rows.1.amount',null)
            ->assertJsonPath('attention.0.url','/customers?customer=1')->assertJsonCount(1,'attention')->assertJsonPath('attention.0.task_url','/action-center?task=99&status=all');
        $this->assertDatabaseCount('journal_entries',0);$this->assertDatabaseCount('operational_tasks',0);
        $this->assertSame('operational_activity',$r->json('sales.basis'));$this->assertSame('0.00',$r->json('sales.document_total'));
        $this->getJson('/api/operating-brief?view=management&days=32')->assertUnprocessable();
    }

    public function test_recorded_changes_check_both_event_and_parent_tenants_and_source_permissions(): void
    {
        $this->fixture(false);
        $c=Customer::create($this->tenantAttributes(['name'=>'Allowed customer','is_active'=>true]));
        $other=Company::factory()->create();$foreign=Customer::create(['company_id'=>$other->id,'name'=>'PRIVATE CUSTOMER','is_active'=>true]);
        foreach([[$this->apiCompany->id,$c->id],[$other->id,$foreign->id],[$this->apiCompany->id,$foreign->id]] as $i=>[$company,$id])
            BusinessEvent::create(['company_id'=>$company,'event_id'=>'dc14-'.$i,'event_type'=>'customer.payment_overdue','entity_type'=>'Customer','entity_id'=>$id,'reference'=>$company===$this->apiCompany->id&&$id===$c->id?'Visible reference':'PRIVATE REFERENCE','occurred_at'=>now(),'metadata'=>[],'idempotency_key'=>'dc14-'.$i]);
        $r=$this->getJson('/api/operating-brief?view=management&days=1')->assertOk()->assertJsonPath('books',null)->assertJsonPath('payroll',null)->assertJsonPath('readiness',null)->assertJsonCount(1,'changes.rows')->assertJsonPath('changes.rows.0.label_sq','Borxhi i vonuar i klientit');
        $this->assertStringNotContainsString('PRIVATE',$r->getContent());
        $this->apiCompany->update(['role_permissions'=>['admin'=>['tasks.view']]]);
        $this->getJson('/api/operating-brief?view=management&team=management')->assertForbidden();
        $this->getJson('/api/operating-brief?view=management')->assertOk()->assertJsonCount(0,'changes.rows');
    }

    public function test_only_complete_fresh_qualified_forecasts_are_exposed_and_sources_can_fail_independently(): void
    {
        $f=$this->fixture();$f['opening_readiness']['status']='verified';$f['controls_reconciled']=true;$f['integrity_status']='READY';$f['cash_outlook']['stale']=true;
        $this->mock(FinancialControlService::class,fn($m)=>$m->shouldReceive('summary')->andReturn($f));
        $this->getJson('/api/operating-brief?view=management')->assertOk()->assertJsonPath('books.values.net_profit','0.00')->assertJsonPath('forecast.state','stale')->assertJsonPath('forecast.values',null);
        $f['cash_outlook']['stale']=false;
        $this->mock(FinancialControlService::class,fn($m)=>$m->shouldReceive('summary')->andReturn($f));
        $this->getJson('/api/operating-brief?view=management')->assertOk()->assertJsonPath('forecast.state','qualified')->assertJsonPath('forecast.values.expected_closing_cash','50.00');
        $this->mock(FinancialControlService::class,fn($m)=>$m->shouldReceive('summary')->andThrow(new \RuntimeException('Isolated unavailable source')));
        $this->getJson('/api/operating-brief?view=management')->assertOk()->assertJsonPath('books',null)->assertJsonPath('unavailable.0','finance');
    }

    public function test_executive_copilot_uses_the_existing_typed_read_tool_and_never_executes_a_write(): void
    {
        $f=$this->fixture();$this->mock(FinancialControlService::class,fn($m)=>$m->shouldReceive('summary')->andReturn($f));
        // Establish the same authenticated company context as the normal assistant route.
        $this->getJson('/api/operating-brief?view=management')->assertOk();
        foreach(['What should I focus on today?','Qka duhet me kontrollu sot?','What changed this week?','Can we afford the recommended purchase?'] as $q){
            $r=app(\App\Services\OperatingAssistantFacts::class)->reply(['question'=>$q,'language'=>str_starts_with($q,'Qka')?'sq':'en'],'dc14');
            $this->assertTrue($r['read_only']);$this->assertSame('get_operating_brief',$r['sources'][0]['tool']);
        }
        $this->assertDatabaseCount('operational_tasks',0);$this->assertDatabaseCount('journal_entries',0);
    }
}
