<?php

declare(strict_types=1);

namespace FancyCompliance\Contracts;

use Illuminate\Http\Request;

/**
 * The one question another package needs to ask before it collects anything:
 * "may I, for this category, for whoever made this request?"
 *
 * This is the contract `fancy-heuristics` (or any collector) should type-hint,
 * rather than the manager or the facade, so that it can bind a fallback of its
 * own when this package is not installed. It is deliberately read-only: there
 * is no method here that grants or records anything.
 *
 * Unknown categories answer false (fail closed). Required categories answer
 * true without a decision.
 */
interface ConsentGate
{
    public function allows(string $category, ?Request $request = null): bool;
}
