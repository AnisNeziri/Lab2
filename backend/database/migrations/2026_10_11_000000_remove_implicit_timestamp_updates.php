<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') return;
        // Older MariaDB silently treats the first nonnullable TIMESTAMP as a
        // mutable clock. No AIMS migration requests useCurrentOnUpdate(). Keep
        // every stored value and remove only those implicit schema side effects.
        // MariaDB 10.4 makes explicit_defaults_for_timestamp read-only. Use an
        // explicit DEFAULT instead: even its legacy mode then preserves values.
        $columns = DB::select("SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE = 'timestamp' AND LOWER(EXTRA) LIKE '%on update current_timestamp%'");
        foreach ($columns as $column) {
            foreach ([$column->TABLE_NAME, $column->COLUMN_NAME] as $name) if (! preg_match('/^[a-zA-Z0-9_]+$/D', $name)) throw new RuntimeException('Unexpected timestamp identifier. No automatic schema rewrite is safe.');
            if (! preg_match('/^timestamp(?:\([0-6]\))?$/iD', $column->COLUMN_TYPE)) throw new RuntimeException('Unexpected timestamp type.');
            $default = $column->COLUMN_DEFAULT;
            $clause = $column->IS_NULLABLE === 'YES' ? ' NULL' : ' NOT NULL';
            if ($default === null || strtoupper((string) $default) === 'NULL') {
                $clause .= $column->IS_NULLABLE === 'YES' ? ' DEFAULT NULL' : " DEFAULT '1970-01-02 00:00:00'";
            } elseif (preg_match('/^current_timestamp(?:\([0-6]?\))?$/iD', $default)) {
                $clause .= ' DEFAULT '.$default;
            } else {
                $clause .= ' DEFAULT '.DB::getPdo()->quote(trim($default, "'"));
            }
            DB::statement('ALTER TABLE `'.$column->TABLE_NAME.'` MODIFY `'.$column->COLUMN_NAME.'` '.$column->COLUMN_TYPE.$clause);
        }
    }

    public function down(): void
    {
        // Forward-only safety repair. Application rollback must retain this
        // correction or restore the verified pre-upgrade database snapshot.
    }
};
