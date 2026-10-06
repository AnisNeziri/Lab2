<?php
namespace App\Services;

use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;

/** Bounded data comparisons only. Never evaluates code, SQL or arbitrary paths. */
class AutomationConditionEngine
{
    public const OPERATORS = ['equals','not_equals','gt','gte','lt','lte','contains','not_contains','in','not_in','empty','not_empty','changed_from','changed_to'];

    public function validate(array $node, array $fields, int $depth = 0): void
    {
        if ($depth > 3) $this->invalid('Conditions support at most three nested groups.');
        if (isset($node['all']) || isset($node['any'])) {
            if (isset($node['all'], $node['any'])) $this->invalid('Choose AND or OR for each group.');
            $children = $node['all'] ?? $node['any'];
            if (!is_array($children) || count($children) > 12) $this->invalid('A group supports at most 12 conditions.');
            foreach ($children as $child) {
                if (!is_array($child)) $this->invalid('Invalid condition.');
                $this->validate($child, $fields, $depth + 1);
            }
            return;
        }
        if (!in_array($node['field'] ?? null, $fields, true) || !in_array($node['operator'] ?? null, self::OPERATORS, true)) $this->invalid('Select a supported field and operator.');
        if (strlen(json_encode($node['value'] ?? null)) > 2000) $this->invalid('Condition value is too long.');
        if (in_array($node['operator'], ['gt','gte','lt','lte'], true) && !is_numeric($node['value'] ?? null)) $this->invalid('Enter a number for this comparison.');
        if (in_array($node['operator'], ['in','not_in'], true) && !is_array($node['value'] ?? null)) $this->invalid('List comparisons require a list.');
    }

    public function evaluate(array $node, array $context): array
    {
        if (isset($node['all']) || isset($node['any'])) {
            $all = isset($node['all']);
            $results = array_map(fn($child) => $this->evaluate($child, $context), $node[$all ? 'all' : 'any']);
            return ['matched'=>$all ? !in_array(false,array_column($results,'matched'),true) : in_array(true,array_column($results,'matched'),true), 'group'=>$all?'AND':'OR','children'=>$results];
        }
        $field=$node['field']; $op=$node['operator']; $value=$node['value'] ?? null;
        $present=array_key_exists($field,$context); $actual=$context[$field] ?? null;
        $compare=is_numeric($actual) && is_numeric($value) ? BigDecimal::of((string)$actual)->compareTo((string)$value) : null;
        $equal=$compare !== null ? $compare===0 : $actual===$value;
        $matched=$present && match($op) {
            'equals'=>$equal, 'not_equals'=>!$equal,
            'gt'=>$compare!==null && $compare>0, 'gte'=>$compare!==null && $compare>=0,
            'lt'=>$compare!==null && $compare<0, 'lte'=>$compare!==null && $compare<=0,
            'contains'=>is_string($actual) && is_scalar($value) && str_contains(mb_strtolower($actual),mb_strtolower((string)$value)),
            'not_contains'=>is_string($actual) && is_scalar($value) && !str_contains(mb_strtolower($actual),mb_strtolower((string)$value)),
            'in'=>in_array($actual,$value,true), 'not_in'=>!in_array($actual,$value,true),
            'empty'=>$actual===null || $actual==='', 'not_empty'=>$actual!==null && $actual!=='',
            'changed_from'=>array_key_exists('previous.'.$field,$context) && $context['previous.'.$field]===$value && $actual!==$value,
            'changed_to'=>array_key_exists('previous.'.$field,$context) && $equal && $context['previous.'.$field]!==$actual,
            default=>false,
        };
        return ['field'=>$field,'operator'=>$op,'expected'=>$value,'actual'=>$actual,'available'=>$present,'matched'=>$matched];
    }
    private function invalid(string $message): never { throw ValidationException::withMessages(['conditions'=>[$message]]); }
}
