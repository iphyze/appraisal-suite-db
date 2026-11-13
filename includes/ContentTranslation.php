<?php
/**
 * Shared translation memory (Batch 6a).
 *
 * The English text is the sole source of truth. Routes should call this only
 * for eligible database-authored content (questions, anchors, rating labels,
 * section labels, departments, cycle titles, evaluation statements, etc.).
 * Never pass names, identifiers, comments, feedback, notes or other personal
 * free-text here; on-demand translation of personal writing is a later batch.
 *
 * Call contentTranslationResolveBatch($conn, $items, $locale) ONCE after
 * collecting all eligible strings in an API response. Each input entry has
 * ['text' => (English source), 'context' => (stable content type)]. The result
 * has the same keys as the input; unknown and unapproved items fall back to
 * the exact, unchanged source text. Do not write the results into source rows.
 * Treat translations as plain text, never render them with HTML injection.
 */
require_once __DIR__ . '/Locale.php';
require_once __DIR__ . '/UnicodeNfc.php';

/** NFC, then Unicode whitespace collapse and trim; identical for every context. */
function contentTranslationNormalize(string $sourceText): string
{
    $nfc = appUnicodeNfc($sourceText);
    // Unicode separator characters plus the whitespace controls in the
    // Unicode White_Space property. NBSP and narrow NBSP become one space.
    $collapsed = preg_replace('/[\p{Z}\x{0009}-\x{000D}\x{0085}]+/u', ' ', $nfc);
    if ($collapsed === null) {
        throw new InvalidArgumentException('Translation source contains invalid UTF-8.');
    }
    return trim($collapsed, ' ');
}

/** Source hashes are content-based, not company/cycle/record ID-based. */
function contentTranslationHash(string $sourceText): string
{
    return hash('sha256', contentTranslationNormalize($sourceText));
}

/** @return array<string,string|null> */
function &contentTranslationRequestCache(): array
{
    static $cache = [];
    return $cache;
}

/** Call after an approval/edit in the same PHP request, or in isolated tests. */
function contentTranslationResetRequestCache(): void
{
    $cache =& contentTranslationRequestCache();
    $cache = [];
}

/**
 * One retrieval call for all not-yet-cached source texts.
 *
 * @param array<array-key,array{text:string,context:string}> $items
 * @param callable(string,list<string>,list<string>):array $fetchApproved
 * @return array<array-key,string>
 */
function contentTranslationResolveWithLoader(array $items, string $locale, callable $fetchApproved): array
{
    $locale = appSupportedLocale($locale)
        ?? throw new InvalidArgumentException('Unsupported translation locale.');

    $fallback = [];
    $keysByIndex = [];
    $pending = [];
    $cache =& contentTranslationRequestCache();

    foreach ($items as $index => $item) {
        if (!is_array($item) || !isset($item['text'], $item['context'])
            || !is_string($item['text']) || !is_string($item['context'])) {
            throw new InvalidArgumentException('Translation entries require text and context strings.');
        }
        $context = $item['context'];
        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $context) !== 1) {
            throw new InvalidArgumentException('Invalid translation context.');
        }
        $source = $item['text'];
        $fallback[$index] = $source;
        if ($locale === 'en') {
            continue;
        }
        $normalized = contentTranslationNormalize($source);
        if ($normalized === '') {
            continue;
        }
        $hash = hash('sha256', $normalized);
        $key = $locale . '|' . $context . '|' . $hash;
        $keysByIndex[$index] = $key;
        if (!array_key_exists($key, $cache)) {
            $pending[$key] = [
                'hash' => $hash,
                'context' => $context,
                'source' => $normalized,
            ];
        }
    }

    if ($pending !== []) {
        $hashes = [];
        $contexts = [];
        foreach ($pending as $key => $request) {
            $hashes[$request['hash']] = $request['hash'];
            $contexts[$request['context']] = $request['context'];
        }
        // All misses are fetched at once, with no per-string lookups.
        $rows = $fetchApproved($locale, array_values($hashes), array_values($contexts));
        foreach ($pending as $key => $_request) {
            $cache[$key] = null; // Cache misses for the rest of this request.
        }
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['source_hash'], $row['source_text'],
                $row['context'], $row['translated_text'])) {
                continue;
            }
            if (isset($row['locale']) && $row['locale'] !== $locale) {
                continue;
            }
            if (isset($row['status']) && $row['status'] !== 'approved') {
                continue;
            }
            $key = $locale . '|' . $row['context'] . '|' . $row['source_hash'];
            if (!isset($pending[$key]) || !is_string($row['source_text'])
                || !is_string($row['translated_text']) || trim($row['translated_text']) === '') {
                continue;
            }
            // Prevent mislabelled/stale records from showing a translation for
            // a different source text, even if imported with a wrong hash.
            try {
                $storedSource = contentTranslationNormalize($row['source_text']);
            } catch (InvalidArgumentException $e) {
                continue;
            }
            if ($storedSource !== $pending[$key]['source']
                || !hash_equals($pending[$key]['hash'], hash('sha256', $storedSource))) {
                continue;
            }
            $cache[$key] = $row['translated_text'];
        }
    }

    foreach ($keysByIndex as $index => $key) {
        if ($cache[$key] !== null) {
            $fallback[$index] = $cache[$key];
        }
    }
    return $fallback;
}

/**
 * Database-backed resolver. Uses one parameterised query for the distinct
 * (source_hash, context) pairs not yet in the request cache.
 *
 * @param mysqli $conn Existing application's mysqli connection
 * @param array<array-key,array{text:string,context:string}> $items
 * @return array<array-key,string>
 */
function contentTranslationResolveBatch(mysqli $conn, array $items, string $locale): array
{
    return contentTranslationResolveWithLoader(
        $items,
        $locale,
        static function (string $targetLocale, array $hashes, array $contexts) use ($conn): array {
            if ($hashes === [] || $contexts === []) {
                return [];
            }
            // MariaDB supports up to 65,535 prepared placeholders. Regular
            // API responses are well below this limit; fail loudly rather
            // than accidentally introducing one-query-per-string behaviour.
            if (count($hashes) + count($contexts) + 1 > 60000) {
                throw new LengthException('Too many translation keys in one API response.');
            }
            $hashPlaceholders = implode(',', array_fill(0, count($hashes), '?'));
            $contextPlaceholders = implode(',', array_fill(0, count($contexts), '?'));
            $sql = 'SELECT source_hash, source_text, locale, context, translated_text, status '
                . 'FROM content_translations '
                . "WHERE locale = ? AND status = 'approved' "
                . "AND source_hash IN ($hashPlaceholders) "
                . "AND context IN ($contextPlaceholders)";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare translation lookup query.');
            }
            try {
                $params = array_merge([$targetLocale], $hashes, $contexts);
                $stmt->bind_param(str_repeat('s', count($params)), ...$params);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result === false) {
                    throw new RuntimeException('Unable to read translation lookup results.');
                }
                return $result->fetch_all(MYSQLI_ASSOC);
            } finally {
                $stmt->close();
            }
        }
    );
}
