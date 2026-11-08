<?php
require __DIR__.'/bootstrap.php';
use Illuminate\Support\Facades\{DB,Auth};
Auth::setUser(App\Models\User::withoutGlobalScopes()->where('company_id',2)->where('role','admin')->firstOrFail());
if((int)DB::table('_dc16_checkpoints')->where('name','history')->value('completed_day')!==364)throw new RuntimeException('Year incomplete');
$dir=$dc16Root.'/storage/evidence/print';if(!is_dir($dir))mkdir($dir,0700,true);
$invoices=App\Models\Invoice::withCount('items')->where('status','issued')->get();
$samples=['normal'=>$invoices->where('items_count',6)->first(),'long'=>$invoices->sortByDesc('items_count')->first(),'one_line'=>$invoices->where('items_count',1)->first()];$results=[];
foreach($samples as $name=>$invoice){
 if(!$invoice){$results[$name]=['status'=>'NOT TESTED','reason'=>'No matching issued historical fixture'];continue;}
 $invoice=app(App\Services\InvoiceService::class)->find($invoice);
 foreach(['en','sq'] as $locale){
  $base=$dir.'/'.$name.'-'.$locale;
  $pdf=app(App\Services\InvoicePdfService::class)->render($invoice,$locale);
  $html=app(App\Services\InvoicePdfService::class)->html($invoice,$locale);
  file_put_contents($base.'.pdf',$pdf);file_put_contents($base.'.html',$html);
  $results[$name][$locale]=['invoice_id'=>$invoice->id,'number'=>$invoice->invoice_number,'lines'=>$invoice->items->count(),'pdf_bytes'=>strlen($pdf),'pdf_sha256'=>hash('sha256',$pdf),'issued_integrity'=>'verified by InvoiceService::find','status'=>'RENDERED — visual/page/totals review pending','total'=>$invoice->total_amount];
 }
}
dc16Evidence('print-artifacts',$results);echo "Existing issued documents rendered privately; visual review pending.\n";
