<?php
/**
 * Super Admin translation workbench. Batch 7a: discover content currently in
 * use, review draft/approved records, and save human translations with audit.
 *
 * The source catalog is intentionally explicit. NEVER add comments, evidence,
 * feedback, notes, names, codes, user-authored targets, or free-text here.
 * Hashes and contexts must match the Batch 6 read transformers exactly.
 */
require_once __DIR__ . '/ContentTranslation.php';

/** @return list<array{table:string,from:string,company:string,column:string,context:string}> */
function ctmSourceSpecifications(): array
{
    return [
        ['table' => 'general_questions', 'from' => 'general_questions g', 'company' => 'g.company_id', 'column' => 'g.question_text', 'context' => 'question'],
        ['table' => 'general_questions', 'from' => 'general_questions g', 'company' => 'g.company_id', 'column' => 'g.question_label', 'context' => 'question'],
        ['table' => 'general_questions', 'from' => 'general_questions g', 'company' => 'g.company_id', 'column' => 'g.anchor_low_text', 'context' => 'anchor'],
        ['table' => 'general_questions', 'from' => 'general_questions g', 'company' => 'g.company_id', 'column' => 'g.anchor_mid_text', 'context' => 'anchor'],
        ['table' => 'general_questions', 'from' => 'general_questions g', 'company' => 'g.company_id', 'column' => 'g.anchor_high_text', 'context' => 'anchor'],
        ['table' => 'kpi_questions', 'from' => 'kpi_questions k', 'company' => 'k.company_id', 'column' => 'k.question_text', 'context' => 'question'],
        ['table' => 'appraisal_sections', 'from' => 'appraisal_sections s', 'company' => 's.company_id', 'column' => 's.label', 'context' => 'section'],
        ['table' => 'appraisal_sections', 'from' => 'appraisal_sections s', 'company' => 's.company_id', 'column' => 's.description', 'context' => 'section'],
        ['table' => 'appraisal_rating_points', 'from' => 'appraisal_rating_points r', 'company' => 'r.company_id', 'column' => 'r.label', 'context' => 'rating_label'],
        ['table' => 'appraisal_rating_points', 'from' => 'appraisal_rating_points r', 'company' => 'r.company_id', 'column' => 'r.definition', 'context' => 'rating_label'],
        ['table' => 'appraisal_rating_points', 'from' => 'appraisal_rating_points r', 'company' => 'r.company_id', 'column' => 'r.evidence_requirement', 'context' => 'rating_label'],
        ['table' => 'appraisal_rating_bands', 'from' => 'appraisal_rating_bands b', 'company' => 'b.company_id', 'column' => 'b.label', 'context' => 'rating_band'],
        ['table' => 'appraisal_scoring_profiles', 'from' => 'appraisal_scoring_profiles p', 'company' => 'p.company_id', 'column' => 'p.label', 'context' => 'scoring_profile'],
        ['table' => 'appraisal_scoring_profiles', 'from' => 'appraisal_scoring_profiles p', 'company' => 'p.company_id', 'column' => 'p.description', 'context' => 'scoring_profile'],
        ['table' => 'departments', 'from' => 'departments d', 'company' => 'd.company_id', 'column' => 'd.name', 'context' => 'department'],
        ['table' => 'appraisal_cycles', 'from' => 'appraisal_cycles c', 'company' => 'c.company_id', 'column' => 'c.title', 'context' => 'cycle_title'],
        ['table' => 'users', 'from' => 'users u', 'company' => 'u.company_id', 'column' => 'u.job_title', 'context' => 'job_title'],
        // Frozen, database-authored content must also be discoverable even if
        // its setup entry was subsequently changed or deleted.
        ['table' => 'appraisals', 'from' => 'appraisals a', 'company' => 'a.company_id', 'column' => 'a.evaluation_statement', 'context' => 'evaluation_statement'],
        ['table' => 'appraisals', 'from' => 'appraisals a', 'company' => 'a.company_id', 'column' => 'a.scoring_profile_label', 'context' => 'scoring_profile'],
        ['table' => 'appraisals', 'from' => 'appraisals a', 'company' => 'a.company_id', 'column' => 'a.staff_department', 'context' => 'department'],
        ['table' => 'appraisals', 'from' => 'appraisals a', 'company' => 'a.company_id', 'column' => 'a.staff_job_title', 'context' => 'job_title'],
        ['table' => 'appraisal_revisions', 'from' => 'appraisal_revisions v', 'company' => 'v.company_id', 'column' => 'v.evaluation_statement', 'context' => 'evaluation_statement'],
        ['table' => 'appraisal_section_scores', 'from' => 'appraisal_section_scores ss JOIN appraisals a ON a.id = ss.appraisal_id', 'company' => 'a.company_id', 'column' => 'ss.section_label', 'context' => 'section'],
        ['table' => 'appraisal_section_responses', 'from' => 'appraisal_section_responses sr JOIN appraisals a ON a.id = sr.appraisal_id', 'company' => 'a.company_id', 'column' => 'sr.question_text', 'context' => 'question'],
        ['table' => 'appraisal_kpi_responses', 'from' => 'appraisal_kpi_responses kr JOIN appraisals a ON a.id = kr.appraisal_id', 'company' => 'a.company_id', 'column' => 'kr.question_text', 'context' => 'question'],
    ];
}

