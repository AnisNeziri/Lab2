<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
use App\Services\WorkspaceSummaryPresentation as Summary;
final class WorkspaceSummaryPresentationTest extends TestCase {
 public function test_dashboard_finance_keeps_currency_and_unknown_values_without_detail_evidence(): void {
  $data=['state'=>'ready','stale'=>true,'evidence'=>['large'=>range(1,100)],'forecast'=>['currencies'=>['EUR'=>['expected_closing_cash'=>null,'timeline'=>range(1,50)],'USD'=>['expected_closing_cash'=>'100.25']]]];
  $result=Summary::finance($data);$this->assertNull($result['forecast']['currencies']['EUR']['expected_closing_cash']);$this->assertSame('100.25',$result['forecast']['currencies']['USD']['expected_closing_cash']);$this->assertArrayNotHasKey('evidence',$result);$this->assertArrayNotHasKey('timeline',$result['forecast']['currencies']['EUR']);
 }
 public function test_customer_summary_is_bounded_and_does_not_copy_profiles(): void {
  $rows=array_fill(0,10,['key'=>'test','customer'=>'Demo','product'=>'Fabric','kind'=>'reorder','raw'=>range(1,100)]);$result=Summary::customers(['state'=>'ready','evidence'=>['opportunities'=>$rows,'profiles'=>range(1,100)]]);
  $this->assertCount(4,$result['evidence']['opportunities']);$this->assertArrayNotHasKey('profiles',$result['evidence']);$this->assertArrayNotHasKey('raw',$result['evidence']['opportunities'][0]);
 }
}
