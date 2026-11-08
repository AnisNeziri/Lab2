<?php
require __DIR__.'/api.php';
use Illuminate\Support\Facades\{Auth,DB};
use Illuminate\Http\Request;
$evidence=$dc16Root.'/storage/evidence/export-checks.json';if(is_file($evidence)){echo "Existing export checks retained.\n";exit;}
$token=dc16Login('primary');$control=dc16Login('control');$results=[];
function dc16Binary(string $path,string $token):array{
 Auth::forgetGuards();$r=Request::create('/api/'.$path,'GET',[],[],[],['HTTP_ACCEPT'=>'application/json','HTTP_AUTHORIZATION'=>'Bearer '.$token]);$k=app(Illuminate\Contracts\Http\Kernel::class);$s=$k->handle($r);ob_start();$s->sendContent();$bytes=ob_get_clean();$k->terminate($r,$s);return ['status'=>$s->getStatusCode(),'type'=>$s->headers->get('Content-Type'),'bytes'=>$bytes];
}
foreach([2,8,66] as $id){
 foreach(['en','sq'] as $locale){
  $r=dc16Binary("invoices/$id/print?locale=$locale",$token);$html=$r['bytes'];unset($r['bytes']);
  $results["invoice-$id-$locale-html"]=$r+['pass'=>$r['status']===200&&str_contains($html,'</html>'),'bytes'=>strlen($html),'sha256'=>hash('sha256',$html)];
 }
 $r=dc16Request('GET',"invoices/$id/pdf",[],$token);$pdf=base64_decode($r['pdf']??'',true);$results["invoice-$id-pdf"]=['pass'=>$pdf&&str_starts_with($pdf,'%PDF-'),'bytes'=>strlen($pdf?:'')];
 $r=dc16Binary("invoices/$id/excel",$token);$path=$dc16Root.'/storage/evidence/print/export-'.$id.'.xlsx';file_put_contents($path,$r['bytes']);$zip=new ZipArchive();$valid=$zip->open($path)===true;$xml=$valid?$zip->getFromName('xl/worksheets/sheet1.xml'):false;if($valid)$zip->close();
 $results["invoice-$id-xlsx"]=['pass'=>$r['status']===200&&$xml!==false,'bytes'=>strlen($r['bytes']),'scope'=>'Valid XLSX worksheet container; formula/cell/layout review separate'];
}
$v=DB::table('document_versions')->where('company_id',2)->orderBy('id')->first();
if($v){
 $r=dc16Binary("documents/$v->document_id/versions/$v->version/file",$token);$actual=hash('sha256',$r['bytes']);
 $results['document-download']=['pass'=>$r['status']===200&&hash_equals($v->checksum,$actual),'document_id'=>$v->document_id,'version'=>$v->version,'actual_sha256'=>$actual,'expected_sha256'=>$v->checksum];
 $r=dc16Binary("documents/$v->document_id/versions/$v->version/file",$control);$results['cross-tenant-document']=['pass'=>in_array($r['status'],[403,404],true),'http'=>$r['status'],'bytes_leaked'=>hash_equals($v->checksum,hash('sha256',$r['bytes']))];
}
dc16Evidence('export-checks',['checks'=>$results,'browser_print'=>'NOT TESTED by this script','native_print'=>'NOT TESTED','tax_fixture'=>'Historical company is explicitly NON-VAT; no claim of all VAT treatments.']);echo count($results)." print/export/document checks saved.\n";
