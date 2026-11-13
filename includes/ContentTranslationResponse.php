<?php
/**
 * Explicit, batched translation of approved, database-authored response fields.
 *
 * The route selects the exact paths eligible for translation. Do not scan every
 * string in the response: names, identifiers, notes, reasons and staff writing
 * must never be implicitly translated. `*` traverses existing array elements.
 * For normal paths the original value remains in `<field>_source` so editors
 * can save English even when the reader is using French.
 *
 * For identity fields used as filters (e.g. `department`), specify an `into`
 * field such as `department_display`: the original API value is not modified.
 *
 * All paths are collected before a SINGLE resolver call for this response.
 */
require_once __DIR__ . '/ContentTranslation.php';

/**
 * @param array<string,string|array{context:string,into:string}> $paths
 * @param callable(array,string):array $resolver accepts list of translation inputs, locale
 */
function contentTranslationApplyWithResolver(array &$payload, string $locale, array $paths, callable $resolver): void
{
    $requests = [];
    $targets = [];
    foreach ($paths as $path => $rule) {
        if (!is_string($path) || $path === '') {
            throw new InvalidArgumentException('Invalid translation response field path.');
        }
        $context = is_array($rule) ? ($rule['context'] ?? null) : $rule;
        $into = is_array($rule) ? ($rule['into'] ?? null) : null;
        if (!is_string($context) || $context === '') {
            throw new InvalidArgumentException('Invalid translation response field context.');
        }
        if ($into !== null && (!is_string($into) || preg_match('/^[a-z][a-z0-9_]*$/D', $into) !== 1)) {
            throw new InvalidArgumentException('Invalid translated display field.');
        }
        contentTranslationCollectPath(
            $payload, explode('.', $path), $context, $into, [], $requests, $targets
        );
    }
    if ($requests === []) {
        return;
    }
    $translated = $resolver($requests, $locale);
    foreach ($targets as $index => $parts) {
        if (!array_key_exists($index, $translated) || !is_string($translated[$index])) {
            throw new RuntimeException('Translation resolver returned an incomplete response.');
        }
        $node =& $payload;
        foreach ($parts as $part) {
            $node =& $node[$part];
        }
        $node = $translated[$index];
        unset($node);
    }
}

/** @internal */
function contentTranslationCollectPath(
    array &$node, array $parts, string $context, ?string $into,
    array $prefix, array &$requests, array &$targets
): void {
    if ($parts === []) return;
    $part = array_shift($parts);
    if ($part === '*') {
        foreach ($node as $index => &$value) {
            if (is_array($value)) {
                contentTranslationCollectPath($value, $parts, $context, $into,
                    array_merge($prefix, [$index]), $requests, $targets);
            }
        }
        unset($value);
        return;
    }
    if (!array_key_exists($part, $node)) return;
    if ($parts !== []) {
        if (is_array($node[$part])) {
            contentTranslationCollectPath($node[$part], $parts, $context, $into,
                array_merge($prefix, [$part]), $requests, $targets);
        }
        return;
    }
    if (!is_string($node[$part]) || trim($node[$part]) === '') return;
    $sourceKey = $part . '_source';
    // The backend is the English authority. Avoid clobbering an existing source
    // field if another read transformer supplied one earlier in this request.
    if (!array_key_exists($sourceKey, $node)) $node[$sourceKey] = $node[$part];
    $source = $node[$sourceKey];
    if (!is_string($source) || trim($source) === '') return;
    $targetField = $into ?? $part;
    if ($into !== null) $node[$into] = $source;
    $idx = count($requests);
    $requests[$idx] = ['text' => $source, 'context' => $context];
    $targets[$idx] = array_merge($prefix, [$targetField]);
}

/** @param array<string,string|array{context:string,into:string}> $paths */
function contentTranslationApplyPaths(mysqli $conn, array &$payload, array $account, array $paths): void
{
    $locale = appLocaleForResponse($account);
    contentTranslationApplyWithResolver(
        $payload, $locale, $paths,
        static fn(array $requests, string $locale): array => contentTranslationResolveBatch($conn, $requests, $locale)
    );
}