/** @return list<string> */
function ctmContexts(): array
{
    return ['question', 'anchor', 'section', 'rating_label', 'rating_band', 'scoring_profile', 'department', 'job_title', 'cycle_title', 'evaluation_statement'];
}

/**
 * SQL identifiers exclusively come from ctmSourceSpecifications(), not input.
 * DISTINCT reduces repeated frozen content before it reaches PHP. PHP still
 * normalizes NFC and whitespace, which SQL/MariaDB cannot do equivalently.
 */
function ctmSourcesSql(int $companyId): string
{
    $clauses = [];
    foreach (ctmSourceSpecifications() as $spec) {
        $condition = $spec['column'] . " IS NOT NULL AND TRIM(" . $spec['column'] . ") <> ''";
        if ($companyId > 0) $condition .= ' AND ' . $spec['company'] . ' = ' . $companyId;
        // All registered source tables use an `id` primary key. Keep one row
        // reference per distinct source, so a bad byte sequence is traceable
        // without logging any personal or appraisal content.
        $sourceAlias = explode('.', $spec['column'], 2)[0];
        $column = explode('.', $spec['column'], 2)[1];
        $clauses[] = 'SELECT ' . $spec['company'] . ' AS company_id, '
            . "'" . $spec['context'] . "' AS context, " . $spec['column'] . ' AS source_text, '
            . "'" . $spec['table'] . "' AS source_table, '" . $column . "' AS source_column, "
            . 'MIN(' . $sourceAlias . '.id) AS source_row_id '
            . 'FROM ' . $spec['from'] . ' WHERE ' . $condition
            . ' GROUP BY ' . $spec['company'] . ', ' . $spec['column'];
    }
    return implode(' UNION ALL ', $clauses);
}

/**
 * Invalid legacy bytes are never trusted as translation sources. A failure
 * logs *where* the content was found, not its value (which may be sensitive).
 */
function ctmLogInvalidSource(array $row): void
{
    $table = (string)($row['source_table'] ?? 'unknown');
    $column = (string)($row['source_column'] ?? 'unknown');
    $reference = (string)($row['source_row_id'] ?? 'unknown');
    error_log('Translations inventory skipped invalid UTF-8: table=' . $table
        . ' column=' . $column . ' row=' . $reference);
}

/** Validates database *reads* only. Do not use for edits or imports. */
function ctmNormalizedReadableSource(array $row): ?string
{
    $value = $row['source_text'] ?? null;
    if (!is_string($value)) return null;
    try {
        return contentTranslationNormalize($value);
    } catch (InvalidArgumentException $e) {
        ctmLogInvalidSource($row);
        return null;
    }
}

/**
 * Parse only known database-authored labels from saved JSON snapshots.
 * Do not walk JSON recursively: its comments, reasons and staff-entered fields
 * must never be included in the translation queue.
 * @return list<array{company_id:int,context:string,source_text:string}>
 */
