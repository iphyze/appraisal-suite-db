<?php
/**
 * Batch 7b: Excel bulk French translation review.
 * Excel bytes are emitted by the existing SimpleXlsx writer, and decoded in
 * the browser. The server accepts ONLY bounded JSON with stable source hashes.
 * No staff-entered writing, names, feedback or English source is ever updated.
 */
require_once __DIR__ . '/ContentTranslationManagement.php';

const CTB_MAX_ROWS = 1000;
const CTB_MAX_EXPORT_ROWS = 20000;
const CTB_MAX_REQUEST_BYTES = 6000000;

/** PHP 8.0-compatible list check (array_is_list is PHP 8.1+). */
function ctbIsList(array $value): bool
{
    return $value === [] || array_keys($value) === range(0, count($value) - 1);
}

/** @return array<string,mixed> */
function ctbExportFilters(array $query): array
{
    $scope = $query['scope'] ?? 'missing';
    if (!is_string($scope) || !in_array($scope, ['missing', 'all'], true)) {
        throw new InvalidArgumentException('Invalid export scope.', 422);
    }
    $allowed = ['context', 'company_id', 'search'];
    $params = array_intersect_key($query, array_flip($allowed));
    $params['status'] = $scope === 'missing' ? 'missing' : 'all';
    $filters = ctmFilters($params);
    $filters['all_rows'] = true; // Internal only, never exposed by the inventory API.
    return $filters;
}

/** @return list<array<string,mixed>> */
function ctbExportRows(array $sources, array $translations, array $filters): array
{
    $result = ctmBuildInventory($sources, $translations, $filters);
    if ($result['meta']['total'] > CTB_MAX_EXPORT_ROWS) {
        throw new InvalidArgumentException('Narrow the filters before exporting (20,000 source limit).', 422);
    }
    return array_map(static function (array $row): array {
        return [
            $row['source_hash'], $row['context'], $row['source_text'],
            $row['translated_text'], $row['translation_status'],
            $row['translated_text'], implode(', ', $row['companies']),
        ];
    }, $result['rows']);
}

/** @return list<array<string,mixed>> */
function ctbIncomingRows(array $input): array
{
    $rows = $input['rows'] ?? null;
    if (!is_array($rows) || !ctbIsList($rows) || count($rows) < 1 || count($rows) > CTB_MAX_ROWS) {
        throw new InvalidArgumentException('Provide 1–1,000 translation rows per import.', 422);
    }
    return $rows;
}

/** @return array<string,array{source_text:string}> */
function ctbSourceIndex(array $sources): array
{
    $index = [];
    foreach ($sources as $source) {
        $context = $source['context'] ?? null;
        $english = $source['source_text'] ?? null;
        if (!is_string($context) || !in_array($context, ctmContexts(), true) || !is_string($english)) continue;
        $text = ctmNormalizedReadableSource($source);
        if ($text === null || $text === '') continue;
        $index[$context . '|' . contentTranslationHash($text)] = ['source_text' => $text];
    }
    return $index;
}

/** @return array<string,array<string,mixed>> */
function ctbTranslationIndex(array $translations): array
{
    $index = [];
    foreach ($translations as $tr) {
        if (($tr['locale'] ?? null) !== 'fr' || !is_string($tr['context'] ?? null)
            || !is_string($tr['source_hash'] ?? null)) continue;
        $key = $tr['context'] . '|' . $tr['source_hash'];
        // A damaged legacy translation must not put invalid bytes into the
        // preview JSON. Block bulk changes to it as a conflict for manual repair.
        $invalidColumn = null;
        foreach (['source_text', 'translated_text'] as $column) {
            if (!is_string($tr[$column] ?? null) || preg_match('//u', $tr[$column]) !== 1) {
                $invalidColumn = $column;
                break;
            }
        }
        if ($invalidColumn !== null) {
            ctmLogInvalidSource(['source_table' => 'content_translations',
                'source_column' => $invalidColumn, 'source_row_id' => $tr['id'] ?? 'unknown']);
            $index[$key] = ['id' => $tr['id'] ?? null, 'source_text' => '',
                'translated_text' => '', 'status' => 'invalid'];
            continue;
        }
        $index[$key] = $tr;
    }
    return $index;
}

/**
 * Pure preview: it is also used under a FOR UPDATE lock immediately before
 * commit. A client-supplied preview can never bypass these classification rules.
 *
 * kind: new, changed, review (same text, draft can be approved), unchanged,
 * conflict (including changed approved text), invalid, duplicate.
 * @return array{rows:list<array<string,mixed>>,counts:array<string,int>}
 */
