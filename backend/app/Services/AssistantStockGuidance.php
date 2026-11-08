<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Exact named products in availability requests. Fuzzy matching remains in the existing resolver. */
final class AssistantStockGuidance
{
    public function reply(array $input, string $conversation): ?array
    {
        $question = $input['question'];
        $q = app(AssistantLanguage::class)->normalize($question);
        $plan = app(AssistantPlanner::class)->plan($question);
        $replenishment = $plan['intent'] === 'replenishment' || (bool) preg_match('/\b(?:should|duhet)\b.*\b(?:reorder|riporos\w*|porosit\w* prape)\b/', $q);
        if (isset($input['simulation_id']) || isset($input['entity']) || ! empty($plan['warehouse_term'])
            || in_array($plan['intent'], ['unsupported', 'prepare', 'scenario', 'movements', 'stockout'], true)
            || ! $replenishment && ! preg_match('/\b(?:stock|available|availability|free|remaining|promise|fetch|sell|sold|metra|meters|metres|gjind\w*|gjend\w*|ku eshte)\b/', $q)
            || preg_match('/\b(?:why|pse|forecast|parashik\w*|what if|nese|only|vetem|where did|how much did)\b/', $q)
            || preg_match('/\b(?:in|at|ne)\s+(?!stock\b|dispozicion\b|the catalog\b)\p{L}/u', $q)) {
            return null;
        }
        $runner = app(AssistantToolRunner::class);
        if (! $runner->allowed('get_product_availability')) {
            return null;
        }
        // Exact contiguous phrases from the user's text. Do not guess a catalog record from a common prefix.
        preg_match_all('/[\pL\pN-]+/u', mb_strtolower($question), $tokens);
        $words = array_slice($tokens[0], 0, 40);
        $names = [];
        foreach (array_keys($words) as $offset) {
            for ($length = 1; $length <= min(5, count($words) - $offset); $length++) {
                $names[] = implode(' ', array_slice($words, $offset, $length));
            }
        }
        $products = Product::query()->whereIn(DB::raw('LOWER(name)'), array_unique($names))->orderBy('id')->limit(3)->get(['id', 'name', 'unit']);
        if ($products->isEmpty() && preg_match('/["“]([^"”]+)["”]/u', $question, $quoted)) {
            $resolved = app(AssistantEntityResolver::class)->resolve($quoted[1], 'product');
            if ($resolved['entity']) {
                $products = Product::query()->whereKey($resolved['entity']['id'])->get(['id', 'name', 'unit']);
            } elseif ($resolved['choices']) {
                return ['id' => (string) Str::uuid(), 'conversation_id' => $conversation, 'intent' => 'record',
                    'text' => ($input['language'] ?? 'en') === 'sq' ? 'Cilin produkt nënkupton? Zgjidh nga lista.' : 'Which product do you mean? Choose from the list.',
                    'choices' => $resolved['choices'], 'cards' => [], 'sources' => [], 'tools' => [],
                    'read_only' => true, 'provider' => 'deterministic', 'state' => 'needs_clarification'];
            }
        }
        if ($products->isEmpty() || $products->count() > 2 || $products->pluck('name')->map(fn ($name) => mb_strtolower($name))->unique()->count() !== $products->count()) {
            return null;
        }
        if ($products->count() === 1 && preg_match('/\b(?:compare|krahas\w*|versus|vs)\b/', $q)) {
            return null;
        }

        $sq = ($input['language'] ?? 'en') === 'sq';
        if ($replenishment && $products->count() === 1) {
            return $this->replenishment($products->first(), $runner, $conversation, $sq);
        }
        $facts = $products->map(fn ($product) => $runner->execute('get_product_availability', ['product_id' => $product->id]))->all();
        $entities = array_map(fn ($data) => ['type' => 'product', 'id' => $data['product']['id'], 'title' => $data['product']['name']], $facts);
        $reply = app(AssistantComposer::class)->compose(array_map(fn ($data) => ['tool' => 'get_product_availability', 'data' => $data], $facts), $sq);
        $text = implode('; ', array_map(fn ($data) => $data['product']['name'].': '.$data['position']['available'].' '.$data['product']['unit'], $facts))
            .($sq ? ' në dispozicion.' : ' available.');
        $first = $facts[0];
        if (count($facts) === 1 && preg_match('/\b(?:where|fetch|ku|gjind\w*|gjend\w*)\b/', $q)) {
            $positions = array_values(array_filter($first['warehouses'], fn ($row) => $row['available'] > 0 && $row['warehouse']));
            $reply['cards'] = array_map(fn ($row) => [
                'tool' => 'get_product_availability',
                'title' => $row['warehouse']['name'],
                'summary' => $row['location']['path'] ?? $row['location']['name'] ?? ($sq ? 'Nuk ka raft të regjistruar.' : 'No bin is recorded.'),
                'metrics' => [['label' => $sq ? 'Në dispozicion' : 'Available', 'value' => $row['available'], 'unit' => $first['product']['unit']]],
                'url' => $first['url'],
            ], array_slice($positions, 0, 8));
            $text .= $positions
                ? ' '.($sq ? 'Shiko depot dhe raftet e regjistruara më poshtë.' : 'See the recorded warehouses and bins below.')
                : ' '.($sq ? 'Burimi nuk tregon një lokacion me stok të lirë.' : 'The source shows no location with free stock.');
        }
        if (count($facts) === 1 && preg_match('/\b(\d+(?:[.,]\d+)?)\s*(metra|meters|metres|m)\b/', $q, $quantity) && $first['product']['unit'] === 'm') {
            $amount = (float) str_replace(',', '.', $quantity[1]);
            $reply['meaning'] = $amount <= (float) $first['position']['available']
                ? ($sq ? "Stoku aktual mbulon $amount m. Kontrollo porosinë para se ta premtosh." : "Current stock covers $amount m. Review the order before making a promise.")
                : ($sq ? "Stoku aktual nuk mbulon $amount m. Kontrollo porosinë dhe furnizimin." : "Current stock falls short of $amount m. Review the order and incoming supply.");
            $text .= ' '.$reply['meaning'];
        }
        return $reply + [
            'id' => (string) Str::uuid(), 'conversation_id' => $conversation, 'intent' => 'product_position',
            'text' => $text, 'context' => count($entities) === 1 ? $entities[0] : null, 'context_entities' => $entities,
            'next_action' => ['url' => '/products?product='.$entities[0]['id'], 'label' => $sq ? 'Hap produktin' : 'Open product'],
            'choices' => [], 'read_only' => true, 'provider' => 'deterministic', 'state' => 'success', 'tools' => $runner->executions,
        ];
    }