function ctmSnapshotSources(
    int $companyId,
    string $kind,
    string $json,
    string $table = 'snapshot',
    string $snapshotColumn = 'json',
    string $rowReference = 'unknown'
): array {
    if (preg_match('//u', $json) !== 1) {
        ctmLogInvalidSource(['source_table' => $table, 'source_column' => $snapshotColumn,
            'source_row_id' => $rowReference]);
        return [];
    }
    $parsed = json_decode($json, true);
    if (!is_array($parsed)) return [];
    $fields = [];
    if ($kind === 'rating_config') {
        $fields = [
            ['key' => 'rating_bands', 'columns' => ['label' => 'rating_band']],
            ['key' => 'rating_points', 'columns' => ['label' => 'rating_label',
                'definition' => 'rating_label', 'evidence_requirement' => 'rating_label']],
        ];
    } elseif ($kind === 'revision') {
        $fields = [['key' => null, 'columns' => ['section_label' => 'section']]];
    } else return [];
    $rows = [];
    foreach ($fields as $set) {
        $parts = $set['key'] === null ? $parsed : ($parsed[$set['key']] ?? []);
        if (!is_array($parts)) continue;
        foreach ($parts as $index => $part) {
            if (!is_array($part)) continue;
            foreach ($set['columns'] as $column => $context) {
                $text = $part[$column] ?? null;
                if (!is_string($text) || trim($text) === '') continue;
                $path = ($set['key'] === null ? '' : $set['key'] . '.') . $index . '.' . $column;
                $reference = $rowReference . ':' . $path;
                $source = ['source_table' => $table, 'source_column' => $snapshotColumn,
                    'source_row_id' => $reference, 'source_text' => $text];
                if (ctmNormalizedReadableSource($source) === null) continue;
                $rows[] = ['company_id' => $companyId, 'context' => $context, 'source_text' => $text,
                    'source_table' => $table, 'source_column' => $snapshotColumn,
                    'source_row_id' => $reference];
            }
        }
    }
    return $rows;
}

/** @return list<array<string,mixed>> */
function ctmReadSources(mysqli $conn, int $companyId = 0): array
{
    $result = $conn->query(ctmSourcesSql($companyId));
    if (!$result) throw new RuntimeException('Unable to inspect translation sources.');
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        if (ctmNormalizedReadableSource($row) === null) continue;
        $rows[] = $row;
        if (count($rows) > 150000) {
            throw new RuntimeException('Translation source catalog is too large for this request.');
        }
    }
    $result->free();
    // JSON snapshots contain prior rating and section labels not necessarily
    // present in the live configuration or denormalized snapshot tables.
    foreach ([
        ['table' => 'appraisals', 'company' => 'company_id', 'column' => 'rating_config_snapshot', 'kind' => 'rating_config'],
        ['table' => 'appraisal_revisions', 'company' => 'company_id', 'column' => 'section_scores_json', 'kind' => 'revision'],
    ] as $spec) {
        $sql = 'SELECT id AS source_row_id, ' . $spec['company'] . ' AS company_id, ' . $spec['column'] . ' AS json_text FROM '
            . $spec['table'] . ' WHERE ' . $spec['column'] . " IS NOT NULL AND " . $spec['column'] . " <> ''";
        if ($companyId > 0) $sql .= ' AND ' . $spec['company'] . ' = ' . $companyId;
        $snapshotResult = $conn->query($sql);
        if (!$snapshotResult) throw new RuntimeException('Unable to inspect saved appraisal snapshots.');
        while ($snapshot = $snapshotResult->fetch_assoc()) {
            foreach (ctmSnapshotSources((int)$snapshot['company_id'], $spec['kind'], (string)$snapshot['json_text'],
                $spec['table'], $spec['column'], (string)$snapshot['source_row_id']) as $entry) {
                $rows[] = $entry;
                if (count($rows) > 150000) throw new RuntimeException('Translation source catalog is too large for this request.');
            }
        }
        $snapshotResult->free();
    }
    return $rows;
}

