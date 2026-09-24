<?php
namespace Tests\Feature;
use App\Models\{AccountingPeriod,JournalEntry,User};
use App\Services\AccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class AccountingCoreTest extends TestCase
{
 use RefreshDatabase;
 private function context():AccountingService{$this->actingAsApiUser('admin');Auth::setUser(User::query()->where('company_id',$this->apiCompany->id)->firstOrFail());$s=app(AccountingService::class);$s->initialize();return $s;}
 public function test_balanced_journal_posts_is_immutable_and_reverses():void{$s=$this->context();$a=collect($s->accounts())->keyBy('code');$e=$s->createDraft(['posting_date'=>'2026-09-01','description'=>'Opening cash','source_module'=>'opening','source_key'=>'cash-1','lines'=>[['accounting_account_id'=>$a['1000']->id,'debit'=>100,'credit'=>0],['accounting_account_id'=>$a['3000']->id,'debit'=>0,'credit'=>100]]]);$this->assertSame('posted',$s->post($e)->status);try{$e->fresh()->update(['description'=>'silent rewrite']);$this->fail('Posted journal changed.');}catch(LogicException){}$reverse=$s->reverse($e->fresh(),'2026-09-02','Opening entered incorrectly.');$this->assertSame('posted',$reverse->status);$this->assertSame('reversed',$e->fresh()->status);$this->assertSame('0.00',$s->trialBalance(null,'2026-09-02')['rows']->firstWhere('code','1000')['closing_balance']);}
 public function test_unbalanced_and_locked_period_posting_are_blocked():void{$s=$this->context();$a=collect($s->accounts())->keyBy('code');$e=$s->createDraft(['posting_date'=>'2026-09-03','description'=>'Bad draft','lines'=>[['accounting_account_id'=>$a['1000']->id,'debit'=>10],['accounting_account_id'=>$a['3000']->id,'credit'=>9]]]);$this->expectException(ValidationException::class);$s->post($e);}
 public function test_locked_period_duplicate_source_and_financial_statements():void{$s=$this->context();$a=collect($s->accounts())->keyBy('code');$data=['posting_date'=>'2026-09-04','description'=>'Cash sale','source_module'=>'sales','source_key'=>'sale:7','lines'=>[['accounting_account_id'=>$a['1000']->id,'debit'=>150],['accounting_account_id'=>$a['4000']->id,'credit'=>150]]];$first=$s->createAndPost($data);$this->assertSame($first->id,$s->createAndPost($data)->id);$pl=$s->profitAndLoss('2026-09-01','2026-09-30');$this->assertSame('150.00',$pl['revenue']);$this->assertSame('150.00',$pl['net_profit']);$this->assertTrue($s->trialBalance('2026-09-01','2026-09-30')['balanced']);$this->assertTrue($s->balanceSheet('2026-09-30')['balanced']);$period=$s->savePeriod(['name'=>'September 2026','starts_at'=>'2026-09-01','ends_at'=>'2026-09-30']);$s->setPeriodStatus($period,'soft_closed','Month-end review in progress.');$draft=$s->createDraft([...$data,'source_key'=>'sale:8']);$this->expectException(ValidationException::class);$s->post($draft);}
 public function test_company_scope_keeps_ledgers_isolated():void{$s=$this->context();$this->assertGreaterThan(0,count($s->accounts()));$other=$this->apiCompany->replicate();$other->name='Other ledger tenant';$other->save();Auth::setUser(User::factory()->create(['company_id'=>$other->id,'role'=>'admin']));$this->assertCount(0,app(AccountingService::class)->accounts());$this->assertSame(0,JournalEntry::query()->count());}
}
