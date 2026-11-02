<?php

namespace App\Services\Platform;

use App\Contracts\BusinessEmbeddingProvider;
use Illuminate\Support\Str;

/** Local domain-concept embedding, not a pretrained language model. No network or paid provider. */
class LocalBusinessEmbedding implements BusinessEmbeddingProvider
{
    public function version(): string
    {
        return 'local-business-concepts-v1-256';
    }

    public function tokens(string $text): array
    {
        $text = Str::lower(Str::ascii($text));
        $words = preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $concepts = ['fabric' => ['fabric', 'textile', 'textiles', 'cloth', 'tekstil', 'material', 'pambuk'], 'china' => ['china', 'chinese', 'kine', 'kines', 'ningbo', 'shanghai', 'shenzhen'], 'incoming' => ['incoming', 'arriving', 'arrival', 'coming', 'shipment', 'dergese', 'vjen', 'transit'], 'customs' => ['customs', 'dogan', 'dogana', 'doganore', 'clearance'], 'contract' => ['contract', 'kontrate', 'employment', 'marreveshje'], 'ending' => ['ending', 'expiring', 'expiry', 'skadon', 'skadim', 'ends'], 'debt' => ['debt', 'owe', 'owes', 'overdue', 'borxh', 'borxhi', 'debit'], 'invoice' => ['invoice', 'bill', 'fature', 'fatura'], 'customer' => ['customer', 'client', 'klient', 'klienti']];
        $map = [];
        foreach ($concepts as $concept => $terms) {
            foreach ($terms as $word) {
                $map[$word] = $concept;
            }
        }

        return array_values(array_unique(array_filter(array_map(fn ($w) => $map[$w] ?? $w, $words), fn ($w) => strlen($w) > 1 && ! in_array($w, ['the', 'that', 'from', 'for', 'about', 'what', 'find', 'show', 'me', 'us', 'is', 'are', 'and', 'of', 'to', 'nga', 'per', 'we', 'it'], true))));
    }

    public function embed(string $text): array
    {
        $v = [];
        foreach ($this->tokens($text) as $token) {
            $features = [$token => 3];
            if (strlen($token) > 3) {
                for ($i = 0; $i <= strlen($token) - 3; $i++) {
                    $features['~'.substr($token, $i, 3)] = .2;
                }
            }foreach ($features as $key => $weight) {
                $slot = hexdec(substr(hash('sha256', $key), 0, 4)) % 256;
                $v[$slot] = ($v[$slot] ?? 0) + $weight;
            }
        }$norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $v))) ?: 1;

        return array_map(fn ($x) => round($x / $norm, 6), $v);
    }
}
