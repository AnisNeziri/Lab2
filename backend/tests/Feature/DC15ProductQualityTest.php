<?php
namespace Tests\Feature;

use App\Models\{Company,Invoice,TaxCode,User};
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth,DB};
use Tests\Concerns\CreatesDC3TaxCompany;
use Tests\TestCase;

class DC15ProductQualityTest extends TestCase
{
    use RefreshDatabase,CreatesDC3TaxCompany;

    public function test_print_and_pdf_share_issued_snapshot_and_preserve_vat_units_and_immutable_history(): void
    {
        $this->setupTax();$data=$this->invoiceData(TaxCode::where('code','KS18')->value('id'));
        $data['items'][0]=[...$data['items'][0],'description'=>'Textile <img src=x onerror=alert(1)>','unit'=>'m','quantity'=>'125.375','unit_price'=>'2.50'];
        $data['items'][]=['description'=>'Reduced-rate service','unit'=>'pcs','quantity'=>'2','unit_price'=>'50','tax_code_id'=>TaxCode::where('code','KS08')->value('id')];
        $draft=$this->postJson('/api/invoices',$data)->assertCreated()->json();$invoice=$this->postJson('/api/invoices/'.$draft['id'].'/issue')->assertOk()->json();$hash=$invoice['integrity_hash'];$journals=DB::table('journal_entries')->count();
        $print=$this->get('/api/invoices/'.$invoice['id'].'/print?locale=en')->assertOk()->assertHeader('Content-Type','text/html; charset=UTF-8')->assertSee('TAX INVOICE')->assertSee('125.375')->assertSee('DC3 Test Company')->assertSee('Business Buyer')->assertSee('Textile &lt;img',false)->assertDontSee('<img src=x',false)->assertSee('18%')->assertSee('8%');
        $this->assertStringContainsString('no-store',$print->headers->get('Cache-Control'));
        $pdf=$this->getJson('/api/invoices/'.$invoice['id'].'/pdf?locale=sq')->assertOk()->json('pdf');$this->assertStringStartsWith('%PDF-',base64_decode($pdf));
        $this->assertSame($hash,Invoice::find($invoice['id'])->integrity_hash);$this->assertSame($journals,DB::table('journal_entries')->count());
    }

    public function test_print_endpoint_checks_permissions_and_company_and_marks_drafts(): void
    {
        $this->setupTax();$id=$this->postJson('/api/invoices',$this->invoiceData(TaxCode::where('code','KS18')->value('id')))->assertCreated()->json('id');
        $this->get('/api/invoices/'.$id.'/print?locale=sq')->assertOk()->assertSee('DRAFT')->assertSee('FATURË TATIMORE');
        $foreign=Company::factory()->create();$foreignId=DB::table('invoices')->insertGetId(['company_id'=>$foreign->id,'document_type'=>'invoice','status'=>'draft','currency'=>'EUR','invoice_date'=>today(),'supply_date'=>today(),'customer_name'=>'PRIVATE BUYER','created_at'=>now(),'updated_at'=>now()]);
        $this->get('/api/invoices/'.$foreignId.'/print')->assertNotFound();$this->get('/api/invoices/'.$id.'/print?locale=invalid')->assertUnprocessable();
        $this->apiCompany->update(['role_permissions'=>['admin'=>['tasks.view']]]);$this->get('/api/invoices/'.$id.'/print')->assertForbidden();
    }

    public function test_bounded_read_scope_reuses_permissions_but_next_operation_sees_revocation_and_exception_cleans_up(): void
    {
        $this->actingAsApiUser();$this->getJson('/api/me')->assertOk();$s=app(PermissionService::class);$s->forRole('admin');
        DB::enableQueryLog();DB::flushQueryLog();$expected=$s->readScope(fn()=>$s->forRole('admin'));$single=count(DB::getQueryLog());DB::flushQueryLog();
        $s->readScope(function()use($s,$expected){for($n=0;$n<30;$n++)$this->assertSame($expected,$s->forRole('admin'));});
        $this->assertSame($single,count(DB::getQueryLog()));DB::disableQueryLog();
        try{$s->readScope(function()use($s){$s->forRole('admin');throw new \RuntimeException('read failed');});}catch(\RuntimeException){}
        $this->assertFalse(request()->attributes->has('aims.permission_read_scope'));
        $this->apiCompany->update(['role_permissions'=>['admin'=>['tasks.view']]]);$this->assertSame(['tasks.view'],$s->readScope(fn()=>$s->forRole('admin')));$this->assertFalse($s->roleHasPermission('admin','invoices.manage'));
    }

    public function test_permission_read_scope_never_reuses_another_user_company_or_integration_scope(): void
    {
        $this->actingAsApiUser();$this->getJson('/api/me')->assertOk();$s=app(PermissionService::class);$original=Auth::user();$other=Company::factory()->create(['role_permissions'=>['admin'=>['tasks.view']]]);$foreign=User::factory()->create(['company_id'=>$other->id,'role'=>'admin']);
        $s->readScope(function()use($s,$original,$foreign){$own=$s->forUser($original);$this->assertContains('invoices.manage',$own);$this->assertSame(['tasks.view'],$s->forUser($foreign));$this->assertSame($own,$s->forUser($original));$this->assertSame($original->id,Auth::id());});
        $integration=User::factory()->create(['company_id'=>$this->apiCompany->id,'role'=>'integration']);$integration->integration_permissions=['tasks.view'];
        $s->readScope(function()use($s,$integration){$this->assertSame(['tasks.view'],$s->forUser($integration));$integration->integration_permissions=['inventory.view'];$this->assertSame(['inventory.view'],$s->forUser($integration));});
        $this->assertFalse(request()->attributes->has('aims.permission_read_scope'));
    }
}
