<?php
namespace App\Services;

use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

/** Experimental visual baseline; product identity/stock remain owned by existing services. */
final class AssistantVisualSearch
{
    public function describe(string $base64,bool $embedding=true):array
    {
        abort_if(strlen($base64)>5400000,422,'Image exceeds 4 MB.');
        $learned=$embedding&&config('assistant.image_encoder')==='siglip2';
        $p=new Process([(string)($learned?config('assistant.encoder_python'):config('inventory_intelligence.python')),base_path($learned?'ml/assistant_siglip.py':'ml/assistant_image.py')],null,['AIMS_ASSISTANT_ENCODER_PATH'=>(string)config('assistant.encoder_path'),'HF_HUB_OFFLINE'=>'1']);
        $p->setTimeout($learned?20:5);$p->setInput(json_encode(['image'=>$base64,'screenshot'=>!$embedding]));
        try {$p->mustRun();$r=json_decode($p->getOutput(),true,512,JSON_THROW_ON_ERROR);}
        catch(\Throwable){abort(422,'Image processing unavailable or image invalid. Use a single JPEG, PNG or WebP under 4 MB and 8 megapixels.');}
        abort_unless(count($r['vector']??[])===($learned?768:193) && isset($r['preview']),422,'Invalid image.');return $r;
    }
    public function search(UploadedFile $image):array
    {
        abort_unless(auth()->user()?->company_id,403);
        abort_unless(app(CatalogReadAccess::class)->inventory(app(CatalogReadAccess::class)->permissions()),403);
        $query=$this->describe(base64_encode($image->get()));$ranked=[];$start=microtime(true);$seen=0;
        // Bounded candidates, lazily indexed; image content changes invalidate the cache.
        $rows=Product::whereNotNull('image_data')->whereRaw('LENGTH(image_data) <= ?',[5400000])->select(['id','name','sku','image_data'])->lazyById(1)->take(51);
        $visited=0;$truncated=false;
        foreach($rows as $p){
            if(++$visited>50||microtime(true)-$start>8){$truncated=true;break;}
            $key='assistant-visual-v2:'.config('assistant.image_encoder').':'.config('assistant.encoder_revision').':'.auth()->user()->company_id.':'.$p->id.':'.hash('sha256',$p->image_data);
            try {$d=Cache::store('file')->remember($key,60*60*24*30,fn()=>$this->describe($p->image_data));}catch(\Throwable){continue;}
            $seen++;$distance=0;$dot=0;foreach($d['vector'] as $i=>$v){$distance+=abs($v-$query['vector'][$i]);$dot+=$v*$query['vector'][$i];}
            $score=config('assistant.image_encoder')==='siglip2'?$dot:max(0,1-$distance/193);
            $ranked[]=['id'=>$p->id,'name'=>$p->name,'sku'=>$p->sku,'similarity'=>round($score*100,1),'image'=>'data:image/jpeg;base64,'.$d['preview'],'url'=>'/products/'.$p->id,'match'=>'possible_visual_match'];
        }
        usort($ranked,fn($a,$b)=>$b['similarity']<=>$a['similarity']);
        $ranked=array_slice($ranked,0,5);
        $runner=app(AssistantToolRunner::class);
        if($runner->allowed('get_product_availability'))foreach($ranked as &$candidate){
            $position=$runner->execute('get_product_availability',['product_id'=>$candidate['id']]);
            $candidate['stock']=['available'=>$position['position']['available']??null,'unit'=>$position['product']['unit']??null];
        }unset($candidate);
        $id=(string)\Illuminate\Support\Str::uuid();
        $learned=config('assistant.image_encoder')==='siglip2';
        app(AnalyticsDataService::class)->audit('assistant.query',['response_id'=>$id,'intent'=>'visual_search','provider'=>$learned?'local_siglip2':'local_colour_texture','model'=>$learned?'google/siglip2-base-patch16-224':'colour_texture_v1','model_revision'=>$learned?config('assistant.encoder_revision'):'dc18-v1','tools'=>[],'indexed'=>$seen,'duration_ms'=>(int)((microtime(true)-$start)*1000),'response_state'=>$seen?'partial':'insufficient']);
        return ['response_id'=>$id,'results'=>$ranked,'indexed'=>$seen,'catalogue_truncated'=>$truncated,'method'=>$learned?'siglip2-base-patch16-224':'experimental_colour_texture_v1','no_match_option'=>true,'exact_product_confirmed'=>false,'retained_upload'=>false,'qualification'=>$learned?'Learned visual similarity, not SKU identity or calibrated confidence. Choose none if no product matches. Real fabric thresholds remain uncalibrated.':'Colour/texture similarity only, not semantic recognition or confidence of SKU identity. Different fabrics may look identical. Verify product label and source record.'];
    }
}
