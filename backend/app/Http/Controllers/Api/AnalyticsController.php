<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Services\{AnalyticsService,AnalyticsDataService,AnalyticsFeatureRegistry};
use App\Models\{AnalyticsDataset,AnalyticsDatasetRow,AnalyticsSnapshot};
use Illuminate\Http\Request;
class AnalyticsController extends Controller {
    public function catalog(AnalyticsService $s){$areas=[];foreach(AnalyticsService::AREAS as $a=>$p){try{$s->authorize($a);$areas[]=$a;}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){}}
        return ['areas'=>$areas,'feature_version'=>AnalyticsFeatureRegistry::VERSION,'definitions'=>[]];}
    public function show(Request $r,AnalyticsService $s,string $area){$data=$s->workspace($area,$r->query());$data['total_rows']=count($data['rows']??[]);$page=max(1,(int)$r->query('page',1));$data['rows']=array_slice($data['rows']??[],($page-1)*50,50);$data['page']=$page;return $data;}
    public function capture(AnalyticsDataService $s){return $s->capture();}
    public function datasets(AnalyticsDataService $s){$s->authorizeDatasets();return AnalyticsDataset::latest()->paginate(25);}
    public function build(Request $r,AnalyticsDataService $s){return response()->json($s->build($r->only('from','to')),201);}
    public function features(Request $r,AnalyticsDataService $s){$s->authorizeDatasets();$d=$r->validate(['entity_type'=>'required|in:product,customer,supplier','entity_id'=>'required|integer','date'=>'required|date_format:Y-m-d']);$snapshot=AnalyticsSnapshot::where('entity_type',$d['entity_type'])->where('entity_id',$d['entity_id'])->whereDate('snapshot_date',$d['date'])->where('warehouse_id',0)->firstOrFail();return ['snapshot'=>$snapshot,'features'=>AnalyticsFeatureRegistry::features($d['entity_type'],$snapshot->facts),'definitions'=>AnalyticsFeatureRegistry::definitions()];}
    public function export(Request $r,AnalyticsDataset $dataset,AnalyticsDataService $s){
        abort_unless((int)$dataset->company_id===(int)$r->user()->company_id,404);
        $s->authorizeDatasets();abort_unless(app(AnalyticsService::class)->can('analytics.export'),403);$format=$r->query('format','csv');abort_unless(in_array($format,['csv','json']),422);
        $s->audit('analytics.dataset.exported',['dataset_id'=>$dataset->id,'format'=>$format]);
        return response()->streamDownload(function()use($dataset,$format){$rows=AnalyticsDatasetRow::where('analytics_dataset_id',$dataset->id)->orderBy('id')->cursor();$out=fopen('php://output','w');$first=true;
            if($format==='json')fwrite($out,'{"manifest":'.json_encode($dataset).',"rows":[');
            foreach($rows as $row){$values=$row->values;if($format==='json'){fwrite($out,($first?'':',').json_encode($values));}else{if($first)fputcsv($out,array_keys($values));fputcsv($out,array_map(fn($v)=>is_array($v)?json_encode($v):(is_string($v)&&!is_numeric($v)&&preg_match('/^[=+@\-\t\r]/',$v)?"'".$v:$v),$values));}$first=false;}
            if($format==='json')fwrite($out,']}');fclose($out);
        },$dataset->name.'-'.$dataset->version.'.'.$format,['Content-Type'=>$format==='csv'?'text/csv; charset=UTF-8':'application/json']);
    }
}