/** Common map for general-question read rows, including their frozen anchors. */
function contentTranslationGeneralQuestionFields(string $prefix = ''): array
{
    return [
        $prefix . 'question_text' => 'question',
        $prefix . 'question_label' => 'question',
        $prefix . 'anchor_low_text' => 'anchor',
        $prefix . 'anchor_mid_text' => 'anchor',
        $prefix . 'anchor_high_text' => 'anchor',
        $prefix . 'section_label' => 'section',
        $prefix . 'cycle_title' => 'cycle_title',
    ];
}

/** Common map for KPI rows. Do not modify department: it is an API filter key. */
function contentTranslationKpiFields(string $prefix = ''): array
{
    return [
        $prefix . 'question_text' => 'question',
        $prefix . 'department' => ['context' => 'department', 'into' => 'department_display'],
        $prefix . 'section_label' => 'section',
        $prefix . 'cycle_title' => 'cycle_title',
    ];
}

function contentTranslationSectionFields(string $prefix = ''): array
{
    return [
        $prefix . 'label' => 'section',
        $prefix . 'description' => 'section',
        $prefix . 'cycle_title' => 'cycle_title',
    ];
}

/**
 * Approved French search matches are converted to source hashes. The calling
 * route still applies its existing company/privacy WHERE predicate; searching
 * never grants access to otherwise inaccessible records.
 *
 * This is one lookup per search, independent of the number of matching rows.
 * `source_text` in the memory is normalized; the source hash is checked here
 * and re-checked against the current row in the SQL predicate below.
 *
 * @param list<string> $contexts
 * @return list<string>
 */
function contentTranslationFindSearchHashes(mysqli $conn, array $account, string $search, array $contexts): array
{
    if (appLocaleForResponse($account) !== 'fr' || trim($search) === '' || $contexts === []) return [];
    foreach ($contexts as $context) {
        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $context) !== 1) {
            throw new InvalidArgumentException('Invalid translation search context.');
        }
    }
    $marks = implode(',', array_fill(0, count($contexts), '?'));
    $sql = "SELECT DISTINCT source_hash FROM content_translations "
        . "WHERE locale = 'fr' AND status = 'approved' "
        . "AND context IN ($marks) AND translated_text LIKE ? "
        . 'AND source_hash = SHA2(source_text, 256) LIMIT 1000';
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Unable to prepare translation search.');
    try {
        $values = array_merge($contexts, ['%' . $search . '%']);
        $stmt->bind_param(str_repeat('s', count($values)), ...$values);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        return array_values(array_unique(array_column($rows, 'source_hash')));
    } finally {
        $stmt->close();
    }
}

/**
 * Add French memory matches to an existing LIKE expression. The source column
 * is *not* replaced with a translated value; input IDs/filters remain English.
 * SQL whitespace canonicalisation mirrors the hash algorithm for typical
 * English content. NFC normalisation is performed by PHP when storing memory;
 * MariaDB has no native NFC function for decomposed legacy source rows.
 *
 * @param list<string> $hashes
 * @param array<int,string> $params Appended in SQL placeholder order.
 */
function contentTranslationHashSearchOr(string $column, array $hashes, array &$params, string &$types): string
{
    if ($hashes === []) return '';
    if (preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/Di', $column) !== 1) {
        throw new InvalidArgumentException('Invalid search source column.');
    }
    $marks = implode(',', array_fill(0, count($hashes), '?'));
    foreach ($hashes as $hash) {
        if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            throw new InvalidArgumentException('Invalid source hash for search.');
        }
        $params[] = $hash;
        $types .= 's';
    }
    return " OR SHA2(REGEXP_REPLACE(TRIM({$column}), '[[:space:]]+', ' '), 256) IN ({$marks})";
}
