<?php

// Evaluation only. The DC18.3 bootstrap verifies the isolated DC16 installation.
$mode = $argv[1] ?? '';
if (! in_array($mode, ['development', 'acceptance', 'ask40'], true)) {
    fwrite(STDERR, "Usage: php scripts/dc19/evaluate.php development|acceptance|ask40 [run label]\n");
    exit(2);
}
$label = $argv[2] ?? 'first';
if (! preg_match('/^[a-z][a-z0-9-]*$/D', $label)) {
    exit(2);
}
require dirname(__DIR__).'/dc183/bootstrap.php';

$dc17Root = 'C:/Users/anisi/.codex/recovery/dc19-20261105';
$file = $mode === 'ask40' ? dirname(__DIR__).'/dc16/ask-corpus.json' : "$dc17Root/$mode.json";
$cases = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
$hash = hash_file('sha256', $file);
if ($mode !== 'ask40') {
    $frozen = collect(json_decode(file_get_contents("$dc17Root/frozen-sha256.json"), true))->firstWhere('file', "$mode.json");
    if (strtolower($frozen['Hash'] ?? '') !== $hash) {
        throw new RuntimeException('Question set changed after freezing.');
    }
}
$output = "$dc17Root/evidence/$mode-$label.json";
if (is_file($output)) {
    throw new RuntimeException('This run label is already recorded.');
}
$digest = static function (): string {
    $hash = hash_init('sha256');
    foreach (['stock_movements', 'sales_orders', 'purchase_orders', 'purchase_requests', 'customer_debt_transactions', 'invoices', 'journal_entries', 'journal_lines', 'payroll_runs', 'payroll_lines'] as $table) {
        hash_update($hash, $table);
        foreach (\Illuminate\Support\Facades\DB::table($table)->orderBy('id')->cursor() as $row) {
            hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR));
        }
    }
    return hash_final($hash);
};
$before = $digest();
$subset = $mode === 'development' ? array_filter(explode(',', getenv('DC19_DEVELOPMENT_CASES') ?: '')) : [];
$cases = $subset ? array_values(array_filter($cases, fn ($case) => in_array($case['id'], $subset, true))) : $cases;
$tokens = $conversations = $results = [];
$ask = static function (array $payload, string $token): array {
    $reply = dc17Request('POST', 'intelligence-assistant/ask', $payload, $token);
    if ($reply['status'] === 429) {
        sleep(min(65, max(1, (int) ($reply['retry_after'] ?? 60))));
        return dc17Request('POST', 'intelligence-assistant/ask', $payload, $token);
    }
    return $reply;
};
foreach ($cases as $case) {
    $role = $case['role'];
    $tokens[$role] ??= dc17Login($role);
    $group = $role.':'.($case['group'] ?? $case['id']);
    $payload = ['question' => $case['question'], 'language' => $case['language']];
    if (isset($conversations[$group])) {
        $payload['conversation_id'] = $conversations[$group];
    }
    $payload += match ($case['entity'] ?? null) {
        'milano04' => ['entity' => ['type' => 'product', 'id' => 4]],
        'first_customer' => ['entity' => ['type' => 'customer', 'id' => 1]],
        default => [],
    };
    $reply = $ask($payload, $tokens[$role]);
    $conversations[$group] = $reply['body']['conversation_id'] ?? null;
    $row = ['case' => $case] + $reply;
    if (isset($case['followup'], $conversations[$group])) {
        $row['followup'] = $ask(['question' => $case['followup'], 'language' => $case['language'], 'conversation_id' => $conversations[$group]], $tokens[$role]);
    }
    $results[] = $row;
    printf("%s HTTP %s %.0f ms %s\n", $case['id'], $reply['status'], $reply['ms'], $reply['body']['provider'] ?? '');
}
$after = $digest();
dc17Evidence("$mode-$label", [
    'corpus_sha256' => $hash,
    'selected_cases' => $subset,
    'protected_before' => $before,
    'protected_after' => $after,
    'protected_unchanged' => hash_equals($before, $after),
    'results' => $results,
]);
exit(hash_equals($before, $after) ? 0 : 1);
