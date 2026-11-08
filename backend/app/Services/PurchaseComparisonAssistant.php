<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class PurchaseComparisonAssistant
{
    public function answer(array $input, string $conversation, array $context): ?array
    {
        if (isset($input['simulation_id']) || isset($input['customer_id']) || isset($input['entity']) && $input['entity']['type'] !== 'product') return null;
        $q = app(AssistantLanguage::class)->normalize($input['question']);
        $prior = ($context['last_intent'] ?? '') === 'purchase_comparison' ? ($context['purchase_comparison'] ?? []) : [];
        $page = $input['purchase_context'] ?? [];
        if (!$prior && ($context['last_intent'] ?? '') !== 'purchase_portfolio') $prior = $page;
        $purchase = preg_match('/\b(?:buy|purchase|purchasing|procurement|order|ble|blej\w*|blerj\w*|marr|porosit\w*|mall\w*)\b/', $q);
        $supplier = preg_match('/\b(?:suppliers?|vendors?|furnitor\w*)\b/', $q);
        $budget = preg_match('/\b(?:budget\w*|buxhet\w*)\b/', $q);
        $quoteQuestion = $supplier && !preg_match('/\b(?:payables?|bills?|invoices?|fatur\w*)\b/', $q)
            && (preg_match('/\b(?:quot\w*|offer\w*|ofert\w*)\b/', $q)
                || $prior && preg_match('/\b(?:histor\w*|previous\w*|earlier|ma heret|me heret|furnizu\w*)\b/', $q));
        $assumptions = app(PurchaseAssumptionInput::class);
        $assumedPrice = $assumptions->unitPrice($input['question']);
        $comparison = $quoteQuestion || preg_match('/\b(?:compare|choose|best|cheapest|reliab\w*|fastest|provide|ofroj\w*|krahas\w*|rekomand\w*|mir[ei]?|lir[ei]|shpejt\w*|zgjedh\w*)\b/', $q);
        $priceFollowup = $prior && preg_match('/\b(?:price|cmim\w*|afford|safe to spend|buxhet\w*)\b/', $q)
            && !preg_match('/\b(?:customer|invoices?|fatur\w*|payroll|pagat|sell|shit\w*)\b/', $q);
        $nextStep = $prior && preg_match('/^(?:what (?:should|do) (?:i|we) do|(?:qka|cka|cfare) (?:duhet|me bo))\b/', $q);
        $follow = ($prior && ($quoteQuestion || $assumedPrice || $priceFollowup || $nextStep)) || ($prior && preg_match('/^(?:and |po |e )?(?:what if|what happens|how much(?: would| will| (?:us )?kusht\w*| del| remaining)|how does|why|pse|which plan|nese|po te|me)\b/', $q)
            && ($purchase || $budget || $supplier || preg_match('/\b(?:that|this|cost|price|plan|balanced|kusht\w*|cmim\w*|skenar\w*|metra|meters?)\b|^(?:why|pse)[?!. ]*$/', $q))
            && !preg_match('/\b(?:payroll|invoice|customer|pagat|fatur\w*|klient\w*)\b/', $q));
        $scopes = [];
        $productQuestion = $input['question'];
        foreach ([
            'warehouse' => '/\b(?:warehouse|depo\w*)\s+["“]([^"”]{2,100})["”]/iu',
            'supplier' => '/\b(?:using|with|from|me|nga|zgjidh)\s+(?:supplier|furnitor\w*)\s+["“]([^"”]{2,100})["”]/iu',
        ] as $kind => $pattern) {
            if (preg_match($pattern, $productQuestion, $match)) {
                $scopes[$kind] = $match[1];
                $productQuestion = str_replace($match[0], '', $productQuestion);
            }
        }
        $named = null;
        $productQuery = $scopes ? app(AssistantLanguage::class)->normalize($productQuestion) : $q;
        if ($assumedPrice) {
            $productQuery = preg_replace('/\b(?:for|per)\s+(?:metres?|meters?|metra|metrin|m|pieces?|pcs|cope|kg|rolls?|rrotulla|now|the moment|momentin|tani|shembull)\b/', '', $productQuery);
        }
        if (preg_match('/["“]([^"”]{2,100})["”]/u', $productQuestion, $m)) $named = $m[1];
        elseif (preg_match('/\b(?:for|per)\s+(.+?)(?=\s+(?:with|if|at|by|in|from|under|ne|nga|me|nese)\b|$)/', $productQuery, $m)
            && !preg_match('/^(?:\d|my |next |this (?:year|month|week)|last |all |our |muajin |blerj|mall\b)/', $m[1])) $named = $m[1];
        elseif ($supplier && preg_match('/\b(?:provide|ofroj\w*)\s+(.+?)[?!. ]*$/', $q, $m)) $named = $m[1];
        $currentProduct = ($input['entity']['type'] ?? null) === 'product' ? $input['entity']['id'] : null;
        if (!$follow && !$quoteQuestion && !($budget && $purchase) && !($purchase && preg_match('/\b(?:who should|cili|wait|prit\w*)\b/', $q))
            && !($supplier && $comparison && ($named || $currentProduct || $prior || $purchase
                || preg_match('/\b(?:this product|this item|ky produkt|kete produkt)\b/', $q)))) return null;

        $sq = ($input['language'] ?? 'en') === 'sq';
        $t = fn ($en, $al) => $sq ? $al : $en;
        $base = ['id' => (string) Str::uuid(), 'conversation_id' => $conversation, 'intent' => 'purchase_comparison',
            'provider' => 'deterministic', 'read_only' => true, 'cards' => [], 'sources' => [], 'tools' => [], 'choices' => []];
        $runner = app(AssistantToolRunner::class);
        $tool = PurchaseComparisonService::TOOL;
        $denied = ['state' => 'permission_denied', 'text' => $t('This role cannot view the requested purchasing or budget evidence.', 'Ky rol nuk mund t’i shohë të dhënat e kërkuara të blerjeve ose të buxhetit.')];
        if (!$runner->allowed($tool)) return $base + $denied;

        $productId = $currentProduct;
        if ($named && !in_array(trim($named, " ?!.,:"), ['it', 'that', 'this product', 'the product', 'kete product', 'ate'], true)) {
            $entity = app(AssistantEntityResolver::class)->resolve(trim($named, " ?!.,:"), 'product');
            $productId = $entity['entity']['id'] ?? null;
            if (!$productId) return array_replace($base, ['state' => 'needs_clarification', 'choices' => $entity['choices'],
                'text' => $t('Which product should I compare? Use its catalogue name or SKU.', 'Cilin produkt të krahasoj? Shkruaj emrin ose kodin nga katalogu.')]);
        }
        $productId ??= $prior['product_id'] ?? (($context['entity']['type'] ?? null) === 'product' ? $context['entity']['id'] : null);
        $product = $productId ? Product::query()->find($productId) : null;
        $workspace = '/purchasing-decisions';
        $review = ['url' => $workspace, 'label' => $t('Open Purchasing Decisions', 'Hap vendimet për blerje')];
        if (!$product) return $base + ['state' => 'needs_clarification', 'next_action' => $review,
            'text' => $t('Choose a product and quantity for supplier evidence, or add several products to the purchasing plan.', 'Zgjidh produktin dhe sasinë për dëshmitë e furnitorëve, ose shto disa produkte në planin e blerjeve.')];

        $args = (int) ($prior['product_id'] ?? 0) === (int) $product->id ? $prior : [];
        $args['product_id'] = (int) $product->id;
        $priorUnit = $assumptions->unit($args['unit'] ?? $product->unit);
        foreach ($scopes as $kind => $term) {
            $match = app(AssistantEntityResolver::class)->resolve($term, $kind);
            if (!$match['entity']) return array_replace($base, ['state' => 'needs_clarification', 'choices' => $match['choices'],
                'purchase_comparison_reference' => $args, 'next_action' => $review,
                'text' => $kind === 'warehouse'
                    ? $t('Which authorized warehouse should I use?', 'Cilën depo të autorizuar të përdor?')
                    : $t('Which authorized supplier do you mean?', 'Cilin furnitor të autorizuar nënkupton?')]);
            $args[$kind.'_id'] = (int) $match['entity']['id'];
        }
        if (preg_match('/\b(\d+(?:[.,]\d{1,3})?)\s*(meters?|metres?|metra|meter|m|pcs|pieces?|cope|kg|rolls?|rrotulla)\b/', $q, $m)) {
            $args['quantity'] = (float) str_replace(',', '.', $m[1]);
            $args['unit'] = match (true) {
                in_array($m[2], ['m','meter','meters','metres','metre','metra'], true) => 'm',
                in_array($m[2], ['pcs','piece','pieces','cope'], true) => 'pcs',
                in_array($m[2], ['roll','rolls','rrotulla'], true) => 'roll',
                default => $m[2],
            };
            if (preg_match('/^(?:m|meter|meters|metres|metre|metra)$/i', (string) $product->unit) && $args['unit'] === 'm') $args['unit'] = $product->unit;
            if (preg_match('/^(?:pcs|piece|pieces|copë|cope)$/iu', (string) $product->unit) && $args['unit'] === 'pcs') $args['unit'] = $product->unit;
        }
        $unit = $assumptions->unit($args['unit'] ?? $product->unit);
        if ($priorUnit !== $unit) unset($args['assumed_unit_price']);
        if ($assumedPrice) {
            if (!app(PurchaseComparisonService::class)->budgetAllowed()) return $base + $denied;
            if ($assumedPrice['currency'] !== \App\Support\CompanyCurrency::current() || $assumedPrice['unit'] !== $unit) {
                return $base + ['state' => 'needs_clarification', 'purchase_comparison_reference' => $args, 'next_action' => $review,
                    'text' => $t('Enter the hypothetical price in company currency per '.$unit.'.', 'Vendos çmimin hipotetik në valutën e kompanisë për '.$unit.'.')];
            }
            $args['assumed_unit_price'] = $assumedPrice['price'];
        }
        if ($budget && !$assumedPrice && preg_match('/(?:€\s*(\d[\d., ]*)|\b(\d[\d., ]*)\s*(?:eur|euro)\b)/iu', $input['question'], $m)) {
            $value = preg_replace('/\s+/', '', trim($m[1] ?: ($m[2] ?? '')));
            $value = preg_replace('/(?<=[0-9])[.,](?=\d{3}(?:\D|$))/', '', $value);
            if (preg_match('/^\d+(?:[.,]\d{1,2})?$/D', $value)) $args['budget'] = (float) str_replace(',', '.', $value);
        }
        if (preg_match('/\b(?:by|deri)\s+(20\d{2}-\d{2}-\d{2})\b/', $input['question'], $m)) $args['required_by'] = $m[1];
        if (preg_match('/\b(?:cheapest|lowest (?:cost|price)|ma i lire|me i lire)\b/', $q)) $args['priority'] = 'cost';
        elseif (preg_match('/\b(?:fastest|quickest|ma i shpejt\w*|me i shpejt\w*)\b/', $q)) $args['priority'] = 'speed';
        elseif (preg_match('/\b(?:reliab\w*|besuesh\w*)\b/', $q)) $args['priority'] = 'reliability';
        elseif (preg_match('/\b(?:balanced|balancuar|ekuilibruar)\b/', $q)) $args['priority'] = 'balanced';
        $reference = ['purchase_comparison_reference' => $args, 'context' => ['type' => 'product', 'id' => (int) $product->id]];
        $review['url'] .= '?'.http_build_query($args);
        if (isset($args['assumed_unit_price'], $args['quantity'])) {
            $review['url'] = $workspace.'?'.http_build_query(['plan' => json_encode([array_diff_key($args, ['budget' => true])]), 'budget' => $args['budget'] ?? null]).'#purchase-portfolio';
        }
        if (!isset($args['quantity'])) return $base + $reference + ['state' => 'needs_clarification', 'next_action' => $review,
            'text' => $t('How much '.$product->name.' do you want to buy? I’ll check which evidence applies to that quantity. If a quote is missing, you can enter a hypothetical unit price.', 'Sa '.$product->name.' dëshiron të blesh? Do të kontrolloj cilat të dhëna vlejnë për atë sasi. Nëse mungon oferta, mund të vendosësh një çmim hipotetik për njësi.')];
        try {
            $data = $runner->execute($tool, $args);
        } catch (ModelNotFoundException|ValidationException $e) {
            unset($reference['purchase_comparison_reference']['quantity'], $reference['purchase_comparison_reference']['unit']);
            return $base + $reference + ['state' => 'needs_clarification', 'next_action' => ['url' => $workspace, 'label' => $review['label']],
                'text' => $t('Check the product, quantity, configured unit and delivery date before comparing.', 'Kontrollo produktin, sasinë, njësinë e konfiguruar dhe datën e dorëzimit para krahasimit.')];
        } catch (HttpExceptionInterface $e) {
            if ($e->getStatusCode() === 403) return $base + $denied;
            throw $e;
        }

        $money = fn ($n) => $n.' '.$data['base_currency'];
        $criteria = ['cost' => $t('lowest quoted material cost', 'koston më të ulët të materialit sipas ofertës'),
            'speed' => $t('shortest quoted lead time', 'afatin më të shkurtër sipas ofertës'),
            'reliability' => $t('strongest recorded on-time delivery', 'dorëzimet e regjistruara më të rregullta'),
            'balanced' => $t('price, lead time and reliability together', 'çmimin, afatin dhe besueshmërinë së bashku')];
        $lead = $data['recommendation'];
        $text = $lead
            ? $lead['name'].' — '.$criteria[$lead['criterion']].' ('.$lead['comparable_suppliers'].' '.$t('comparable suppliers', 'furnitorë të krahasueshëm').').'
            : $t('No clear winner is supported. Compare recorded offers and request missing price or delivery evidence.', 'Të dhënat nuk tregojnë një fitues të qartë. Krahaso ofertat dhe kërko të dhënat që mungojnë për çmimin ose dorëzimin.');
        $text = $product->name.' · '.$data['normalized']['base_quantity'].' '.$data['normalized']['inventory_unit']."\n".$text;
        if (!$lead && $data['quote_candidates']) {
            $text .= "\n".$t('Ask these recorded candidates for a current quote: ', 'Kërko ofertë aktuale nga këta kandidatë të regjistruar: ')
                .implode(', ', array_column(array_slice($data['quote_candidates'], 0, 3), 'name')).'.';
        }
        if (($priceFollowup || $nextStep) && !$lead && $data['quote_candidates'] && !collect($data['suppliers'])->contains(fn ($row) => $row['quote'] !== null)) {
            $text .= "\n".$t('There is no current quotation for this quantity. Ask for the unit price, currency, validity, minimum and pack size, and delivery to the destination.', 'Për këtë sasi nuk ka ofertë aktuale. Kërko çmimin për njësi, valutën, vlefshmërinë, sasinë minimale, paketimin dhe dorëzimin në destinacion.');
        }
        if ($lead && $lead['history']) {
            $text .= "\n".$lead['history']['on_time_percent'].'% · '.$lead['history']['promised_samples'].' '
                .$t('assessed deliveries', 'dorëzime të vlerësuara').' · '.$lead['history']['assessed_from'].' – '.$lead['history']['assessed_to'].'.';
        }
        if ($data['scenarios'][1]['cost_source'] === 'assumption') {
            $text .= "\n".$t('Your planning assumption puts materials at ', 'Sipas supozimit tënd, materialet kushtojnë ').$money($data['scenarios'][1]['material_cost']).'. '
                .$t('Request a current quote before relying on that price.', 'Kërko ofertë aktuale para se të mbështetesh te ky çmim.');
        } elseif (preg_match('/\b(?:how much|cost|kusht\w*)\b/', $q) && $data['scenarios'][1]['material_cost'] !== null) {
            $text .= "\n".$t('Balanced material cost: ', 'Kostoja e materialit për planin e balancuar: ').$money($data['scenarios'][1]['material_cost']).'.';
        }
        if (preg_match('/\b(?:wait|prit\w*)\b/', $q)) {
            $unit = ' '.$data['normalized']['inventory_unit'];
            $text .= "\n".$t('Available to promise: ', 'Në dispozicion për porosi: ').$data['stock']['available_to_promise'].$unit
                .' · '.$t('Incoming on record: ', 'Në ardhje sipas regjistrit: ').$data['stock']['incoming'].$unit.'. '
                .$t('Check dated supply and demand before deciding whether to wait.', 'Kontrollo datat e furnizimit dhe kërkesën para se të vendosësh nëse duhet të presësh.');
        }
        if ($budget || isset($args['budget'])) {
            $balanced = $data['scenarios'][1];
            if ($balanced['remaining_envelope'] !== null) $text .= "\n".$t('Balanced: budget remaining before freight and taxes: ', 'Plani i balancuar: buxheti i mbetur para transportit dhe tatimeve: ').$money($balanced['remaining_envelope']).'.';
            elseif ($balanced['illustrative_envelope_remaining'] !== null) $text .= "\n".$t('Illustrative envelope remaining: ', 'Buxheti ilustrues i mbetur: ').$money($balanced['illustrative_envelope_remaining']).'.';
            elseif (isset($args['budget'])) $text .= "\n".$t('No budget remainder can be calculated until a supplier and a usable material price are selected.', 'Buxheti i mbetur nuk mund të llogaritet pa furnitor dhe çmim të përdorshëm.');
            else $text .= "\n".$t('Enter a budget amount to check the remainder. Recorded cash is not spendable cash.', 'Shkruaj shumën e buxhetit për të parë mbetjen. Gjendja e regjistruar nuk është para e lirë për shpenzim.');
        }
        $cards = array_map(fn ($s) => ['title' => $s['name'], 'url' => $s['quote']['url'] ?? $review['url'], 'summary' => implode(' · ', array_filter([
            isset($s['quote']['total']) ? $t('Quoted materials: ', 'Materiali sipas ofertës: ').$money($s['quote']['total'])
                : (isset($s['catalogue_estimate']['total']) ? $t('Catalogue estimate: ', 'Vlerësim nga katalogu: ').$money($s['catalogue_estimate']['total']) : $t('No comparable price available', 'Nuk ka çmim të krahasueshëm')),
            isset($s['quote']['lead_days']) ? $s['quote']['lead_days'].' '.$t('days quoted', 'ditë sipas ofertës') : $t('Delivery unverified', 'Dorëzimi i paverifikuar'),
        ]))], array_slice($data['suppliers'], 0, 4));
        return array_replace($base + $reference, ['state' => $data['suppliers'] ? 'answered' : 'insufficient',
            'text' => $text."\n".$t('Freight, duty, taxes and destination delivery are not verified by this comparison.', 'Transporti, dogana, tatimet dhe dorëzimi në depon e zgjedhur nuk verifikohen nga ky krahasim.'),
            'tools' => $runner->executions, 'cards' => $cards, 'next_action' => $review]);
    }
}
