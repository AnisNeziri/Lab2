<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReleaseBaselineRecordsTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_compares_every_original_column_without_treating_new_columns_as_changed_business_records(): void
    {
        require_once dirname(__DIR__, 3).'/scripts/certification-common.php';
        $this->actingAsApiUser();
        $product = Product::create(['name' => 'Original business record', 'sku' => 'ORIGINAL-RELEASE', 'quantity' => 10, 'price' => 5]);
        $before = certificationCriticalRecords();
        Schema::table('products', fn (Blueprint $table) => $table->string('test_added_upgrade_column')->nullable());
        $this->assertSame($before, certificationCriticalRecords($before));
        $product->update(['quantity' => 9]);
        $this->assertNotSame($before['products']['sha256'], certificationCriticalRecords($before)['products']['sha256']);
        $this->assertNotSame($before, certificationCriticalRecords($before));
    }
}