    private function replenishment(Product $product, AssistantToolRunner $runner, string $conversation, bool $sq): ?array
    {
        if (! $runner->allowed('get_inventory_plan')) {
            $data = $runner->execute('get_product_availability', ['product_id' => $product->id]);
            $url = '/products?product='.$product->id;
            $text = $product->name.': '.$data['position']['available'].' '.$product->unit.($sq
                ? ' në dispozicion. Kjo llogari nuk sheh planin e rifurnizimit.'
                : ' available. This account cannot see the replenishment plan.');
            return ['id' => (string) Str::uuid(), 'conversation_id' => $conversation, 'intent' => 'replenishment',
                'text' => $text, 'cards' => [], 'sources' => [['tool' => 'get_product_availability', 'url' => $url]],
                'next_action' => ['url' => $url, 'label' => $sq ? 'Shqyrto produktin' : 'Review product'],
                'choices' => [], 'read_only' => true, 'provider' => 'deterministic', 'state' => 'insufficient',
                'tools' => $runner->executions, 'context' => ['type' => 'product', 'id' => $product->id, 'title' => $product->name]];
        }
        $data = $runner->execute('get_inventory_plan', ['product_id' => $product->id]);
        $plan = $data['plan'];
        $quantity = ($plan['coverage_supported'] ?? false) && ($plan['feasible'] ?? false) ? $plan['base_quantity'] : null;
        $url = '/inventory-intelligence?view=planning&product='.$product->id;
        $text = $product->name.' · '.($quantity === null
            ? ($sq ? 'Nuk ka sasi të mbështetur për riporosi. Kontrollo kërkesën dhe afatin e furnitorit në planifikim.' : 'A reorder quantity is not supported. Check demand and supplier lead time in Planning.')
            : ($sq ? 'Sasia për shqyrtim: ' : 'Quantity for review: ').$quantity.' '.$product->unit.'.');
        return ['id' => (string) Str::uuid(), 'conversation_id' => $conversation, 'intent' => 'replenishment',
            'text' => $text, 'cards' => [], 'sources' => [['tool' => 'get_inventory_plan', 'url' => $url]],
            'next_action' => ['url' => $url, 'label' => $sq ? 'Hap planifikimin' : 'Open Planning'],
            'choices' => [], 'read_only' => true, 'provider' => 'deterministic', 'state' => $quantity === null ? 'insufficient' : 'success',
            'tools' => $runner->executions, 'context' => ['type' => 'product', 'id' => $product->id, 'title' => $product->name]];
    }
}
