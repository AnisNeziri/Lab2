<?php

namespace App\Services;

use App\Models\OnboardingSession;
use App\Models\Product;
use App\Services\Onboarding\OnboardingReconciliation;
use Illuminate\Support\Str;

/** Bounded facts only. No staging mutation, forecasting, initialization, or posting. */
class OpeningFactsService
{
    public function lowStock(): array
    {
        $available = 'COALESCE((SELECT SUM(available_quantity) FROM warehouse_stock WHERE warehouse_stock.company_id=products.company_id AND warehouse_stock.product_id=products.id),products.quantity)';
        $threshold = 'CASE WHEN COALESCE(products.reorder_point,0)>products.min_quantity THEN products.reorder_point ELSE products.min_quantity END';
        $products = Product::withStockTotals()->where('lifecycle_status', 'active')->whereRaw($available.' < '.$threshold)->orderBy('id')->limit(25)->get();

        return ['is_prediction' => false, 'as_of' => now()->toIso8601String(), 'limit' => 25, 'products' => $products->map(fn ($p) => [
            'id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'available' => $p->available_quantity, 'threshold' => max((float) $p->reorder_point, (float) $p->min_quantity), 'unit' => $p->unit, 'url' => '/products?product='.$p->id,
        ])->all()];
    }

    public function onboarding(array $input = []): array
    {
        $query = OnboardingSession::query();
        $s = isset($input['session_id']) ? $query->findOrFail($input['session_id']) : $query->where('status', '!=', 'cancelled')->latest('id')->first();
        if (! $s) {
            return ['session' => null, 'rows' => []];
        }
        $rows = $s->rows()->active()->whereIn('status', ['review', 'error', 'unvalidated', 'failed'])
            ->when(isset($input['domain']), fn ($q) => $q->where('domain', $input['domain']))->orderBy('id')->limit(25)->get(['id', 'line_number', 'domain', 'external_ref', 'status', 'errors', 'warnings']);

        return ['session' => $s->only(['id', 'public_id', 'name', 'status', 'currency', 'revision']),
            'report' => app(OnboardingReconciliation::class)->report($s), 'rows' => $rows, 'limit' => 25, 'url' => '/onboarding?session='.$s->id];
    }

    public function reply(string $intent, string $question, string $conversationId, bool $sq): array
    {
        $runner = app(AssistantToolRunner::class);
        $tool = $intent === 'low_stock' ? 'get_low_stock_facts' : (preg_match('/import|produkt|products/iu', $question) ? 'get_import_review' : 'get_onboarding_reconciliation');
        $data = $runner->execute($tool, $tool === 'get_import_review' ? ['domain' => 'products'] : []);
        $cards = [];
        if ($intent === 'low_stock') {
            $text = $sq ? 'Stoku aktual i disponueshëm nën pragun e konfiguruar. Këto janë fakte aktuale; nuk është parashikim i mbarimit të stokut.' : 'Current available stock below the configured threshold. These are current facts; this is not a stockout prediction.';
            foreach ($data['products'] as $p) {
                $cards[] = ['tool' => $tool, 'title' => $p['name'], 'url' => $p['url'], 'summary' => $p['sku'],
                    'metrics' => [['label' => $sq ? 'I disponueshëm' : 'Available', 'value' => $p['available'], 'unit' => $p['unit']], ['label' => $sq ? 'Pragu' : 'Threshold', 'value' => $p['threshold'], 'unit' => $p['unit']]], 'evidence' => ['as_of' => $data['as_of'], 'is_prediction' => false]];
            }
            if (! $cards) {
                $text .= $sq ? ' Asnjë produkt nuk është nën pragun e konfiguruar.' : ' No products are below a configured threshold.';
            }
        } else {
            $text = $data['session'] ? ($sq ? 'Kontrollet e hapjes dhe rreshtat që kërkojnë shqyrtim janë më poshtë. Regjistrimi kërkon shqyrtim dhe miratim nga ndërfaqja.' : 'Opening controls and rows needing review are shown below. Posting requires review and approval in the onboarding screen.') :
                ($sq ? 'Nuk ka seancë aktive të hapjes për këtë kompani.' : 'There is no active opening session for this company.');
            if ($data['session']) {
                $counts = $data['report']['counts'];
                $cards[] = ['tool' => $tool, 'title' => $data['session']['name'], 'url' => $data['url'], 'summary' => $sq ? 'Përmbledhje e hapjes' : 'Opening summary',
                    'metrics' => [['label' => $sq ? 'Kërkon shqyrtim' : 'Needs review', 'value' => ($counts['review'] ?? 0) + ($counts['error'] ?? 0) + ($counts['unvalidated'] ?? 0), 'unit' => ''], ['label' => $sq ? 'Diferenca burimore' : 'Blocking checks', 'value' => count($data['report']['blocking']), 'unit' => '']],
                    'evidence' => ['source_controls' => $data['report']['sections'], 'source_quantities_by_unit' => $data['report']['source_inventory_units'], 'staged_quantities_by_unit' => $data['report']['inventory_units']]];
                foreach ($data['rows'] as $row) {
                    $cards[] = ['tool' => $tool, 'title' => ($sq ? 'Rreshti ' : 'Source row ').$row->line_number.' · '.$row->external_ref, 'url' => $data['url'].'&domain='.$row->domain, 'summary' => $sq ? 'Kërkon shqyrtim të burimit' : 'Source review required', 'metrics' => [], 'evidence' => ['checks' => $row->errors, 'warnings' => $row->warnings]];
                }
            }
        }

        return ['id' => (string) Str::uuid(), 'conversation_id' => $conversationId, 'intent' => $intent, 'text' => $text, 'cards' => $cards, 'sources' => [['tool' => $tool, 'url' => $intent === 'low_stock' ? '/products' : ($data['url'] ?? '/onboarding')]],
            'limitations' => [$sq ? 'Deri në 25 rezultate; hap burimin për listën e plotë.' : 'Up to 25 results; open the source for the complete list.'], 'choices' => [], 'follow_ups' => [], 'context' => null, 'context_entities' => [],
            'read_only' => true, 'provider' => 'deterministic', 'state' => 'success', 'is_prediction' => false, 'tools' => $runner->executions];
    }
}
