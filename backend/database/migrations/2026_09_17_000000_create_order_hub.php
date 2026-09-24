<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('order_channels', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained(); $t->string('name',120); $t->string('type',30);
            $t->boolean('enabled')->default(true); $t->string('external_reference',150)->nullable();
            $t->foreignId('warehouse_id')->nullable()->constrained(); $t->string('currency',3);
            $t->boolean('allow_guest')->default(false); $t->string('acceptance',30)->default('review');
            $t->string('oversale_policy',30)->default('require_review'); $t->decimal('price_tolerance',18,2)->default(0);
            $t->json('configuration')->nullable(); $t->foreignId('integration_provider_id')->nullable()->constrained();
            $t->timestamps(); $t->unique(['company_id','name']);
        });
        Schema::create('order_channel_mappings', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained(); $t->foreignId('order_channel_id')->constrained();
            $t->string('kind',20); $t->string('external_id',150); $t->foreignId('product_id')->nullable()->constrained();
            $t->foreignId('customer_id')->nullable()->constrained(); $t->timestamps();
            $t->unique(['order_channel_id','kind','external_id'],'channel_mapping_identity');
        });
        // Credentials are deliberately excluded from portable company backups.
        Schema::create('order_channel_keys', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained(); $t->foreignId('order_channel_id')->constrained();
            $t->foreignId('user_id')->constrained(); $t->foreignId('customer_id')->nullable()->constrained();
            $t->string('token_hash',64)->unique(); $t->text('webhook_secret'); $t->json('scopes');
            $t->timestamp('expires_at')->nullable(); $t->timestamp('revoked_at')->nullable(); $t->timestamp('last_used_at')->nullable(); $t->timestamps();
        });
        Schema::create('order_intakes', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained(); $t->foreignId('order_channel_id')->constrained();
            $t->foreignId('sales_order_id')->nullable()->unique()->constrained(); $t->string('external_id',150)->nullable();
            $t->string('idempotency_key',100); $t->string('fingerprint',64); $t->string('state',30)->default('received');
            $t->json('payload'); $t->json('issues')->nullable(); $t->json('resolution')->nullable();
            $t->string('tracking_hash',64)->nullable()->unique(); $t->timestamp('tracking_expires_at')->nullable();
            $t->unsignedInteger('attempts')->default(0); $t->timestamp('next_retry_at')->nullable();
            $t->timestamp('last_success_at')->nullable(); $t->string('last_error',1000)->nullable(); $t->timestamps();
            $t->unique(['order_channel_id','external_id'],'intake_external_identity');
            $t->unique(['order_channel_id','idempotency_key'],'intake_request_identity');
            $t->index(['company_id','state','created_at'],'intake_inbox');
        });
        Schema::create('order_hub_presets', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained(); $t->foreignId('user_id')->constrained();
            $t->foreignId('order_intake_id')->nullable()->constrained();
            $t->string('name',120); $t->string('kind',20); $t->json('payload'); $t->timestamps();
        });
        Schema::table('sales_orders', function (Blueprint $t) { $t->unsignedBigInteger('customer_id')->nullable()->change(); });
        // Expose existing manual orders without changing quantities, prices,
        // financial postings, or their original fulfillment identity.
        \Illuminate\Support\Facades\DB::table('sales_orders')->orderBy('id')->chunkById(100,function($orders){
            foreach($orders as $o){
                $db=\Illuminate\Support\Facades\DB::class;
                $channel=$db::table('order_channels')->where('company_id',$o->company_id)->where('name','Manual')->value('id');
                if(!$channel)$channel=$db::table('order_channels')->insertGetId(['company_id'=>$o->company_id,'name'=>'Manual','type'=>'manual','currency'=>$o->currency,'oversale_policy'=>'accept_backorder','created_at'=>now(),'updated_at'=>now()]);
                $payload=['customer_id'=>$o->customer_id,'order_date'=>$o->order_date,'requested_delivery_date'=>$o->requested_delivery_date,'payment_type'=>$o->payment_type,'currency'=>$o->currency,'priority'=>$o->priority,'idempotency_key'=>'manual-'.$o->id,'items'=>$db::table('sales_order_items')->where('sales_order_id',$o->id)->get(['product_id','quantity','unit','unit_price'])->map(fn($x)=>(array)$x)->all()];
                $db::table('order_intakes')->insert(['company_id'=>$o->company_id,'order_channel_id'=>$channel,'sales_order_id'=>$o->id,'external_id'=>$o->order_number,'idempotency_key'=>'manual-'.$o->id,'fingerprint'=>hash('sha256',json_encode($payload)),'payload'=>json_encode($payload),'state'=>$o->confirmed_at?'accepted':'validated','created_at'=>$o->created_at,'updated_at'=>$o->updated_at,'last_success_at'=>now()]);
            }
        });
    }
    public function down(): void {
        foreach(['order_hub_presets','order_intakes','order_channel_keys','order_channel_mappings','order_channels'] as $table) Schema::dropIfExists($table);
        // Retain nullable customer_id: rolling back must not erase guest orders.
    }
};
