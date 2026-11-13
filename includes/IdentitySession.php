<?php
/**
 * Batch 3 identity-session rules. These pure checks are shared by the login
 * selection and middleware, and can be tested without a live connection.
 */
declare(strict_types=1);

function identitySessionMemberAvailable(array $member): bool
{
    return (int)($member['is_active'] ?? 0) === 1
        && (int)($member['company_is_active'] ?? 0) === 1
        && (int)($member['identity_is_active'] ?? 0) === 1;
}

/** Return false for mismatched identity/membership, inactive accounts, revoked JWT. */
function identitySessionValid(array $claims, array $member): bool
{
    if (!identitySessionMemberAvailable($member)) return false;
    $memberId = (int)($member['id'] ?? 0);
    $identityId = (int)($member['identity_id'] ?? 0);
    if ($memberId <= 0 || (int)($claims['id'] ?? 0) !== $memberId) return false;

    if ($identityId > 0) {
        // Old per-membership JWTs are deliberately not promoted to identity JWTs.
        return (int)($claims['identity_id'] ?? 0) === $identityId
            && array_key_exists('identity_token_version', $claims)
            && (int)$claims['identity_token_version'] === (int)$member['identity_token_version'];
    }

    // Temporary fallback: only memberships without a linked identity. These
    // must be explicitly unlinked in DB, not a second membership of an identity.
    return (int)($claims['identity_id'] ?? 0) === 0
        && (int)($claims['token_version'] ?? -1) === (int)($member['token_version'] ?? 0);
}

/**
 * Latest audit login wins (found only among this identity's OWN memberships).
 * A missing previous login means the user chooses without a preselection.
 */
function identitySessionSuggestedMembership(array $memberships, int $lastUserId): int
{
    foreach ($memberships as $member) {
        if ((int)($member['user_id'] ?? 0) === $lastUserId && $lastUserId > 0) {
            return $lastUserId;
        }
    }
    return 0;
}
