<?php

namespace App\Services;

use App\Contracts\IntelligenceProvider;
use App\Models\Product;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Carries only authorized input assumptions. All calculation happens in the typed read tool. */
final class PurchasePortfolioAssistant
{
    private const QUANTITY = PurchaseConversationInput::QUANTITY;

    public function answer(array $input, string $conversation, array $context): ?array
    {
        if (isset($input['simulation_id']) || isset($input['customer_id']) || isset($input['entity']) && $input['entity']['type'] !== 'product') return null;
        $question = $input['question'];
        $language = app(AssistantLanguage::class);
        $q = $language->normalize($question);
        $conversationInput = app(PurchaseConversationInput::class);
        $resume = ($context['purchase_focus'] ?? null) === 'purchase_portfolio' && $conversationInput->resumesPlan($q);
        $prior = $resume || ($context['last_intent'] ?? null) === 'purchase_portfolio' ? ($context['purchase_portfolio'] ?? []) : [];
        $explicit = preg_match('/\b(?:purchasing plan|purchase plan|buying plan|purchasing portfolio|plan\w* (?:i |e |per |te )?blerj\w*|buxhet\w* (?:per |te )?blerj\w*)\b/', $q);
        if (!$explicit && preg_match('/\b(?:payroll|customer|sales|payables?|receivables?|pagat|klient\w*|fatur\w*)\b/', $q)) return null;
        $prioritize = preg_match('/\b(?:buy first|order first|prioriti[sz]e|porosit\w* (?:tani|now)|order now)\b/', $q)
            && preg_match('/\b(?:budget\w*|buxhet\w*|buy|purchase|order)\b/', $q);
        $assumptions = app(PurchaseAssumptionInput::class);
        $assumedPrice = $assumptions->unitPrice($question);
        $smaller = $prior && preg_match('/\b(?:smaller|shrink|reduce|scale down|zvogel\w*|zvogelo\w*|pak\w* sasi)\b/', $q);
        $cashQuestion = $prior && preg_match('/\b(?:enough cash|enough money|fund\w* this|pare\w* (?:te )?mjaft\w*|na dalin paret)\b/', $q);
        $nextStep = $prior && preg_match('/^(?:what (?:(?:should|do) (?:i|we) do|should me do|me do)|cka should)\b/', $q);
        $totalQuestion = $prior && preg_match('/\b(?:altogether|all in|krejt|sa (?:po )?na del)\b/', $q);
        $comparison = ($context['last_intent'] ?? null) === 'purchase_comparison' ? ($context['purchase_comparison'] ?? []) : [];
        $addFromComparison = !empty($comparison['quantity']) && preg_match('/\b(?:add\w*|shto\w*)\b/', $q);
        $keepSupplier = $prior && preg_match('/\b(?:same supplier|te njejt\w* (?:supplier|furnitor\w*)|(?:supplier|furnitor\w*) te njejt\w*)\b/', $q);
        $reference = $prior && ($assumedPrice || $smaller || $cashQuestion || $nextStep || $totalQuestion || preg_match('/\b(?:add\w*|shto\w*|budget\w*|buxhet\w*|total|gjithsej|safer|sigurt|conservative|konservator|plan|kusht\w*|afford|safe to spend|quot\w*|ofert\w*|suppliers?)\b/', $q))
            && !preg_match('/\b(?:payroll|pay supplier|customer|sales|write off|post journal|pagat|klient\w*)\b/', $q);
        $pending = $prior['pending_product'] ?? null;
        $correction = $prior ? $conversationInput->correction($q) : null;
        $resize = $prior && !$pending && preg_match('/^(?:use|make it|beje|vendos|perdor)\s+'.self::QUANTITY.'/i', $q, $size);
        if (!$resume && !$explicit && !$prioritize && !$reference && !$addFromComparison && !$keepSupplier
            && !$correction && !$resize && !($pending && preg_match('/^'.self::QUANTITY.'[.?! ]*$/', $q))) return null;

        $t = fn ($en, $sq) => ($input['language'] ?? 'en') === 'sq' ? $sq : $en;
        $base = ['id' => (string) Str::uuid(), 'conversation_id' => $conversation, 'intent' => 'purchase_portfolio',
            'provider' => 'deterministic', 'read_only' => true, 'cards' => [], 'sources' => [], 'tools' => [], 'choices' => []];
        $runner = app(AssistantToolRunner::class);
        $tool = PurchaseComparisonService::PORTFOLIO_TOOL;
        $denied = ['state' => 'permission_denied', 'text' => $t('This role cannot view company purchasing budgets.', 'Ky rol nuk mund të shohë buxhetet e blerjeve të kompanisë.')];
        if (!$runner->allowed($tool)) return $base + $denied;
        $review = ['url' => '/purchasing-decisions#purchase-portfolio', 'label' => $t('Open purchasing plan', 'Hap planin e blerjeve')];
        $args = $prior['args'] ?? ['lines' => []];
        if (!$prior && ($explicit || $addFromComparison) && isset($comparison['quantity'])) {
            $previous = $comparison;
            $args['lines'][] = array_diff_key($previous, ['budget' => true]);
            if (isset($previous['budget'])) $args['budget'] = $previous['budget'];
        }
        if (!$assumedPrice && preg_match('/\b(?:budget\w*|buxhet\w*|envelope)\b/', $q) && ($budget = $this->budget($question)) !== null) $args['budget'] = $budget;
        $resolver = app(AssistantEntityResolver::class);
        if ($correction && ($pending || count($args['lines']) !== 1 || $correction['reset'])) {
            $match = $resolver->resolve($correction['product'], 'product');
            $product = $match['entity'];
            if (!$pending && count($args['lines']) > 1) {
                return $base + ['state' => 'needs_clarification', 'next_action' => $review,
                    'purchase_portfolio_reference' => $prior,
                    'text' => $t('Which line in the existing plan do you want to replace? Open the plan to edit it.',
                        'Cilin rresht të planit dëshiron të zëvendësosh? Hape planin për ta ndryshuar.')];
            }
            if ($product && $correction['reset']) $args['lines'] = [];
            return array_replace($base, ['state' => 'needs_clarification', 'next_action' => $review,
                'choices' => $match['choices'],
                'purchase_portfolio_reference' => ['args' => $args, 'pending_product' => $product ?? $pending],
                'text' => $product
                    ? $t('What quantity of '.$product['title'].' should I add?', 'Çfarë sasie të shtoj për '.$product['title'].'?')
                    : $t('Which product should I add? Your existing plan is saved.', 'Cilin produkt të shtoj? Plani ekzistues është ruajtur.')]);
        }
        if ($correction) {
            $match = $resolver->resolve($correction['product'], 'product');
            $product = isset($match['entity']) ? Product::find($match['entity']['id']) : null;
            if (!$product || $assumptions->unit($args['lines'][0]['unit'] ?? '') !== $assumptions->unit($product->unit)) {
                return array_replace($base, ['state' => 'needs_clarification', 'choices' => $match['choices'],
                    'next_action' => $review, 'purchase_portfolio_reference' => $prior,
                    'text' => $t('Confirm the replacement product and quantity in Purchasing Decisions. I kept your existing plan.',
                        'Konfirmo produktin zëvendësues dhe sasinë te Vendimet për blerje. Plani ekzistues është ruajtur.')]);
            }
            if ((int) $args['lines'][0]['product_id'] !== (int) $product->id) {
                $args['lines'][0]['product_id'] = $product->id;
                unset($args['lines'][0]['supplier_id'], $args['lines'][0]['assumed_unit_price'], $args['lines'][0]['assumed_extra_cost']);
            }
        }
        if ($resize) {
            if (count($args['lines']) !== 1) {
                return $base + ['state' => 'needs_clarification', 'next_action' => $review,
                    'purchase_portfolio_reference' => $prior,
                    'text' => $t('Which product should use that quantity? Your plan is still saved.',
                        'Për cilin produkt ta përdor atë sasi? Plani yt është ruajtur.')];
            }
            $line = &$args['lines'][0];
            $unit = $assumptions->unit($size[2]);
            if ($assumptions->unit($line['unit'] ?? '') !== $unit) unset($line['assumed_unit_price']);
            $line['unit'] = $unit;
            $line['quantity'] = (float) str_replace(',', '.', $size[1]);
            unset($line);
        }
        preg_match_all('/["“]([^"”]{2,80})["”]\s*(?:[:=]|at|me|for|per)?\s*'.self::QUANTITY.'/iu', $question, $pairs, PREG_SET_ORDER);
        $outsideNames = preg_replace('/["“][^"”]*["”]/u', '', $question);
        $unpairedQuantity = $pairs && preg_match_all('/'.self::QUANTITY.'/iu', $outsideNames) > count($pairs);
        if ($unpairedQuantity) {
            return $base + ['state' => 'needs_clarification', 'next_action' => $review,
                'text' => $t('Put each product name in quotes and add its quantity so I can match every line.',
                    'Vendos emrin e secilit produkt në thonjëza dhe shto sasinë që të mund t’i lidh të gjitha rreshtat.'),
                'purchase_portfolio_reference' => ['args' => $args]];
        }
        $adding = (bool) preg_match('/\b(?:add\w*|shto\w*)\b/', $q);
        if (!$pairs && preg_match('/\b(?:add\w*|shto\w*)\s+([^,?!]{2,90}?)(?=\s+(?:to|ne|affect|ndikon|at|me|per)\b|[?!.,]|$)/i', $q, $m)) {
            $name = preg_replace('/^(?:also|another|edhe)\s+/i', '', trim($m[1], " \""));
            $found = $resolver->resolve($name, 'product')['entity'];
            if ($found && preg_match('/'.self::QUANTITY.'/i', $q, $amount)) $pairs[] = [null, $found['title'], $amount[1], $amount[2]];
            elseif ($found) return $base + ['state' => 'needs_clarification', 'purchase_portfolio_reference' => ['args' => $args, 'pending_product' => $found],
                'next_action' => $review, 'text' => $t('What quantity of '.$found['title'].' should I add?', 'Çfarë sasie të shtoj për '.$found['title'].'?')];
        }
        if (!$pairs && $pending && preg_match('/^'.self::QUANTITY.'[.?! ]*$/', $q, $amount)) {
            $pairs[] = [null, $pending['title'], $amount[1], $amount[2]];
            $adding = true;
        }
        if (!$pairs && !$args['lines'] && $explicit && preg_match_all('/'.self::QUANTITY.'/i', $q, $amounts, PREG_SET_ORDER) === 1) {
            // One optional local interpretation. The number comes from the question, not from the model.
            $provider = app(IntelligenceProvider::class);
            $proposal = $provider instanceof LocalAssistantProvider ? $provider->businessIntent($question, ['purchase_product']) : null;
            if ($proposal) {
                $name = preg_replace('/^'.preg_quote($amounts[0][0], '/').'\s+(?:(?:of|te|nga)\s+)?/iu', '', trim($proposal['phrase']));
                $candidate = $language->normalize($name);
                if ($candidate !== '' && preg_match('/(?<![\pL\pN])'.preg_quote($candidate, '/').'(?![\pL\pN])/u', $q)) {
                    $pairs[] = [null, $name, $amounts[0][1], $amounts[0][2]];
                    $base['provider'] = 'ollama';
                }
            }
        }
        if ($pairs) {
            $lines = $adding ? array_column($args['lines'], null, 'product_id') : [];
            foreach ($pairs as [, $name, $quantity, $unit]) {
                $match = $resolver->resolve($name, 'product');
                if (!$match['entity']) return array_replace($base, ['state' => 'needs_clarification', 'choices' => $match['choices'],
                    'text' => $t('Which catalogue product do you mean by '.$name.'?', 'Cilin produkt të katalogut nënkupton me '.$name.'?'), 'next_action' => $review,
                    'purchase_portfolio_reference' => ['args' => $args]]);
                $product = $match['entity']['id'];
                $unit = match (true) {
                    preg_match('/^(?:m|metr|meter)/i', $unit) === 1 => 'm',
                    preg_match('/^(?:piece|pcs|cope)/i', $unit) === 1 => 'pcs',
                    preg_match('/^(?:roll|rrotulla)/i', $unit) === 1 => 'roll',
                    default => strtolower($unit),
                };
                $line = ['product_id' => (int) $product, 'quantity' => (float) str_replace(',', '.', $quantity), 'unit' => $unit];
                $previous = $lines[$product] ?? [];
                if ($adding && !$previous) {
                    $warehouses = array_unique(array_map(fn ($existing) => $existing['warehouse_id'] ?? null, $args['lines']));
                    if (count($warehouses) === 1 && ($warehouse = reset($warehouses))) $line['warehouse_id'] = $warehouse;
                }
                if (isset($previous['unit']) && $assumptions->unit($previous['unit']) !== $unit) unset($previous['assumed_unit_price']);
                $lines[$product] = array_replace($previous, $line);
            }
            $args['lines'] = array_values($lines);
        }
        if (!$args['lines'] || count($args['lines']) > 5 || ($adding && !$pairs)) {
            return $base + ['state' => 'needs_clarification', 'purchase_portfolio_reference' => ['args' => $args], 'next_action' => $review,
                'text' => $t('Give up to five product names or SKUs with quantities, or add them in Purchasing Decisions. A budget alone cannot establish which goods are needed.',
                    'Jep deri në pesë emra ose kode produktesh me sasi, ose shtoji te Vendimet për blerje. Vetëm buxheti nuk tregon cilat mallra duhen.')];
        }
        if ($assumedPrice) {
            $products = Product::whereIn('id', array_column($args['lines'], 'product_id'))->get(['id', 'name', 'sku', 'unit'])->keyBy('id');
            $named = $products->filter(fn ($product) => collect([$product->name, $product->sku])->filter()->contains(
                fn ($name) => preg_match('/(?<![\pL\pN])'.preg_quote($name, '/').'(?![\pL\pN])/iu', $question)));
            $productId = $named->count() === 1 ? $named->first()->id : (count($args['lines']) === 1 ? $args['lines'][0]['product_id'] : null);
            $index = $productId ? array_search($productId, array_column($args['lines'], 'product_id')) : false;
            if ($index === false || !$products->has($productId)
                || $assumedPrice['currency'] !== \App\Support\CompanyCurrency::current()
                || $assumedPrice['unit'] !== $assumptions->unit($args['lines'][$index]['unit'] ?? $products[$productId]->unit)) {
                return $base + ['state' => 'needs_clarification', 'purchase_portfolio_reference' => ['args' => $args], 'next_action' => $review,
                    'text' => $t('Name one product in the plan and enter the hypothetical price in company currency per its selected purchase unit.',
                        'Trego një produkt nga plani dhe çmimin hipotetik në valutën e kompanisë për njësinë e zgjedhur të blerjes.')];
            }
            $args['lines'][$index]['assumed_unit_price'] = $assumedPrice['price'];
        }
        try {
            $data = $runner->execute($tool, $args);
        } catch (ModelNotFoundException|ValidationException) {
            return $base + ['state' => 'needs_clarification', 'next_action' => $review,
                'purchase_portfolio_reference' => $prior ?: ['args' => ['lines' => []]],
                'text' => $t('Check the product, quantity, unit or budget in Purchasing Decisions.', 'Kontrollo produktin, sasinë, njësinë ose buxhetin te Vendimet për blerje.')];
        } catch (HttpExceptionInterface $error) {
            if ($error->getStatusCode() === 403) return $base + $denied;
            throw $error;
        }
        $scenario = $data['scenarios'][$smaller || preg_match('/\b(?:conservative|konservator|safer|sigurt)\b/', $q) ? 0 : 1];
        $products = implode(', ', array_column(array_column($data['lines'], 'product'), 'name'));
        $text = $products.".\n";
        if ($smaller) {
            $text .= $t('Smaller order: ', 'Porosia më e vogël: ').implode(', ', array_map(
                fn ($line) => $line['product']['name'].' '.$line['quantity'].' '.$line['unit'],
                array_values(array_filter($scenario['lines'], fn ($line) => $line['quantity'] !== null))
            )).'. ';
        }
        $currency = ' '.$data['base_currency'].'. ';
        $sources = [
            'quote' => $t('Verified current quotes: ', 'Oferta aktuale të verifikuara: '),
            'catalogue' => $t('Catalogue estimates: ', 'Vlerësime nga katalogu: '),
            'assumption' => $t('Your hypothetical prices: ', 'Çmimet e tua hipotetike: '),
        ];
        foreach ($sources as $source => $label) {
            $subtotal = $scenario['material_subtotals'][$source];
            if ($subtotal['count']) $text .= $label.$subtotal['amount'].$currency;
        }
        if (!$scenario['material_subtotals']['quote']['count'] && preg_match('/\b(?:quot\w*|ofert\w*)\b/', $q)) {
            $text .= $t('No line has a verified current supplier quote for its quantity. ',
                'Asnjë rresht nuk ka ofertë aktuale të verifikuar nga furnitori për sasinë e kërkuar. ');
        }
        if (!$scenario['priced_count']) $text .= $t('There are no usable material prices for these quantities. ', 'Nuk ka çmime të përdorshme për këto sasi. ');
        if ($scenario['unpriced_count']) $text .= $scenario['unpriced_count'].' '.$t('items are unpriced. ', 'artikuj janë pa çmim. ');
        if ($scenario['priced_costs_exceed_envelope']) $text .= $t('The priced subtotal already exceeds the planning budget. ', 'Nëntotali me çmim tashmë e tejkalon buxhetin. ');
        elseif ($scenario['illustrative_envelope_remaining'] !== null) $text .= $t('Illustrative envelope remaining: ', 'Buxheti ilustrues i mbetur: ').$scenario['illustrative_envelope_remaining'].$currency;
        $text .= $data['strategy'] === 'quantity_sensitivity'
            ? $t('Smaller quantities reduce exposure when priced. The records do not establish a replenishment strategy. ', 'Sasitë më të vogla ulin angazhimin kur kanë çmim. Regjistrimet nuk përcaktojnë një strategji furnizimi. ')
            : $t('Only quantities backed by uncovered confirmed orders enter these plans. ', 'Planet përfshijnë vetëm sasi të mbështetura nga porosi të konfirmuara të pambuluara. ');
        $text .= $t('Cash available to spend and full landed costs remain unverified.', 'Paratë e disponueshme për shpenzim dhe kostot e plota deri në depo mbeten të paverifikuara.');
        if ($data['financial']['evidence_readiness']['status'] === 'blocked') {
            $text .= ' '.$t('Finance must reconcile the records before we can confirm total obligations or how much is safe to spend.',
                'Financat duhet të rakordojnë regjistrimet para se të konfirmojmë detyrimet totale ose sa mund të shpenzohet me siguri.');
        }
        if ($data['financial']['unresolved_po_overlaps_or_fx'] && ($cashQuestion || preg_match('/\b(?:afford|safe to spend|why|pse|buxhet\w*|cash)\b/', $q))) {
            $text .= ' '.$data['financial']['unresolved_po_overlaps_or_fx'].' '.$t('purchase orders still need a finance review.', 'porosi blerjeje ende kërkojnë shqyrtim financiar.');
        }
        if ($nextStep) {
            $text .= ' '.$t('Open the plan, request current quotes for missing terms, and ask Finance to confirm funding before committing.', 'Hape planin, kërko oferta aktuale për kushtet që mungojnë dhe kërko nga Financat të verifikojnë fondet para se të zotohesh.');
        }
        if (preg_match('/\b(?:ask|quot\w*|ofert\w*|kerko\w*)\b/', $q) && preg_match('/\b(?:supplier|furnitor\w*)\b/', $q)) {
            $text .= ' '.$t('Ask for unit price and currency, validity, minimum and pack size, delivery date, freight, duty and tax.',
                'Kërko çmimin për njësi dhe valutën, vlefshmërinë, minimumin dhe paketimin, datën e dorëzimit, transportin, doganën dhe tatimin.');
        }
        $review['url'] = '/purchasing-decisions?'.http_build_query(['plan' => json_encode($args['lines']), 'budget' => $args['budget'] ?? null]).'#purchase-portfolio';
        return array_replace($base, ['state' => 'answered', 'text' => $text, 'next_action' => $review,
            'tools' => $runner->executions, 'purchase_portfolio_reference' => ['args' => $args]]);
    }

    private function budget(string $question): ?float
    {
        if (!preg_match('/(?:€\s*(\d[\d., ]*)|\b(\d[\d., ]*)\s*(?:eur|euro)\b)/iu', $question, $matches)) return null;
        $value = preg_replace('/\s+/', '', trim($matches[1] ?: ($matches[2] ?? '')));
        $value = preg_replace('/(?<=[0-9])[.,](?=\d{3}(?:\D|$))/', '', $value);
        return preg_match('/^\d+(?:[.,]\d{1,2})?$/D', $value) ? (float) str_replace(',', '.', $value) : null;
    }
}
