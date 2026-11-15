<?php

declare(strict_types=1);

namespace Relay\Services;

use Relay\Support\Settings;

final class BillingService
{
    public function __construct(private Settings $settings)
    {
    }

    /** @param array<string,mixed> $model @param array<string,mixed> $usage @param array<string,mixed> $plan */
    public function calculate(array $model, array $usage, array $plan): float
    {
        $requestCost = (float) ($model['credit_per_request'] ?? $this->settings->get('billing.credits_per_request_default', 0));
        $inputRate = (float) ($model['credit_per_1k_input'] ?? $this->settings->get('billing.credits_per_1k_input_default', 0));
        $outputRate = (float) ($model['credit_per_1k_output'] ?? $this->settings->get('billing.credits_per_1k_output_default', 0));
        $multiplier = (float) ($model['credit_multiplier'] ?? 1);
        $discount = (float) ($plan['discount_multiplier'] ?? 1);
        $input = (int) ($usage['input_tokens'] ?? 0);
        $output = (int) ($usage['output_tokens'] ?? 0);
        $cost = ($requestCost + ($input / 1000 * $inputRate) + ($output / 1000 * $outputRate)) * $multiplier * $discount;
        return round(max(0, $cost), 6);
    }
}
