<?php
/** Calendar-year interpretation for read-only AI appraisal queries.
 * Never let the language model decide the meaning of a relative date on its own.
 */

function aiTimeframeNumber(string $raw): ?int
{
    $words = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
        'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10];
    $number = ctype_digit($raw) ? (int)$raw : ($words[strtolower($raw)] ?? null);
    return $number !== null && $number >= 1 && $number <= 20 ? $number : null;
}

/** Returns inclusive [from, to] appraisal-cycle years, or null for unrestricted queries. */
function aiTimeframeParse(string $message, ?int $currentYear = null): ?array
{
    $year = $currentYear ?? (int)gmdate('Y');
    $text = strtolower(trim($message));
    // French calendar-year phrases receive the same server-side bounds as English.
    // The model cannot expand these bounds with an invented cycle or year.
    if (preg_match('/\b(?:les\s+|ces\s+)?(\d{1,2})\s+derni[èe]res?\s+ann[ée]es?\b/u', $text, $fm)
        || preg_match('/\b(?:sur|pendant)\s+(?:les\s+)?(\d{1,2})\s+derni[èe]res?\s+ann[ée]es?\b/u', $text, $fm)) {
        $amount = (int)$fm[1];
        if ($amount >= 1 && $amount <= 20) return [$year - $amount + 1, $year];
    }
    if (preg_match('/\b(?:l[’\']ann[ée]e\s+derni[èe]re|l[’\']ann[ée]e\s+pr[ée]c[ée]dente)\b/u', $text)) {
        return [$year - 1, $year - 1];
    }
    if (preg_match('/\b(?:cette\s+ann[ée]e|l[’\']ann[ée]e\s+en\s+cours)\b/u', $text)) {
        return [$year, $year];
    }
    if (preg_match('/\b(?:entre|de)\s+((?:19|20)\d{2})\s+(?:et|[àa]|jusqu[’\']à)\s+((?:19|20)\d{2})\b/u', $text, $fm)) {
        return [(int)min($fm[1], $fm[2]), (int)max($fm[1], $fm[2])];
    }
    if (preg_match('/\bdepuis\s+((?:19|20)\d{2})\b/u', $text, $fm)) {
        return [(int)$fm[1], $year];
    }
    if (preg_match('/\b(?:depuis\s+|il\s+y\s+a\s+)(\d{1,2})\s+ans?\b/u', $text, $fm)) {
        $amount = (int)$fm[1];
        if ($amount >= 1 && $amount <= 20) {
            $asRange = str_contains($text, 'depuis ') || (bool)preg_match('/\b(?:jusqu[’\']à\s+(?:aujourd[’\']hui|maintenant)|à\s+(?:ce\s+jour|aujourd[’\']hui))\b/u', $text);
            return $asRange ? [$year - $amount, $year] : [$year - $amount, $year - $amount];
        }
    }
    $n = '(\d{1,2}|one|two|three|four|five|six|seven|eight|nine|ten)';
    $years = '((?:19|20)\d{2})';

    $adj = '(?:consecutive\s+|calendar\s+|full\s+|whole\s+|complete\s+|straight\s+)?';
    $beforeNow = '(?:before|prior\s+to|preceding|excluding|not\s+including|leading\s+up\s+to|up\s+to)\s+(?:this|the\s+current|the\s+present)\s+year';

    // "3 (consecutive) years before this year" = the three completed years: 2023-2025 in 2026.
    if (preg_match('/\b' . $n . '\s+' . $adj . 'years?\b.*?\b' . $beforeNow . '\b/', $text, $m)
        || preg_match('/\b' . $beforeNow . '\b.*?\b' . $n . '\s+' . $adj . 'years?\b/', $text, $m)) {
        $amount = aiTimeframeNumber($m[1]);
        if ($amount !== null && $amount > 0) return [$year - $amount, $year - 1];
    }
    // No number: "the years before this year" = all earlier years; "the year before this year" = last year.
    if (preg_match('/\byears\b.*?\b' . $beforeNow . '\b/', $text)) {
        return [1900, $year - 1];
    }
    if (preg_match('/\b' . $beforeNow . '\b/', $text)) {
        return [$year - 1, $year - 1];
    }

    // "from 2 years ago till date" means from 2024 through 2026 in 2026.
    if (preg_match('/\b(?:from|since|starting\s+from)\s+' . $n . '\s+years?\s+ago\b/', $text, $m)) {
        $amount = aiTimeframeNumber($m[1]);
        if ($amount !== null) return [$year - $amount, $year];
    }
    // "past/last 3 years" includes this year and the preceding two years.
    if (preg_match('/\b(?:past|last|previous)\s+' . $n . '\s+' . $adj . 'years?\b/', $text, $m)) {
        $amount = aiTimeframeNumber($m[1]);
        if ($amount !== null) return [$year - $amount + 1, $year];
    }
    // "last year" / "previous year" is the previous cycle year; "this year" the current one.
    if (preg_match("/\\b(?:last|previous|prior)\\s+year(?:'s|s')?\\b/", $text)) {
        return [$year - 1, $year - 1];
    }
    if (preg_match("/\\b(?:this|current)\\s+year(?:'s)?\\b/", $text)) {
        return [$year, $year];
    }
    // "for 3 consecutive years" (no "past/last"): the last three years including this one.
    if (preg_match('/\b' . $n . '\s+(?:consecutive|straight)\s+years?\b/', $text, $m)) {
        $amount = aiTimeframeNumber($m[1]);
        if ($amount !== null && $amount > 0) return [$year - $amount + 1, $year];
    }
    if (preg_match('/\b' . $n . '\s+years?\s+ago\b/', $text, $m)) {
        $amount = aiTimeframeNumber($m[1]);
        if ($amount !== null) {
            $past = $year - $amount;
            return preg_match('/\b(?:till|until|to|through)\s+(?:now|today|date|present)\b/', $text)
                ? [$past, $year] : [$past, $past];
        }
    }
    if (preg_match('/\b(?:between\s+|from\s+)?' . $years . '\s*(?:to|through|until|and|[-–—])\s*' . $years . '\b/', $text, $m)) {
        return [(int)min($m[1], $m[2]), (int)max($m[1], $m[2])];
    }
    if (preg_match('/\b(?:since|from)\s+' . $years . '\b/', $text, $m)) {
        return [(int)$m[1], $year];
    }
    if (preg_match('/\b' . $years . '\b/', $text, $m)) {
        return [(int)$m[1], (int)$m[1]];
    }
    return null;
}

