<?php
namespace App\Http\Middleware;

use App\Services\CatalogReadAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

final class AuthorizeCatalogRead
{
    public function handle(Request $request, Closure $next, string $mode = 'catalog')
    {
        $access=app(CatalogReadAccess::class);$permissions=$access->permissions();
        $reference=$mode==='catalog' && $request->boolean('reference_only');
        abort_unless($mode==='suppliers' ? $access->suppliers($permissions) : ($reference ? $access->reference($permissions) : $access->inventory($permissions)),403,'You do not have permission to read this catalog.');
        // The existing full export contains acquisition prices and supplier terms.
        if($mode==='export')abort_unless(in_array('export.execute',$permissions,true)&&$access->costs($permissions),403);
        $response=$next($request);
        if($reference && $response instanceof JsonResponse && $response->isSuccessful()){
            $data=$response->getData(true);
            if(isset($data['data']))$data['data']=array_map($access->referenceProduct(...),$data['data']);
            elseif(isset($data['product']))$data=['product'=>$access->referenceProduct($data['product'])];
            else $data=$access->referenceProduct($data);
            $response->setData($data);
        }
        return $response;
    }
}
