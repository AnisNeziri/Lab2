<?php
namespace Tests\Feature;

use App\Services\{AssistantLanguage, AssistantPlanner};
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ProductionClockTest extends TestCase
{
    public function test_company_calendar_boundaries_include_midnight_month_year_and_dst(): void
    {
        try {
            foreach ([
                ['2026-12-31T23:30:00Z','today','2027-01-01','2027-01-01'],
                ['2026-01-31T23:30:00Z','this month','2026-02-01','2026-02-28'],
                ['2026-03-29T00:30:00Z','today','2026-03-29','2026-03-29'],
                ['2026-03-29T01:30:00Z','today','2026-03-29','2026-03-29'],
                ['2026-10-25T00:30:00Z','today','2026-10-25','2026-10-25'],
                ['2026-10-25T01:30:00Z','today','2026-10-25','2026-10-25'],
            ] as [$instant,$question,$from,$to]) {
                CarbonImmutable::setTestNow(CarbonImmutable::parse($instant));
                $period=app(AssistantPlanner::class)->period($question,'Europe/Budapest');
                $this->assertSame($from,$period['from']);$this->assertSame($to,$period['to']);
            }
        } finally { CarbonImmutable::setTestNow(); }
    }

    public function test_follow_up_quantity_keeps_context_instead_of_resolving_an_entity_name(): void
    {
        foreach(['What if I buy only 20 pcs?','What if only 20 m?'] as $question) {
            $this->assertNull(app(AssistantLanguage::class)->entityTerm($question));
            $plan=app(AssistantPlanner::class)->plan($question,['entity'=>['type'=>'product','id'=>1]]);
            $this->assertSame('scenario',$plan['intent']);
            $this->assertSame(20.0,$plan['scenario']['base_quantity']);
        }
    }

    public function test_company_dates_follow_explicit_installation_timezone_independently_of_server_utc(): void
    {
        config(['app.timezone'=>'UTC','production.timezone'=>'Asia/Tokyo']);
        try {
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-31T23:30:00Z'));
            $this->assertSame('2027-01-01',\App\Support\CompanyClock::today()->toDateString());
            $this->assertSame('Asia/Tokyo',\App\Support\CompanyClock::timezone());
        } finally { CarbonImmutable::setTestNow(); }
    }
}