/** @return list<array<string,mixed>> */
function ctmReadTranslations(mysqli $conn): array
{
    $stmt = $conn->prepare('SELECT id, source_hash, source_text, locale, context, translated_text, status, origin, updated_by, updated_at FROM content_translations WHERE locale = ? AND context <> \'free_text\'');
    if (!$stmt) throw new RuntimeException('Unable to load translation records.');
    $locale = 'fr';
    try {
        $stmt->bind_param('s', $locale);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } finally { $stmt->close(); }
}

/**
 * Pure inventory join to allow tests without a database. Only current source
 * text is listed, so an edited English text appears as MISSING (new hash).
 * Old translations are never attached by record ID or by loose text match.
 *
 * @param list<array<string,mixed>> $sources
 * @param list<array<string,mixed>> $translations
 * @param array<string,mixed> $filters
 * @return array{rows:list<array<string,mixed>>,meta:array<string,mixed>}
 */
function ctmBuildInventory(array $sources, array $translations, array $filters): array
{
    $items = [];
    $contexts = ctmContexts();
    foreach ($sources as $row) {
        $context = $row['context'] ?? null;
        $english = $row['source_text'] ?? null;
        if (!is_string($context) || !in_array($context, $contexts, true) || !is_string($english)) continue;
        $normalized = ctmNormalizedReadableSource($row);
        if ($normalized === null || $normalized === '') continue;
        $hash = hash('sha256', $normalized);
        $key = $context . '|' . $hash;
        if (!isset($items[$key])) {
            $items[$key] = [
                'source_hash' => $hash, 'context' => $context, 'source_text' => $normalized,
                'translation_id' => null, 'translated_text' => '', 'translation_status' => 'missing',
                'origin' => null, 'updated_at' => null, 'companies' => [],
            ];
        }
        $company = (int)($row['company_id'] ?? 0);
        if ($company > 0) $items[$key]['companies'][$company] = $company;
    }
    foreach ($translations as $entry) {
        if (($entry['locale'] ?? null) !== 'fr') continue;
        $context = $entry['context'] ?? null;
        $source = $entry['source_text'] ?? null;
        $hash = $entry['source_hash'] ?? null;
        if (!is_string($context) || !is_string($source) || !is_string($hash)) continue;
        $translationRow = ['source_table' => 'content_translations', 'source_column' => 'source_text',
            'source_row_id' => $entry['id'] ?? 'unknown', 'source_text' => $source];
        $normalizedSource = ctmNormalizedReadableSource($translationRow);
        if ($normalizedSource === null) continue;
        $translated = $entry['translated_text'] ?? null;
        if (!is_string($translated) || preg_match('//u', $translated) !== 1) {
            ctmLogInvalidSource(['source_table' => 'content_translations',
                'source_column' => 'translated_text', 'source_row_id' => $entry['id'] ?? 'unknown']);
            continue;
        }
        $key = $context . '|' . $hash;
        if (!isset($items[$key])) continue;
        if (!hash_equals($items[$key]['source_hash'], hash('sha256', $normalizedSource))
            || $normalizedSource !== $items[$key]['source_text']) continue;
        if (!in_array($entry['status'] ?? '', ['draft', 'approved'], true)) continue;
        $items[$key]['translation_id'] = (int)$entry['id'];
        $items[$key]['translated_text'] = (string)($entry['translated_text'] ?? '');
        $items[$key]['translation_status'] = $entry['status'];
        $items[$key]['origin'] = $entry['origin'] ?? null;
        $items[$key]['updated_at'] = $entry['updated_at'] ?? null;
    }
    $status = (string)($filters['status'] ?? 'all');
    $contextFilter = (string)($filters['context'] ?? 'all');
    $companyFilter = (int)($filters['company_id'] ?? 0);
    $search = trim((string)($filters['search'] ?? ''));
    $allCounts = ['missing' => 0, 'draft' => 0, 'approved' => 0];
    $filtered = [];
    foreach ($items as $item) {
        if ($companyFilter > 0 && !isset($item['companies'][$companyFilter])) continue;
        if ($contextFilter !== 'all' && $item['context'] !== $contextFilter) continue;
        if ($search !== '' && stripos($item['source_text'], $search) === false
            && stripos($item['translated_text'], $search) === false) continue;
        $allCounts[$item['translation_status']]++;
        if ($status !== 'all' && $status !== $item['translation_status']) continue;
        $item['companies'] = array_values($item['companies']);
        sort($item['companies'], SORT_NUMERIC);
        $filtered[] = $item;
    }
    // Missing first, then drafts for approval, then approved; consistent pages.
    $order = ['missing' => 0, 'draft' => 1, 'approved' => 2];
    usort($filtered, static function ($a, $b) use ($order) {
        return ($order[$a['translation_status']] <=> $order[$b['translation_status']])
            ?: strcmp($a['context'], $b['context'])
            ?: strcmp($a['source_text'], $b['source_text']);
    });
    $page = max(1, (int)($filters['page'] ?? 1));
    $limit = max(1, min(100, (int)($filters['limit'] ?? 20)));
    $total = count($filtered);
    return [
        'rows' => (($filters['all_rows'] ?? false) === true)
            ? $filtered : array_slice($filtered, ($page - 1) * $limit, $limit),
        'meta' => ['page' => $page, 'limit' => $limit, 'total' => $total,
            'total_pages' => max(1, (int)ceil($total / $limit)), 'counts' => $allCounts,
            'contexts' => $contexts],
    ];
}

