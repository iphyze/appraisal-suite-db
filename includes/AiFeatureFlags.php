<?php
/** Global Super Admin AI feature controls. Never grant access from client-side flags. */
require_once __DIR__ . '/AiFoundation.php';

/**
 * Every AI feature, released or planned.
 * - released:    false means the switch is shown but cannot be turned on yet.
 * - uses_tokens: false features run inside AppraisalSuite only. They are not
 *                affected by the master switch, the server AI key or the budget.
 * - requires:    another feature that must also be on.
 */
function aiFeatureCatalog(): array
{
    return [
        'master' => ['label' => 'AI Platform', 'released' => true, 'uses_tokens' => true, 'group' => 'platform',
            'description' => 'Master switch for every AI feature that uses OpenAI. Features that run inside AppraisalSuite keep working.'],

        'assistant' => ['label' => 'Appraisal Assistant', 'released' => true, 'uses_tokens' => true, 'group' => 'available',
            'description' => 'Answers questions and looks up appraisals each person is allowed to see.'],
        'ask_panel' => ['label' => 'Ask AI panel', 'released' => true, 'uses_tokens' => true, 'group' => 'available', 'requires' => 'assistant',
            'description' => 'An Ask AI button in the top bar that opens the Assistant beside any page.'],
        'management_insights' => ['label' => 'Management Insights', 'released' => true, 'uses_tokens' => true, 'group' => 'available',
            'description' => 'Turns cycle statistics into observations and actions. Results are saved and reused.'],
        'content_translation' => ['label' => 'Translate appraisal comments', 'released' => true, 'uses_tokens' => true, 'group' => 'available',
            'description' => 'On-demand AI translation of authorised staff feedback and management notes. Originals stay unchanged; cached translations reuse no tokens.'],
        'writing_assistant' => ['label' => 'Writing Assistant', 'released' => true, 'uses_tokens' => true, 'group' => 'available',
            'description' => 'Helps supervisors and staff turn notes into clear comments and feedback.'],
        'development_plan' => ['label' => 'Development Plan', 'released' => true, 'uses_tokens' => true, 'group' => 'available',
            'description' => 'Drafts a development objective, actions and review measures.'],
        'setup_assistant' => ['label' => 'Setup Assistant', 'released' => true, 'uses_tokens' => true, 'group' => 'available',
            'description' => 'Suggests wording when creating cycles, sections, KPI and general questions.'],

        'rankings' => ['label' => 'Totals and rankings', 'released' => true, 'uses_tokens' => false, 'group' => 'available',
            'description' => 'Ranks departments, supervisors and levels, and compares ratings with the guideline spread. Also lets the Assistant answer "which department scored highest".'],
        'calibration' => ['label' => 'Supervisor calibration', 'released' => true, 'uses_tokens' => false, 'group' => 'available',
            'description' => 'Shows supervisors who rate much higher or lower than the company average.'],
        'alerts' => ['label' => 'Performance alerts', 'released' => true, 'uses_tokens' => false, 'group' => 'available',
            'description' => 'Flags big score drops, people rated Outstanding three years running, and scores below Fully Meets.'],
        'progress' => ['label' => 'Progress tracker', 'released' => true, 'uses_tokens' => false, 'group' => 'available',
            'description' => 'Shows who still needs a level, objectives, an appraisal or an acknowledgement. Supervisors see their own team.'],
        'smart_search' => ['label' => 'Plain-English search', 'released' => true, 'uses_tokens' => false, 'group' => 'available',
            'description' => 'Lets the top search bar understand requests like "pending acknowledgements in Procurement".'],
        'fairness_check' => ['label' => 'Fairness check before submit', 'released' => true, 'uses_tokens' => false, 'token_mode' => 'optional', 'group' => 'available',
            'description' => 'Before an appraisal is submitted, flags missing evidence, identical ratings and recommendations that do not fit the score. The rule checks are free; the optional AI wording check uses tokens.'],
        'cycle_briefing' => ['label' => 'Cycle briefing', 'released' => true, 'uses_tokens' => true, 'group' => 'available',
            'description' => 'A one-page management briefing for a cycle, written from the Analytics figures. Saved, so reopening it is free.'],
        'feedback_summary' => ['label' => 'Feedback summaries', 'released' => true, 'uses_tokens' => true, 'group' => 'available',
            'description' => 'Groups what employees wrote when acknowledging into themes and flags disagreements. Names are not sent to OpenAI.'],
        'explain_appraisal' => ['label' => 'Explain this appraisal', 'released' => true, 'uses_tokens' => true, 'group' => 'available',
            'description' => 'A plain-language explanation of an appraisal result for the employee or reviewer. Saved per version.'],
        'reminder_drafts' => ['label' => 'Reminder drafts', 'released' => true, 'uses_tokens' => true, 'group' => 'available',
            'description' => 'Drafts reminder emails from the Progress tracker, ready to send from Send Email.'],
        'kpi_suggestions' => ['label' => 'KPI suggestions', 'released' => true, 'uses_tokens' => true, 'group' => 'available',
            'description' => 'Suggests measurable targets and evidence inside the objective plan.'],
    ];
}

/** Features that existed before migration 17 must have a stored setting. */
function aiFeatureCoreKeys(): array
{
    return ['master', 'assistant', 'management_insights', 'writing_assistant', 'development_plan'];
}

function aiConfigured(): bool
{
    return aiEnabled() && aiEnv('OPENAI_API_KEY') !== '' && function_exists('curl_init');
}

