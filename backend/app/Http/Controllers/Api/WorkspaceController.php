<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\UserPreferences;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkspaceController extends Controller
{
    private function document(User $user): array
    {
        $saved = $user->preferences['workspace'] ?? null;
        if (!is_array($saved) || ($saved['company_id'] ?? null) !== $user->company_id || ($saved['user_id'] ?? null) !== $user->id) {
            $saved = [];
        }
        return [...$saved, 'version' => 1, 'revision' => (int)($saved['revision'] ?? 0),
            'user_id' => $user->id, 'company_id' => $user->company_id,
            'dashboard' => $saved['dashboard'] ?? null, 'navigation' => $saved['navigation'] ?? null,
            'updated_at' => $saved['updated_at'] ?? null];
    }

    public function show(Request $request)
    {
        return response()->json($this->document($request->user()));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'revision' => 'required|integer|min:0', 'user_id' => 'prohibited', 'company_id' => 'prohibited',
            'dashboard' => 'sometimes|array:version,widgets', 'dashboard.version' => 'required_with:dashboard|integer|in:1',
            'dashboard.widgets' => 'present_with:dashboard|array|max:60',
            'dashboard.widgets.*' => 'array:id,position,size,settings',
            'dashboard.widgets.*.id' => 'required|string|max:60|distinct|regex:/^[a-z0-9-]+$/',
            'dashboard.widgets.*.position' => 'required|integer|min:0|max:59',
            'dashboard.widgets.*.size' => 'required|in:small,medium,large',
            'dashboard.widgets.*.settings' => 'present|array:period,supplier_id',
            'dashboard.widgets.*.settings.period' => 'sometimes|in:week,month,year',
            'dashboard.widgets.*.settings.supplier_id' => 'sometimes|nullable|regex:/^\d*$/|max:20',
            'navigation' => 'sometimes|array:version,hidden,favorites,order',
            'navigation.version' => 'required_with:navigation|integer|in:1',
            'navigation.hidden' => 'present_with:navigation|array|max:100', 'navigation.hidden.*' => 'string|max:80|distinct',
            'navigation.favorites' => 'present_with:navigation|array|max:6', 'navigation.favorites.*' => 'string|max:80|distinct',
            'navigation.order' => 'present_with:navigation|array|max:20',
            'navigation.order.*' => 'array|max:100', 'navigation.order.*.*' => 'string|max:80|distinct',
        ]);
        abort_unless(isset($data['dashboard']) || isset($data['navigation']), 422, 'Choose dashboard or navigation settings to save.');
        return DB::transaction(function () use ($request,$data) {
            $user=User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $current=$this->document($user);
            if ($current['revision'] !== (int)$data['revision']) {
                return response()->json(['code'=>'WORKSPACE_CONFLICT','message'=>'Your workspace was changed on another device. Reload saved settings before saving again.'],409);
            }
            foreach (['dashboard','navigation'] as $section) if(isset($data[$section])) $current[$section]=$data[$section];
            if (isset($data['dashboard'])) foreach ($current['dashboard']['widgets'] as $position=>&$widget) $widget['position']=$position;
            if (isset($data['navigation'])) $current['navigation']['hidden']=array_values(array_diff($current['navigation']['hidden'],['dashboard','action-center']));
            $current['revision']++;$current['updated_at']=now()->toIso8601String();
            $user->preferences=[...UserPreferences::normalize($user->preferences),'workspace'=>$current];$user->save();
            return response()->json($current);
        });
    }
}