/** @return array<string,mixed> */
function ctmFilters(array $query): array
{
    $status = $query['status'] ?? 'all';
    $context = $query['context'] ?? 'all';
    if (!is_string($status) || !in_array($status, ['all', 'missing', 'draft', 'approved'], true)) {
        throw new InvalidArgumentException('Invalid translation status filter.', 422);
    }
    if (!is_string($context) || ($context !== 'all' && !in_array($context, ctmContexts(), true))) {
        throw new InvalidArgumentException('Invalid translation context filter.', 422);
    }
    foreach (['page', 'limit', 'company_id'] as $number) {
        if (isset($query[$number]) && (!is_scalar($query[$number]) || !preg_match('/^\d+$/D', (string)$query[$number]))) {
            throw new InvalidArgumentException('Invalid numeric filter.', 422);
        }
    }
    $search = $query['search'] ?? '';
    if (!is_string($search) || strlen($search) > 200) throw new InvalidArgumentException('Invalid search length.', 422);
    return ['status' => $status, 'context' => $context, 'company_id' => (int)($query['company_id'] ?? 0),
        'page' => max(1, (int)($query['page'] ?? 1)), 'limit' => max(1, min(100, (int)($query['limit'] ?? 20))), 'search' => trim($search)];
}

/** @param array<string,mixed> $input */
function ctmValidateChange(array $input): array
{
    $context = $input['context'] ?? null;
    $source = $input['source_text'] ?? null;
    $hash = $input['source_hash'] ?? null;
    $translation = $input['translated_text'] ?? null;
    $action = $input['action'] ?? null;
    $reason = $input['reason'] ?? null;
    if (!is_string($context) || !in_array($context, ctmContexts(), true)
        || !is_string($source) || trim($source) === '' || strlen($source) > 16000
        || preg_match('//u', $source) !== 1
        || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)
        || !hash_equals(contentTranslationHash($source), $hash)) {
        throw new InvalidArgumentException('The English source has changed. Reload translations.', 409);
    }
    if (!is_string($translation) || trim($translation) === '' || strlen($translation) > 16000
        || preg_match('//u', $translation) !== 1) {
        throw new InvalidArgumentException('A French translation is required (maximum 16 KB).', 422);
    }
    if (!in_array($action, ['draft', 'approve'], true)) throw new InvalidArgumentException('Invalid translation action.', 422);
    if (!is_string($reason) || strlen(trim($reason)) < 5 || strlen($reason) > 500
        || preg_match('//u', $reason) !== 1) {
        throw new InvalidArgumentException('An approval reason of 5–500 characters is required.', 422);
    }
    $expectedStatus = $input['expected_status'] ?? null;
    $expectedText = $input['expected_text'] ?? null;
    if (!in_array($expectedStatus, ['missing', 'draft', 'approved'], true)
        || !is_string($expectedText) || strlen($expectedText) > 16000
        || preg_match('//u', $expectedText) !== 1) {
        throw new InvalidArgumentException('Translation revision is missing. Reload translations.', 409);
    }
    if ($expectedStatus === 'missing' && $expectedText !== '') {
        throw new InvalidArgumentException('Translation revision is inconsistent.', 409);
    }
    return ['context' => $context, 'source_text' => contentTranslationNormalize($source),
        'source_hash' => $hash, 'translated_text' => trim($translation),
        'status' => $action === 'approve' ? 'approved' : 'draft', 'reason' => trim($reason),
        'expected_status' => $expectedStatus, 'expected_text' => $expectedText];
}

