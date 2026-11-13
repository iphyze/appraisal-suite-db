<?php
/**
 * Batch 11: read-only, authenticated lookup of a specific author's text in an
 * appraisal. Never accept arbitrary text from the browser for translation.
 * The normal appraisal detail route shares the same read authorization helper.
 */
require_once __DIR__ . '/AppraisalViewAuthorization.php';
require_once __DIR__ . '/ContentTranslation.php';
require_once __DIR__ . '/AppraisalFreeTextRules.php';

/**
 * Authorize first; then read only the exact submitted source field. Never use
 * translated response fields or user input as a replacement for source data.
 */
function appraisalFreeTextSource(mysqli $conn, array $user, int $appraisalId, string $field, int $recordId): string
{
    $spec = appraisalFreeTextFieldSql($field);
    if ($appraisalId <= 0 || !$spec || $recordId < 0 || ($spec['key'] !== 'appraisal' && $recordId <= 0)) {
        throw new Exception('Invalid appraisal translation selection.', 422);
    }
    if ($spec['key'] === 'appraisal' && $recordId !== 0) {
        throw new Exception('Invalid appraisal translation selection.', 422);
    }
    // Keep the same computed supervisor-management and company configuration
    // as /appraisals/single, so the shared authorization helper is equivalent.
    $actor = (int)$user['id'];
    $canAppraise = userCanConductAppraisals($user) ? 1 : 0;
    $stmt = $conn->prepare("SELECT ap.id, ap.company_id, ap.cycle_id, ap.staff_user_id, ap.staff_type,
        ap.supervisor_id, COALESCE(acs.management_review_enabled, 0) AS management_review_enabled,
        CASE WHEN ap.supervisor_id = ? AND ? = 1
            AND EXISTS (SELECT 1 FROM supervisor_assignments sa WHERE sa.cycle_id = ap.cycle_id
                AND sa.staff_id = ap.staff_user_id AND sa.supervisor_id = ap.supervisor_id)
            AND EXISTS (SELECT 1 FROM supervisor_onboarding so WHERE so.cycle_id = ap.cycle_id
                AND so.supervisor_id = ap.supervisor_id)
            THEN 1 ELSE 0 END AS can_manage_appraisal
        FROM appraisals ap
        LEFT JOIN appraisal_cycle_settings acs ON acs.cycle_id = ap.cycle_id AND acs.company_id = ap.company_id
        WHERE ap.id = ? LIMIT 1");
    if (!$stmt) throw new Exception('Unable to retrieve the appraisal.', 503);
    $stmt->bind_param('iii', $actor, $canAppraise, $appraisalId);
    $stmt->execute();
    $appraisal = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$appraisal) throw new Exception('Appraisal not found.', 404);
    appraisalAssertViewAuthorized($conn, $user, $appraisal);
    if (!appraisalFreeTextCanRead($appraisal, $user, $field)) {
        throw new Exception('This note is not accessible.', 403);
    }

    $table = $spec['table'];
    $column = $spec['column'];
    if ($field === 'management_response') {
        $sql = "SELECT r.response AS original FROM appraisal_management_follow_up_responses r
                INNER JOIN appraisal_management_reviews m ON m.id = r.management_review_id
                WHERE m.appraisal_id = ? AND r.id = ? LIMIT 1";
    } else {
        $sql = "SELECT {$column} AS original FROM {$table} WHERE appraisal_id = ? AND {$spec['key']} = ? LIMIT 1";
        if ($spec['key'] === 'appraisal') {
            $sql = "SELECT {$column} AS original FROM appraisals WHERE id = ? LIMIT 1";
        }
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception('Unable to retrieve the note.', 503);
    if ($spec['key'] === 'appraisal') $stmt->bind_param('i', $appraisalId);
    else $stmt->bind_param('ii', $appraisalId, $recordId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $source = $result['original'] ?? null;
    if (!is_string($source) || trim($source) === '') throw new Exception('Note not found.', 404);
    if (!preg_match('//u', $source) || strlen($source) > 18000 || (function_exists('mb_strlen') ? mb_strlen($source) : preg_match_all('/./us', $source)) > 5000) {
        throw new Exception('This note is too long to translate.', 422);
    }
    return $source;
}

/** Validate source hash and approval, including the free_text context. */
function appraisalFreeTextCachedTranslation(mysqli $conn, string $source, string $target): ?string
{
    $hash = contentTranslationHash($source);
    $context = 'free_text';
    $stmt = $conn->prepare("SELECT source_text, translated_text, status FROM content_translations
        WHERE source_hash = ? AND locale = ? AND context = ? AND status = 'approved' LIMIT 1");
    if (!$stmt) throw new Exception('Translations are unavailable.', 503);
    $stmt->bind_param('sss', $hash, $target, $context);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || trim((string)($row['translated_text'] ?? '')) === ''
        || contentTranslationNormalize((string)$row['source_text']) !== contentTranslationNormalize($source)) return null;
    return (string)$row['translated_text'];
}

/** Cache only as approved AI output; never overwrite existing human work. */
function appraisalFreeTextRemember(mysqli $conn, string $source, string $target, string $translated): bool
{
    $hash = contentTranslationHash($source);
    $context = 'free_text';
    $stmt = $conn->prepare("INSERT IGNORE INTO content_translations
        (source_hash, source_text, locale, context, translated_text, status, origin, updated_by)
        VALUES (?, ?, ?, ?, ?, 'approved', 'ai', NULL)");
    if (!$stmt) throw new Exception('Translations are unavailable.', 503);
    $stmt->bind_param('sssss', $hash, $source, $target, $context, $translated);
    $stmt->execute();
    $inserted = $stmt->affected_rows === 1;
    $stmt->close();
    contentTranslationResetRequestCache();
    return $inserted;
}
