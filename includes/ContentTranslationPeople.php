<?php
/**
 * Read-only French display of job titles (translation memory context 'job_title').
 *
 * Job titles are stored in English (users.job_title and the frozen appraisals.staff_job_title).
 * This adds a separate display field next to each one, e.g. job_title -> job_title_display,
 * exactly like departments (department -> department_display). The original value is never
 * changed, so editing, filtering, searching and imports keep using the English source.
 *
 * Call once per response, after authorisation and data-visibility checks, on the data that
 * is about to be returned. Only approved translations are used; otherwise the display field
 * repeats the English text. Nothing is done when the response language is English.
 */
require_once __DIR__ . '/ContentTranslationResponse.php';

/** @return array<string,string> source field => display field */
function contentTranslationJobTitleFields(): array
{
    return ['job_title' => 'job_title_display', 'staff_job_title' => 'staff_job_title_display'];
}

/** @internal Collects [path, display field, text] for every non-empty job title in $node. */
function contentTranslationCollectJobTitles(array $node, array $path, array &$found): void
{
    foreach (contentTranslationJobTitleFields() as $field => $display) {
        if (array_key_exists($field, $node) && is_string($node[$field]) && trim($node[$field]) !== '') {
            $found[] = ['path' => $path, 'display' => $display, 'text' => $node[$field]];
        }
    }
    foreach ($node as $key => $child) {
        if (is_array($child)) contentTranslationCollectJobTitles($child, array_merge($path, [$key]), $found);
    }
}

/** Testable core: $resolver(list<array{text,context}>, locale) returns translated strings by index. */
function contentTranslationJobTitlesWithResolver(array &$data, string $locale, callable $resolver): void
{
    if ($locale === 'en') return;
    $found = [];
    contentTranslationCollectJobTitles($data, [], $found);
    if ($found === []) return;
    $requests = array_map(static fn(array $f): array => ['text' => $f['text'], 'context' => 'job_title'], $found);
    $translated = $resolver($requests, $locale);
    foreach ($found as $i => $f) {
        $node =& $data;
        foreach ($f['path'] as $key) $node =& $node[$key];
        $value = $translated[$i] ?? null;
        $node[$f['display']] = is_string($value) && trim($value) !== '' ? $value : $f['text'];
        unset($node);
    }
}

/** Adds job_title_display / staff_job_title_display anywhere in $data, in the response language. */
function contentTranslationAddJobTitleDisplays(mysqli $conn, array &$data, array $account): void
{
    try {
        $locale = appLocaleForResponse($account);
        contentTranslationJobTitlesWithResolver($data, $locale,
            static fn(array $requests, string $locale): array => contentTranslationResolveBatch($conn, $requests, $locale));
    } catch (Throwable $e) {
        // A translation problem must never break the page: the English title is shown instead.
        error_log('[AppraisalSuite] Job title translation skipped: ' . $e->getMessage());
    }
}
