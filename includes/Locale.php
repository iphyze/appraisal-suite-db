<?php
/** Shared EN/FR locale rules; no database access or HTML rendering. */

function appSupportedLocale($locale): ?string
{
    if (!is_string($locale)) {
        return null;
    }
    $value = strtolower(trim($locale));
    return in_array($value, ['en', 'fr'], true) ? $value : null;
}

/** Explicit account preference wins over home-company default. */
function appEffectiveLocale($userPreferred, $companyDefault): string
{
    return appSupportedLocale($userPreferred)
        ?? appSupportedLocale($companyDefault)
        ?? 'en';
}

/** Header represents the user's currently selected UI language for this request.
 * Stored preference still controls a new session in the absence of that header.
 * Reject unsupported values instead of trusting arbitrary client data.
 */
function appRequestLocale(): ?string
{
    $raw = $_SERVER['HTTP_X_APP_LOCALE'] ?? null;
    if ($raw === null || $raw === '') {
        return null;
    }
    $locale = appSupportedLocale($raw);
    if ($locale === null) {
        throw new InvalidArgumentException('Unsupported X-App-Locale header. Use en or fr.', 400);
    }
    return $locale;
}

function appLocaleForResponse(array $account): string
{
    return appRequestLocale() ?? appEffectiveLocale(
        $account['preferred_locale'] ?? null,
        $account['default_locale'] ?? null
    );
}
