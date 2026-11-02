<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('users','api_token_expires_at')) Schema::table('users',fn(Blueprint $t)=>$t->dateTime('api_token_expires_at')->nullable());
        // A bounded compatibility window, without changing any existing token or business field.
        DB::table('users')->whereNotNull('api_token')->whereNull('api_token_expires_at')->update(['api_token_expires_at'=>now()->addDays(30)]);
    }
    public function down(): void { throw new RuntimeException('Legacy token expiry is a security boundary. Preserve it during application rollback.'); }
};