function ctbPreviewRows(array $incoming, array $sources, array $translations): array
{
    $sourceIndex = ctbSourceIndex($sources);
    $translationIndex = ctbTranslationIndex($translations);
    $seen = [];
    $output = [];
    $counts = array_fill_keys(['new', 'changed', 'review', 'unchanged', 'conflict', 'invalid', 'duplicate'], 0);
    foreach ($incoming as $position => $raw) {
        $row = is_array($raw) ? $raw : [];
        // Incoming material is a write attempt: reject invalid UTF-8 rather
        // than silently skipping it (or returning malformed preview JSON).
        foreach (['source_text', 'translated_text', 'expected_text'] as $field) {
            if (isset($row[$field]) && is_string($row[$field])
                && preg_match('//u', $row[$field]) !== 1) {
                throw new InvalidArgumentException('Translation import contains invalid UTF-8.', 422);
            }
        }
        $result = [
            'row_number' => $row['row_number'] ?? ($position + 2),
            'context' => $row['context'] ?? '', 'source_hash' => $row['source_hash'] ?? '',
            'source_text' => $row['source_text'] ?? '', 'translated_text' => $row['translated_text'] ?? '',
            'expected_status' => $row['expected_status'] ?? '', 'expected_text' => $row['expected_text'] ?? '',
            'live_status' => 'missing', 'live_text' => '', 'kind' => 'invalid', 'reason_code' => 'invalid_row',
        ];
        $context = $result['context'];
        $hash = $result['source_hash'];
        $english = $result['source_text'];
        $french = $result['translated_text'];
        $expectedStatus = $result['expected_status'];
        $expectedText = $result['expected_text'];
        $line = $result['row_number'];
        $valid = is_int($line) && $line >= 2 && $line <= 1048576
            && is_string($context) && in_array($context, ctmContexts(), true)
            && is_string($hash) && preg_match('/^[a-f0-9]{64}$/D', $hash)
            && is_string($english) && trim($english) !== '' && strlen($english) <= 16000
            && preg_match('//u', $english) === 1
            && hash_equals(contentTranslationHash($english), $hash)
            && is_string($french) && trim($french) !== '' && strlen($french) <= 16000
            && preg_match('//u', $french) === 1
            && is_string($expectedStatus) && in_array($expectedStatus, ['missing', 'draft', 'approved'], true)
            && is_string($expectedText) && strlen($expectedText) <= 16000
            && preg_match('//u', $expectedText) === 1
            && ($expectedStatus !== 'missing' || $expectedText === '');
        if ($valid) {
            $key = $context . '|' . $hash;
            if (isset($seen[$key])) {
                $result['kind'] = 'duplicate';
                $result['reason_code'] = 'duplicate';
                // Both instances are unsafe, even if their French text agrees.
                $output[$seen[$key]]['kind'] = 'duplicate';
                $output[$seen[$key]]['reason_code'] = 'duplicate';
            } else {
                $seen[$key] = count($output);
                $original = $sourceIndex[$key]['source_text'] ?? null;
                $existing = $translationIndex[$key] ?? null;
                $liveStatus = is_array($existing) ? (string)($existing['status'] ?? 'missing') : 'missing';
                $liveText = is_array($existing) ? (string)($existing['translated_text'] ?? '') : '';
                $result['live_status'] = $liveStatus;
                $result['live_text'] = $liveText;
                // Guard even a malformed or legacy translation row where its
                // source hash was saved against different English content.
                if ($original === null || contentTranslationNormalize($english) !== $original) {
                    $result['kind'] = 'conflict'; $result['reason_code'] = 'source_changed';
                } elseif ($existing && ctmNormalizedReadableSource([
                    'source_table' => 'content_translations', 'source_column' => 'source_text',
                    'source_row_id' => $existing['id'] ?? 'unknown',
                    'source_text' => (string)($existing['source_text'] ?? ''),
                ]) !== $original) {
                    $result['kind'] = 'conflict'; $result['reason_code'] = 'source_changed';
                } elseif ($liveStatus !== $expectedStatus || $liveText !== $expectedText) {
                    $result['kind'] = 'conflict'; $result['reason_code'] = 'revision_changed';
                } elseif ($liveStatus === 'missing') {
                    $result['kind'] = 'new'; $result['reason_code'] = 'new';
                } elseif ($french === $liveText) {
                    $result['kind'] = $liveStatus === 'draft' ? 'review' : 'unchanged';
                    $result['reason_code'] = $result['kind'];
                } elseif ($liveStatus === 'approved') {
                    $result['kind'] = 'conflict'; $result['reason_code'] = 'approved_change';
                } else {
                    $result['kind'] = 'changed'; $result['reason_code'] = 'changed';
                }
            }
        }
        $output[] = $result;
    }
    foreach ($output as $row) $counts[$row['kind']]++;
    return ['rows' => $output, 'counts' => $counts];
}

