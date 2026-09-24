<?php
namespace App\Services;
use App\Models\{OrderChannel,Product,Customer};
use App\Support\Money;
use Brick\Math\BigDecimal;

/** A channel discount adapter over AIMS product prices, not a sales ledger. */
class OrderPriceService {
    public function quote(Product $product,OrderChannel $channel,?Customer $customer,string $factor,string $quantity,string $date):array {
        $base=Money::multiply($product->selling_price??$product->price,$factor);
        $expected=$base;$applied=null;
        // Rules are explicitly ordered. Only the first applicable rule wins;
        // stacking discounts cannot silently reduce an agreed price twice.
        foreach($channel->configuration['price_rules']??[] as $rule){
            if(empty($rule['enabled']))continue;
            if(!empty($rule['product_id'])&&(int)$rule['product_id']!==$product->id)continue;
            if(!empty($rule['customer_id'])&&(int)$rule['customer_id']!==$customer?->id)continue;
            if(!empty($rule['starts_on'])&&$date<$rule['starts_on'])continue;
            if(!empty($rule['ends_on'])&&$date>$rule['ends_on'])continue;
            if(BigDecimal::of($quantity)->multipliedBy($factor)->isLessThan($rule['minimum_quantity']??1))continue;
            $discount=$rule['kind']==='percentage'?Money::multiply($base,BigDecimal::of($rule['value'])->dividedBy(100,4)) : Money::multiply($rule['value'],$factor);
            $expected=Money::compare($discount,$base)>0?'0.00':Money::subtract($base,$discount);$applied=$rule;break;
        }
        return ['base_price'=>$base,'expected_price'=>$expected,'rule'=>$applied];
    }
}
