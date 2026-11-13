<?php
/** Server-side inbox localisation. No user-provided HTML is ever interpreted. */
require_once __DIR__ . '/Locale.php';

function appNotificationCatalogue(string $locale): array
{
    static $catalogue = [];
    $code = appSupportedLocale($locale) ?? 'en';
    if (!isset($catalogue[$code])) {
        $catalogue[$code] = require __DIR__ . '/lang/' . $code . '.php';
    }
    return $catalogue[$code];
}

/** Validate a canonical server-owned key, and only scalar interpolation data. */
function appNotificationParams(?string $key, array $params): ?string
{
    if ($key === null) return null;
    if (!preg_match('/^notification\.[a-zA-Z][a-zA-Z0-9]*$/D', $key)) {
        throw new InvalidArgumentException('Invalid notification message key.');
    }
    $en = appNotificationCatalogue('en');
    if (!isset($en[$key . '.title'], $en[$key . '.message'])) {
        throw new InvalidArgumentException('Unknown notification message key.');
    }
    if (count($params) > 12) throw new InvalidArgumentException('Too many notification message parameters.');
    foreach ($params as $name => $value) {
        if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_]*$/D', $name)
            || (!is_string($value) && !is_int($value) && !is_float($value))
            || strlen((string)$value) > 300) {
            throw new InvalidArgumentException('Invalid notification message parameters.');
        }
    }
    return json_encode((object)$params, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

/**
 * Render into existing title/message response fields, never the stored DB row.
 * Unknown, incomplete or malformed structured data falls back to stored text.
 * Old notifications have no key and are returned byte-for-byte as authored.
 */
function appNotificationLocalizeRow(array $row, string $locale): array
{
    $key = $row['message_key'] ?? null;
    if (!is_string($key) || $key === '') return $row;
    $english = appNotificationCatalogue('en');
    $translated = appNotificationCatalogue($locale);
    $title = $translated[$key . '.title'] ?? $english[$key . '.title'] ?? null;
    $message = $translated[$key . '.message'] ?? $english[$key . '.message'] ?? null;
    if (!is_string($title) || !is_string($message)) return $row;
    try {
        $params = json_decode((string)($row['message_params'] ?? '{}'), true, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        return $row;
    }
    if (!is_array($params)) return $row;
    $replacements = [];
    foreach ($params as $name => $value) {
        if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_]*$/D', $name)
            || (!is_string($value) && !is_int($value) && !is_float($value))) return $row;
        // Numeric display stays locale-aware without mutating the database value.
        if ($name === 'score' && is_numeric($value)) {
            $value = number_format((float)$value, 2, $locale === 'fr' ? ',' : '.', '');
        }
        $replacements['{' . $name . '}'] = (string)$value;
    }
    $renderedTitle = strtr($title, $replacements);
    $renderedMessage = strtr($message, $replacements);
    // Fail closed if required placeholders weren't supplied.
    if (preg_match('/\{[a-z][a-z0-9_]*\}/', $renderedTitle . $renderedMessage)) return $row;
    $row['title'] = $renderedTitle;
    $row['message'] = $renderedMessage;
    return $row;
}

function appNotificationLocalizeRows(array $rows, string $locale): array
{
    return array_map(static fn(array $row): array => appNotificationLocalizeRow($row, $locale), $rows);
}
