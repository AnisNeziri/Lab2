<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_lines', fn (Blueprint $t) => $t->text('private_snapshot')->nullable());
    }

    public function down(): void
    {
        throw new RuntimeException('Payroll identity snapshots must be preserved. Use a reviewed roll-forward correction.');
    }
};