/** @return array{action:string,reason:string,rows:list<array<string,mixed>>} */
function ctbValidateCommit(array $body): array
{
    $rows = ctbIncomingRows($body);
    $action = $body['action'] ?? null;
    $reason = $body['reason'] ?? null;
    if (!in_array($action, ['draft', 'approve'], true)) {
        throw new InvalidArgumentException('Invalid bulk review action.', 422);
    }
    if (!is_string($reason) || strlen(trim($reason)) < 5 || strlen($reason) > 500
        || preg_match('//u', $reason) !== 1) {
        throw new InvalidArgumentException('A Super Admin approval reason of 5–500 characters is required.', 422);
    }
    return ['action' => $action, 'reason' => trim($reason), 'rows' => $rows];
}

/**
 * SELECT ... FOR UPDATE for submitted hashes, in chunks to keep placeholders
 * bounded. Unique hash+locale+context also handles a simultaneous new INSERT.
 * @return list<array<string,mixed>>
 */
function ctbLockTranslations(mysqli $conn, array $rows): array
{
    $hashes = [];
    foreach ($rows as $row) {
        if (is_array($row) && is_string($row['source_hash'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/D', $row['source_hash'])) $hashes[$row['source_hash']] = $row['source_hash'];
    }
    $records = [];
    $sortedHashes = array_values($hashes);
    sort($sortedHashes, SORT_STRING); // Acquire locks in a stable order.
    foreach (array_chunk($sortedHashes, 200) as $chunk) {
        $sql = 'SELECT id, source_hash, source_text, locale, context, translated_text, status '
            . 'FROM content_translations WHERE locale = \'fr\' AND source_hash IN ('
            . implode(',', array_fill(0, count($chunk), '?')) . ') FOR UPDATE';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(str_repeat('s', count($chunk)), ...$chunk);
        try {
            $stmt->execute();
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $entry) $records[] = $entry;
        } finally { $stmt->close(); }
    }
    return $records;
}

/** @return array{saved:int,approved:int,action:string} */
function ctbCommit(mysqli $conn, array $actor, array $body): array
{
    $payload = ctbValidateCommit($body);
    $sourceRows = ctmReadSources($conn); // Before transaction, no extra queries per text.
    $conn->begin_transaction();
    try {
        $current = ctbLockTranslations($conn, $payload['rows']);
        $preview = ctbPreviewRows($payload['rows'], $sourceRows, $current);
        $allowed = $payload['action'] === 'approve' ? ['new', 'changed', 'review'] : ['new', 'changed'];
        foreach ($preview['rows'] as $row) {
            if (!in_array($row['kind'], $allowed, true)) {
                throw new InvalidArgumentException('The import has conflicts or outdated rows. Preview the workbook again before applying.', 409);
            }
        }
        $originals = ctbTranslationIndex($current);
        $actorId = (int)$actor['id'];
        $finalStatus = $payload['action'] === 'approve' ? 'approved' : 'draft';
        $auditAction = $payload['action'] === 'approve' ? 'approve_imported_content_translation' : 'import_content_translation_draft';
        foreach ($preview['rows'] as $row) {
            $key = $row['context'] . '|' . $row['source_hash'];
            $existing = $originals[$key] ?? null;
            $english = contentTranslationNormalize($row['source_text']);
            $french = trim($row['translated_text']);
            $locale = 'fr';
            if (!$existing) {
                $stmt = $conn->prepare("INSERT INTO content_translations (source_hash, source_text, locale, context, translated_text, status, origin, updated_by) VALUES (?, ?, ?, ?, ?, ?, 'import', ?)");
                $stmt->bind_param('ssssssi', $row['source_hash'], $english, $locale, $row['context'], $french, $finalStatus, $actorId);
                $stmt->execute();
                $id = (int)$conn->insert_id;
            } else {
                $id = (int)$existing['id'];
                $stmt = $conn->prepare("UPDATE content_translations SET translated_text = ?, status = ?, origin = 'import', updated_by = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?");
                $stmt->bind_param('ssii', $french, $finalStatus, $actorId, $id);
                $stmt->execute();
            }
            $stmt->close();
            $description = 'French ' . $row['context'] . ' bulk translation ' . $finalStatus
                . ' (source ' . $row['source_hash'] . '). Reason: ' . $payload['reason'];
            $stmt = $conn->prepare("INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description) VALUES (NULL, ?, ?, 'content_translations', ?, ?)");
            $stmt->bind_param('isis', $actorId, $auditAction, $id, $description);
            $stmt->execute();
            $stmt->close();
        }
        $conn->commit();
        contentTranslationResetRequestCache();
        return ['saved' => count($preview['rows']), 'approved' => $finalStatus === 'approved' ? count($preview['rows']) : 0, 'action' => $payload['action']];
    } catch (Throwable $e) {
        $conn->rollback();
        if ($e instanceof mysqli_sql_exception && in_array((int)$e->getCode(), [1062, 1205, 1213], true)) {
            throw new InvalidArgumentException('Another reviewer changed these translations. Reimport and preview again.', 409);
        }
        throw $e;
    }
}
