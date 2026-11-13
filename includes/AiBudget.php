<?php
/**
 * Company-wide monthly AI token budget and usage reporting.
 * Months are calendar months in UTC, matching ai_usage_events.created_at.
 * If migration 17 has not been applied, there is simply no budget (previous behaviour).
 */

function aiBudgetSettings(mysqli $conn): array
{
    try {
        $result = $conn->query('SELECT monthly_token_limit, warn_percent, updated_at FROM ai_budget_settings WHERE id = 1');
        $row = $result ? $result->fetch_assoc() : null;
        if ($result) $result->free();
        if ($row) {
            return ['limit' => (int)$row['monthly_token_limit'], 'warn_percent' => (int)$row['warn_percent'],
                'updated_at' => $row['updated_at'], 'available' => true];
        }
    } catch (Throwable $e) {
        error_log('[AppraisalSuite AI] Budget settings unavailable: ' . get_class($e));
    }
    return ['limit' => 0, 'warn_percent' => 80, 'updated_at' => null, 'available' => false];
}

/** Tokens used this calendar month (UTC) across the whole installation. */
function aiBudgetMonthUsage(mysqli $conn): array
{
    $start = gmdate('Y-m-01 00:00:00');
    $stmt = $conn->prepare("SELECT COALESCE(SUM(input_tokens), 0) AS input_tokens,
        COALESCE(SUM(output_tokens), 0) AS output_tokens, COUNT(*) AS requests
        FROM ai_usage_events WHERE created_at >= ?");
    $stmt->bind_param('s', $start);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $input = (int)($row['input_tokens'] ?? 0);
    $output = (int)($row['output_tokens'] ?? 0);
    return ['input_tokens' => $input, 'output_tokens' => $output, 'tokens' => $input + $output,
        'requests' => (int)($row['requests'] ?? 0), 'since' => $start];
}

/** Summary used by the settings page, status endpoints and the request guard. */
function aiBudgetStatus(mysqli $conn): array
{
    $settings = aiBudgetSettings($conn);
    try {
        $usage = aiBudgetMonthUsage($conn);
    } catch (Throwable $e) {
        error_log('[AppraisalSuite AI] Budget usage unavailable: ' . get_class($e));
        $usage = ['input_tokens' => 0, 'output_tokens' => 0, 'tokens' => 0, 'requests' => 0, 'since' => gmdate('Y-m-01 00:00:00')];
    }
    $limit = $settings['limit'];
    $percent = $limit > 0 ? min(100, (int)floor($usage['tokens'] * 100 / $limit)) : 0;
    return [
        'limit' => $limit,
        'used' => $usage['tokens'],
        'input_tokens' => $usage['input_tokens'],
        'output_tokens' => $usage['output_tokens'],
        'requests' => $usage['requests'],
        'remaining' => $limit > 0 ? max(0, $limit - $usage['tokens']) : null,
        'percent' => $percent,
        'warn_percent' => $settings['warn_percent'],
        'warning' => $limit > 0 && $percent >= $settings['warn_percent'],
        'reached' => $limit > 0 && $usage['tokens'] >= $limit,
        'resets_on' => gmdate('Y-m-01', strtotime('first day of next month')),
        'configured' => $settings['available'],
    ];
}

/** Stop new token-using requests once the month's budget is used up. */
function aiRequireBudget(mysqli $conn): void
{
    $status = aiBudgetStatus($conn);
    if ($status['reached']) {
        $date = gmdate('j F', strtotime($status['resets_on']));
        throw new Exception("This month's AI budget has been used up. AI will be available again on {$date}, or sooner if the Super Admin raises the budget.", 429);
    }
}

/** Tokens and requests per feature this month, for the settings page. */
function aiBudgetUsageByFeature(mysqli $conn): array
{
    $start = gmdate('Y-m-01 00:00:00');
    try {
        $stmt = $conn->prepare("SELECT COALESCE(feature_key, 'other') AS feature_key,
            COALESCE(SUM(input_tokens + output_tokens), 0) AS tokens, COUNT(*) AS requests
            FROM ai_usage_events WHERE created_at >= ? GROUP BY COALESCE(feature_key, 'other') ORDER BY tokens DESC");
        $stmt->bind_param('s', $start);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (Throwable $e) {
        // feature_key column is added by migration 17.
        return [];
    }
    return array_map(static fn($r) => ['feature' => (string)$r['feature_key'], 'tokens' => (int)$r['tokens'], 'requests' => (int)$r['requests']], $rows);
}

/** Optional cost estimate; only shown when prices are configured in .env (USD per million tokens). */
function aiBudgetCostEstimate(int $inputTokens, int $outputTokens): ?float
{
    $in = (float)aiEnv('AI_PRICE_INPUT_PER_MILLION', '0');
    $out = (float)aiEnv('AI_PRICE_OUTPUT_PER_MILLION', '0');
    if ($in <= 0 && $out <= 0) return null;
    return round(($inputTokens * $in + $outputTokens * $out) / 1000000, 2);
}

/** Pure validation for a budget change. */
function aiValidateBudgetChange(string $role, $limit, $warnPercent, $reason): array
{
    if ($role !== 'super_admin') throw new Exception('Only Super Admin can manage the AI budget.', 403);
    if (!is_int($limit) || $limit < 0 || $limit > 1000000000) throw new Exception('Enter a monthly limit between 0 and 1,000,000,000 tokens (0 means no limit).', 422);
    if (!is_int($warnPercent) || $warnPercent < 50 || $warnPercent > 95) throw new Exception('The warning level must be between 50% and 95%.', 422);
    if (!is_string($reason) || !preg_match('//u', $reason) || strlen(trim($reason)) < 5 || strlen(trim($reason)) > 500) {
        throw new Exception('Enter a reason between 5 and 500 characters.', 422);
    }
    return [$limit, $warnPercent, trim($reason)];
}
