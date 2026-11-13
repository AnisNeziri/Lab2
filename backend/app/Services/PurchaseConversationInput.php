<?php

namespace App\Services;

final class PurchaseConversationInput
{
    public const QUANTITY = '(\d+(?:[.,]\d{1,3})?)\s*(metres?|meters?|metra|m|pieces?|pcs|cope|kg|rolls?|rrotulla)\b';

    public function correction(string $question): ?array
    {
        if (preg_match('/^(?:forget (?:that|the) product|harro (?:ate|kete) product)[,.; ]+(?:let.s talk about|folim per)\s+(.+?)[.?! ]*$/', $question, $match)) {
            return ['product' => $match[1], 'reset' => true];
        }
        if (preg_match('/^(?:no[, ]+i meant|i meant|jo[, ]+(?:at[e]? product[, ]+|e pata fjalen per ))\s*(.+?)[.?! ]*$/', $question, $match)) {
            return ['product' => trim($match[1], ' "'), 'reset' => false];
        }
        return null;
    }

    public function resumesPlan(string $question): bool
    {
        return (bool) preg_match('/\b(?:back to|return to|ktheh\w* te)\b.*\b(?:budget|plan|buxhet\w*|plan\w*)\b/', $question);
    }

    public function relativeDelivery(string $question): bool
    {
        return (bool) preg_match('/\b(?:next (?:week|month)|javen tjeter|muajin tjeter|javen e ardhshme|muajin e ardhshem)\b/', $question);
    }
}