/** Carry forward the requested period in short conversational follow-ups (e.g. a staff name). */
function aiTimeframeForTurn(string $message, array $history, ?int $currentYear = null): ?array
{
    $range = aiTimeframeParse($message, $currentYear);
    if ($range !== null) return $range;
    // Only the most recent user question supplies an inherited time window.
    for ($i = count($history) - 1; $i >= 0; $i--) {
        $entry = $history[$i];
        if (($entry['role'] ?? '') === 'user') {
            return aiTimeframeParse((string)($entry['content'] ?? ''), $currentYear);
        }
    }
    return null;
}

/** Model-provided years must not widen or override a period specified by the user. */
function aiTimeframeApply(array $arguments, ?array $range): array
{
    if ($range === null) return $arguments;
    [$from, $to] = $range;
    $arguments['year_from'] = $from;
    $arguments['year_to'] = $to;
    // A single selected cycle must not accidentally hide the prior years.
    if ($from !== $to) $arguments['cycle_id'] = 0;
    return $arguments;
}

/** Live appraisal questions must actually call an authorized data tool. */
function aiTimeframeNeedsLookup(string $message, ?array $range): bool
{
    if ($range === null) return false;
    if (preg_match('/\b(?:appraisal|appraised|rating|ratings|score|scores|performance|staff|employee|record|records|department|[ée]valuations?|[ée]valu[ée]s?|notes?|r[ée]sultats?|personnel|salari[ée]s?|employ[ée]s?|d[ée]partements?|performances?)\b/iu', $message)) {
        return true;
    }
    // An immediate follow-up containing just a full name refers to the preceding
    // period question; do not let the model answer it from general knowledge.
    return (bool)preg_match("/^\\p{Lu}[\\p{L}\'-]+(?:\\s+\\p{Lu}[\\p{L}\'-]+){1,4}$/u", trim($message));
}
