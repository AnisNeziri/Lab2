<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('warehouse_sections')
            ->whereIn('code', ['A1', 'A2', 'A3', 'A4', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'D1', 'D2', 'D3', 'D4'])
            ->where('name', 'like', 'Zone %Shelf%')
            ->orderBy('id')
            ->get()
            ->each(function ($section) {
                $name = 'Zone '.substr($section->code, 0, 1).' — Shelf '.$section->code;
                DB::table('warehouse_sections')->where('id', $section->id)->update(['name' => $name]);
                if ($section->warehouse_location_id) {
                    DB::table('warehouse_locations')->where('id', $section->warehouse_location_id)->update(['name' => $name]);
                }
            });
    }

    public function down(): void
    {
        // Data-only encoding repair; restoring corrupt text would be harmful.
    }
};
