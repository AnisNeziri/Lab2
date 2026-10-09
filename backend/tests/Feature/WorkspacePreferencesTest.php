<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspacePreferencesTest extends TestCase
{
    use RefreshDatabase;

    private function dashboard(): array
    {
        return ['version'=>1,'widgets'=>[
            ['id'=>'product-count','position'=>4,'size'=>'small','settings'=>[]],
            ['id'=>'sales-trend','position'=>0,'size'=>'large','settings'=>['period'=>'month']],
        ]];
    }

    private function navigation(): array
    {
        return ['version'=>1,'hidden'=>['dashboard','action-center','analytics'],
            'favorites'=>['products'],'order'=>['inventory'=>['stock','products']]];
    }

    public function test_workspace_defaults_are_owned_by_the_authenticated_user(): void
    {
        $this->actingAsApiUser('staff');
        $user=User::first();
        $this->getJson('/api/settings/workspace')->assertOk()
            ->assertJsonPath('user_id',$user->id)->assertJsonPath('company_id',$user->company_id)
            ->assertJsonPath('revision',0)->assertJsonPath('dashboard',null)->assertJsonPath('navigation',null);
    }

    public function test_dashboard_persists_and_canonicalizes_order_without_changing_normal_preferences(): void
    {
        $this->actingAsApiUser();
        $this->putJson('/api/settings/preferences',['theme'=>'dark','language'=>'sq'])->assertOk();
        $saved=$this->putJson('/api/settings/workspace',['revision'=>0,'dashboard'=>$this->dashboard()])
            ->assertOk()->assertJsonPath('revision',1)->assertJsonPath('version',1)
            ->assertJsonPath('dashboard.widgets.0.position',0)->assertJsonPath('dashboard.widgets.1.position',1)->json();
        $this->assertNotNull($saved['updated_at']);
        $this->getJson('/api/settings/workspace')->assertExactJson($saved);
        $preferences=User::first()->fresh()->preferences;
        $this->assertSame('dark',$preferences['theme']);
        $this->assertSame('sq',$preferences['language']);
        $this->assertSame($saved,$preferences['workspace']);
    }

    public function test_navigation_update_preserves_dashboard_and_protects_core_items(): void
    {
        $this->actingAsApiUser();
        $dashboard=$this->putJson('/api/settings/workspace',['revision'=>0,'dashboard'=>$this->dashboard()])->assertOk()->json('dashboard');
        $this->putJson('/api/settings/workspace',['revision'=>1,'navigation'=>$this->navigation()])->assertOk()
            ->assertJsonPath('dashboard',$dashboard)->assertJsonPath('revision',2)
            ->assertJsonPath('navigation.hidden',['analytics'])->assertJsonPath('navigation.favorites',['products']);
        $this->putJson('/api/settings/preferences',['theme'=>'dark'])->assertOk();
        $this->getJson('/api/settings/workspace')->assertJsonPath('dashboard',$dashboard)->assertJsonPath('navigation.hidden',['analytics']);
    }

    public function test_empty_layout_and_empty_navigation_can_be_saved(): void
    {
        $this->actingAsApiUser();
        $this->putJson('/api/settings/workspace',['revision'=>0,'dashboard'=>['version'=>1,'widgets'=>[]],
            'navigation'=>['version'=>1,'hidden'=>[],'favorites'=>[],'order'=>[]]])->assertOk()
            ->assertJsonPath('dashboard.widgets',[])->assertJsonPath('navigation.hidden',[]);
    }

    public function test_conflicting_device_save_does_not_overwrite_newer_layout(): void
    {
        $this->actingAsApiUser();
        $saved=$this->putJson('/api/settings/workspace',['revision'=>0,'dashboard'=>$this->dashboard()])->assertOk()->json();
        $this->putJson('/api/settings/workspace',['revision'=>0,'navigation'=>$this->navigation()])
            ->assertStatus(409)->assertJsonPath('code','WORKSPACE_CONFLICT');
        $this->getJson('/api/settings/workspace')->assertExactJson($saved);
    }

    public function test_invalid_layouts_are_rejected_without_mutation(): void
    {
        $this->actingAsApiUser();
        $variants=[];
        $duplicate=$this->dashboard();$duplicate['widgets'][]=$duplicate['widgets'][0];$variants[]=$duplicate;
        $invalidSize=$this->dashboard();$invalidSize['widgets'][0]['size']='pixel';$variants[]=$invalidSize;
        $freePosition=$this->dashboard();$freePosition['widgets'][0]['x']=100;$variants[]=$freePosition;
        $invalidSettings=$this->dashboard();$invalidSettings['widgets'][1]['settings']['period']='90';$variants[]=$invalidSettings;
        $unknownVersion=$this->dashboard();$unknownVersion['version']=99;$variants[]=$unknownVersion;
        foreach($variants as $dashboard) $this->putJson('/api/settings/workspace',['revision'=>0,'dashboard'=>$dashboard])->assertStatus(422);
        $this->putJson('/api/settings/workspace',['revision'=>0,'dashboard'=>['version'=>1]])->assertStatus(422);
        $this->getJson('/api/settings/workspace')->assertJsonPath('revision',0);
    }

    public function test_user_and_tenant_isolation_and_prohibited_targeting(): void
    {
        $this->actingAsApiUser();
        $owner=User::first();
        $saved=$this->putJson('/api/settings/workspace',['revision'=>0,'dashboard'=>$this->dashboard()])->assertOk()->json();
        foreach([$owner->company_id,Company::factory()->create()->id] as $company) {
            $plain='workspace-user-'.$company;
            $other=User::factory()->create(['company_id'=>$company,'role'=>'staff','api_token'=>hash('sha256',$plain)]);
            $this->withHeader('Authorization','Bearer '.$plain);
            $this->getJson('/api/settings/workspace')->assertOk()->assertJsonPath('user_id',$other->id)->assertJsonPath('dashboard',null);
            $this->putJson('/api/settings/workspace',['revision'=>0,'user_id'=>$owner->id,'company_id'=>$owner->company_id,'dashboard'=>$this->dashboard()])->assertStatus(422);
            $this->putJson('/api/settings/workspace',['revision'=>0,'navigation'=>$this->navigation()])->assertOk();
        }
        $this->assertSame($saved,$owner->fresh()->preferences['workspace']);
    }

    public function test_moving_user_to_another_company_discards_old_company_layout(): void
    {
        $this->actingAsApiUser();
        $this->putJson('/api/settings/workspace',['revision'=>0,'dashboard'=>$this->dashboard()])->assertOk();
        $user=User::first();$newCompany=Company::factory()->create();
        $user->update(['company_id'=>$newCompany->id]);
        $this->getJson('/api/settings/workspace')->assertOk()->assertJsonPath('company_id',$newCompany->id)->assertJsonPath('dashboard',null)->assertJsonPath('revision',0);
    }

    public function test_personalization_does_not_grant_business_permissions(): void
    {
        $this->actingAsApiUser('staff');
        $this->putJson('/api/settings/workspace',['revision'=>0,'dashboard'=>['version'=>1,'widgets'=>[
            ['id'=>'cash-outlook','position'=>0,'size'=>'medium','settings'=>[]],
        ]]])->assertOk();
        $this->getJson('/api/financial-intelligence')->assertForbidden();
    }
}