/** Always fail closed: a missing core setting stops AI; a missing newer setting only stops that feature. */
function aiReadFeatureFlags(mysqli $conn): array
{
    try {
        $result = $conn->query('SELECT feature_key, is_enabled FROM ai_feature_settings');
        if (!$result) throw new RuntimeException('Cannot read AI settings');
        $values = [];
        while ($row = $result->fetch_assoc()) $values[(string)$row['feature_key']] = (int)$row['is_enabled'] === 1;
        $result->free();
        foreach (array_keys(aiFeatureCatalog()) as $key) {
            if (array_key_exists($key, $values)) continue;
            if (in_array($key, aiFeatureCoreKeys(), true)) throw new RuntimeException('Missing AI feature setting');
            $values[$key] = false;
        }
        return $values;
    } catch (Throwable $e) {
        error_log('[AppraisalSuite AI] Feature settings unavailable: ' . get_class($e));
        throw new Exception('AI feature settings are unavailable. Apply migration 14.', 503);
    }
}

function aiFeatureUsesTokens(string $feature): bool
{
    $catalog = aiFeatureCatalog();
    return !isset($catalog[$feature]) || !empty($catalog[$feature]['uses_tokens']);
}

function aiFeatureEnabled(array $settings, string $feature): bool
{
    $catalog = aiFeatureCatalog();
    if (!isset($catalog[$feature]) || !$catalog[$feature]['released'] || empty($settings[$feature])) return false;
    $requires = $catalog[$feature]['requires'] ?? null;
    if ($requires !== null && !aiFeatureEnabled($settings, $requires)) return false;
    // Features that never call OpenAI are not affected by the master switch.
    if (empty($catalog[$feature]['uses_tokens'])) return true;
    return !empty($settings['master']);
}

function aiRequireFeature(mysqli $conn, string $feature): void
{
    if (aiFeatureUsesTokens($feature) && !aiConfigured()) {
        throw new Exception('AI is not configured or is disabled on the server.', 503);
    }
    $settings = aiReadFeatureFlags($conn);
    if (!aiFeatureEnabled($settings, $feature)) throw new Exception('This AI functionality has been disabled by the Super Admin.', 403);
}

function aiPublicAvailability(mysqli $conn, string $feature = 'assistant'): array
{
    $settings = aiReadFeatureFlags($conn);
    $catalog = aiFeatureCatalog();
    $needsServer = aiFeatureUsesTokens($feature);
    return [
        'enabled' => (!$needsServer || aiConfigured()) && aiFeatureEnabled($settings, $feature),
        'feature_enabled' => aiFeatureEnabled($settings, $feature),
        'server_ready' => aiConfigured(),
        'feature' => $feature,
        'released' => !empty($catalog[$feature]['released']),
    ];
}

/** Menu and top-bar presentation only; AI operations retain their own server-side gates. */
function aiNavigationAvailability(array $settings, bool $serverReady, string $role): array
{
    $items = ['assistant', 'ask_panel'];
    if (in_array($role, ['admin', 'super_admin'], true)) $items[] = 'management_insights';
    $result = [];
    // Analytics page: shown when any of its views is on for this role (no tokens needed).
    $views = in_array($role, ['admin', 'super_admin'], true) ? ['rankings', 'calibration', 'alerts', 'progress', 'cycle_briefing', 'feedback_summary']
        : ($role === 'supervisor' ? ['progress'] : []);
    if ($views) {
        $on = array_values(array_filter($views, static fn($f) => aiFeatureEnabled($settings, $f)
            && (!aiFeatureUsesTokens($f) || $serverReady)));
        $result['analytics'] = ['available' => (bool)$on, 'views' => $on, 'reason' => $on ? '' : 'Disabled by Super Admin'];
    }
    foreach ($items as $feature) {
        $featureEnabled = aiFeatureEnabled($settings, $feature);
        $needsServer = aiFeatureUsesTokens($feature);
        $available = (!$needsServer || $serverReady) && $featureEnabled;
        $result[$feature] = [
            'available' => $available,
            'reason' => ($needsServer && !$serverReady) ? 'AI server unavailable' : ($featureEnabled ? '' : 'Disabled by Super Admin'),
        ];
    }
    // Buttons inside pages (each endpoint re-checks permissions and switches).
    $tokensReady = $serverReady && !empty($settings['master']);
    $result['tools'] = [
        'smart_search' => aiFeatureEnabled($settings, 'smart_search'),
        'fairness_check' => aiFeatureEnabled($settings, 'fairness_check') && in_array($role, ['supervisor', 'admin'], true),
        'fairness_ai' => aiFeatureEnabled($settings, 'fairness_check') && $tokensReady && in_array($role, ['supervisor', 'admin'], true),
        'explain_appraisal' => aiFeatureEnabled($settings, 'explain_appraisal') && $tokensReady,
        'reminder_drafts' => aiFeatureEnabled($settings, 'reminder_drafts') && $tokensReady && in_array($role, ['admin', 'super_admin'], true),
    ];
    return $result;
}

/** Pure validation suitable for unit tests and shared by update handler. */
function aiValidateFeatureChange(string $role, $feature, $enabled, $reason): array
{
    if ($role !== 'super_admin') throw new Exception('Only Super Admin can manage AI functionality.', 403);
    $catalog = aiFeatureCatalog();
    if (!is_string($feature) || !array_key_exists($feature, $catalog)) throw new Exception('Invalid AI feature.', 422);
    if (!is_bool($enabled)) throw new Exception('enabled must be true or false.', 422);
    if ($enabled && !$catalog[$feature]['released']) throw new Exception('This functionality has not been released yet.', 422);
    if (!is_string($reason) || !preg_match('//u', $reason) || strlen(trim($reason)) < 5 || strlen(trim($reason)) > 500) {
        throw new Exception('Enter a reason between 5 and 500 characters.', 422);
    }
    return [$feature, (int)$enabled, trim($reason)];
}
