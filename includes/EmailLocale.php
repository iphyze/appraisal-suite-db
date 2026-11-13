<?php
/** Recipient-specific email locale lookup: never use the sender's X-App-Locale. */
require_once __DIR__ . '/Locale.php';

/**
 * Resolve a batch of recipients with one prepared query (IDs and/or addresses).
 * Explicit user preference > HOME-company default > English.
 * @param array<array{id?:int, email?:string}> $recipients
 * @return array<string,string> Keys are "id:123" and/or "email:user@example.com".
 */
function appEmailLocalesForRecipients(mysqli $db, array $recipients): array
{
    $ids = []; $emails = [];
    foreach ($recipients as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id > 0) $ids[$id] = $id;
        $email = strtolower(trim((string)($row['email'] ?? '')));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) $emails[$email] = $email;
    }
    if (!$ids && !$emails) return [];

    $clauses = []; $types = ''; $values = [];
    if ($ids) {
        $clauses[] = 'u.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $types .= str_repeat('i', count($ids));
        array_push($values, ...array_values($ids));
    }
    if ($emails) {
        $clauses[] = 'LOWER(TRIM(u.email)) IN (' . implode(',', array_fill(0, count($emails), '?')) . ')';
        $types .= str_repeat('s', count($emails));
        array_push($values, ...array_values($emails));
    }
    $sql = 'SELECT u.id, u.email,
                CASE WHEN u.identity_id IS NOT NULL THEN i.preferred_locale ELSE u.preferred_locale END AS preferred_locale,
                home.default_locale
            FROM users u LEFT JOIN companies home ON home.id = u.company_id
            LEFT JOIN user_identities i ON i.id = u.identity_id
            WHERE ' . implode(' OR ', $clauses);
    try {
        $stmt = $db->prepare($sql);
        if (!$stmt) throw new RuntimeException('Unable to resolve email recipient language.');
        try {
            $stmt->bind_param($types, ...$values);
            if (!$stmt->execute()) throw new RuntimeException('Unable to resolve email recipient language.');
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        } finally {
            $stmt->close();
        }
    } catch (Throwable $e) {
        // Never mark a committed appraisal as failed or interrupt a bulk mail
        // because of an account-language lookup problem. Fall back to English.
        error_log('Email locale lookup failed; using English fallback.');
        return [];
    }
    $resolved = []; $emailCounts = [];
    foreach ($rows as $row) {
        $locale = appEffectiveLocale($row['preferred_locale'] ?? null, $row['default_locale'] ?? null);
        $resolved['id:' . (int)$row['id']] = $locale;
        $email = strtolower(trim((string)($row['email'] ?? '')));
        if ($email !== '') {
            $emailCounts[$email] = ($emailCounts[$email] ?? 0) + 1;
            $resolved['email:' . $email] = $locale;
        }
    }
    // Duplicate addresses are ambiguous: do not guess a person's locale.
    foreach ($emailCounts as $email => $count) {
        if ($count > 1) unset($resolved['email:' . $email]);
    }
    return $resolved;
}

/** ID takes precedence over address; unknown/external email is English. */
function appEmailLocaleFromMap(array $locales, int $userId = 0, string $email = ''): string
{
    $byId = $userId > 0 ? ($locales['id:' . $userId] ?? null) : null;
    $byEmail = $email !== '' ? ($locales['email:' . strtolower(trim($email))] ?? null) : null;
    return appSupportedLocale($byId) ?? appSupportedLocale($byEmail) ?? 'en';
}

function appEmailRecipientLocale(mysqli $db, int $userId = 0, string $email = ''): string
{
    $locales = appEmailLocalesForRecipients($db, [['id' => $userId, 'email' => $email]]);
    return appEmailLocaleFromMap($locales, $userId, $email);
}