/**
 * Save only translations of currently used company-authored source text.
 * Server-side authorization and CSRF are handled by the route/router.
 * @return array<string,mixed>
 */
function ctmSaveTranslation(mysqli $conn, array $actor, array $input): array
{
    $data = ctmValidateChange($input);
    $inUse = false;
    foreach (ctmReadSources($conn) as $row) {
        if ($row['context'] === $data['context']
            && contentTranslationHash((string)$row['source_text']) === $data['source_hash']) {
            $inUse = true;
            break;
        }
    }
    if (!$inUse) throw new InvalidArgumentException('The English source is no longer in use. Reload translations.', 409);
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT id, source_text, translated_text, status FROM content_translations WHERE source_hash = ? AND locale = ? AND context = ? FOR UPDATE');
        $locale = 'fr';
        $stmt->bind_param('sss', $data['source_hash'], $locale, $data['context']);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ((empty($existing) && $data['expected_status'] !== 'missing')
            || (!empty($existing) && ($data['expected_status'] === 'missing'
                || $existing['status'] !== $data['expected_status']
                || $existing['translated_text'] !== $data['expected_text']
                || contentTranslationNormalize((string)$existing['source_text']) !== $data['source_text']))) {
            throw new InvalidArgumentException('This translation was edited by someone else. Reload before saving.', 409);
        }
        $actorId = (int)$actor['id'];
        if (empty($existing)) {
            $stmt = $conn->prepare('INSERT INTO content_translations (source_hash, source_text, locale, context, translated_text, status, origin, updated_by) VALUES (?, ?, ?, ?, ?, ?, \'human\', ?)');
            $stmt->bind_param('ssssssi', $data['source_hash'], $data['source_text'], $locale,
                $data['context'], $data['translated_text'], $data['status'], $actorId);
            $stmt->execute();
            $id = (int)$conn->insert_id;
        } else {
            $id = (int)$existing['id'];
            $stmt = $conn->prepare("UPDATE content_translations SET translated_text = ?, status = ?, origin = 'human', updated_by = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?");
            $stmt->bind_param('ssii', $data['translated_text'], $data['status'], $actorId, $id);
            $stmt->execute();
        }
        $stmt->close();
        // Shared globally across companies: leave company_id NULL in the audit.
        $action = $data['status'] === 'approved' ? 'approve_content_translation' : 'save_content_translation_draft';
        $description = 'French ' . $data['context'] . ' translation ' . $data['status']
            . ' (source ' . $data['source_hash'] . '). Reason: ' . $data['reason'];
        $stmt = $conn->prepare('INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description) VALUES (NULL, ?, ?, \'content_translations\', ?, ?)');
        $stmt->bind_param('isis', $actorId, $action, $id, $description);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
        contentTranslationResetRequestCache();
        return ['id' => $id, 'source_hash' => $data['source_hash'], 'context' => $data['context'],
            'translated_text' => $data['translated_text'], 'status' => $data['status']];
    } catch (Throwable $error) {
        $conn->rollback();
        if ($error instanceof mysqli_sql_exception && (int)$error->getCode() === 1062) {
            throw new InvalidArgumentException('This translation was edited by someone else. Reload before saving.', 409);
        }
        throw $error;
    }
}
