<?php
/**
 * Batch 8A: live, staff-subject-aware notification distribution to grantees.
 * Never send or enqueue a notification without a verified subject inside the
 * event's company. Appraisal events use the saved staff_type snapshot.
 */
require_once __DIR__ . '/AdminAppraisalVisibility.php';

/** Pure, deliberately fail-closed scope predicate; also used by PHP tests. */
function companyNotificationGrantScopeAllows(array $grant, array $subject): bool
{
    if ((int)($grant['receive_notifications'] ?? 0) !== 1
        || !empty($grant['revoked_at'])
        || !in_array($grant['access_level'] ?? null, ['view', 'manage'], true)) return false;
    $scope = $grant['staff_scope'] ?? null;
    if (!in_array($scope, ['All', 'Local', 'Expatriate'], true)) return false;
    return $scope === 'All' || ($subject['staff_type'] ?? null) === $scope;
}

/** Event IDs may come from several recipient paths; one recipient per event. */
function companyNotificationUniqueRecipients(array $recipients, array $exclude = []): array
{
    $blocked = array_fill_keys(array_filter(array_map('intval', $exclude)), true);
    $unique = [];
    foreach ($recipients as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id > 0 && !isset($blocked[$id])) $unique[$id] = $row;
    }
    return array_values($unique);
}

/**
 * Check a subject against the actual record (never caller-provided staff_type).
 * Appraisal subjects use the historical staff_type; other subjects use users.
 */
function companyNotificationVerifiedSubject(mysqli $db, int $companyId, int $subjectUserId, int $appraisalId = 0): ?array
{
    if ($companyId <= 0 || $subjectUserId <= 0) return null;
    if ($appraisalId > 0) {
        $stmt = $db->prepare('SELECT id AS appraisal_id, staff_user_id AS user_id, staff_type FROM appraisals WHERE id = ? AND company_id = ? AND staff_user_id = ? LIMIT 1');
        if (!$stmt) throw new Exception('Unable to verify notification subject.', 500);
        $stmt->bind_param('iii', $appraisalId, $companyId, $subjectUserId);
    } else {
        $stmt = $db->prepare('SELECT id AS user_id, staff_type FROM users WHERE id = ? AND company_id = ? LIMIT 1');
        if (!$stmt) throw new Exception('Unable to verify notification subject.', 500);
        $stmt->bind_param('ii', $subjectUserId, $companyId);
    }
    if (!$stmt->execute()) {
        $stmt->close();
        throw new Exception('Unable to verify notification subject.', 500);
    }
    $subject = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $subject;
}

/**
 * Fetch current eligible admins, never session/JWT grants.
 * Peer-admin policy is checked for each recipient in the event's own company,
 * with recorded-supervisor exception DISABLED for foreign companies.
 */
function companyNotificationGrantRecipients(
    mysqli $db, int $companyId, int $subjectUserId, int $appraisalId = 0, array $excludeIds = []
): array {
    $subject = companyNotificationVerifiedSubject($db, $companyId, $subjectUserId, $appraisalId);
    if (!$subject) return [];

    $stmt = $db->prepare("SELECT u.id, u.email, u.first_name, u.last_name,
             aca.access_level, aca.staff_scope, aca.receive_notifications, aca.revoked_at,
             CASE WHEN u.identity_id IS NOT NULL THEN i.preferred_locale ELSE u.preferred_locale END AS preferred_locale, home.default_locale
        FROM admin_company_access aca
        INNER JOIN users u ON u.id = aca.user_id AND u.is_active = 1
        LEFT JOIN companies home ON home.id = u.company_id
        LEFT JOIN user_identities i ON i.id = u.identity_id
        INNER JOIN roles r ON r.id = u.role_id
        INNER JOIN companies c ON c.id = aca.company_id AND c.is_active = 1
        WHERE aca.company_id = ?
          AND aca.revoked_at IS NULL
          AND aca.receive_notifications = 1
          AND aca.company_id <> u.company_id
          AND LOWER(REPLACE(TRIM(r.name), ' ', '_')) = 'admin'");
    if (!$stmt) throw new Exception('Unable to load company notification recipients.', 500);
    $stmt->bind_param('i', $companyId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new Exception('Unable to load company notification recipients.', 500);
    }
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $eligible = [];
    foreach ($rows as $row) {
        if (!companyNotificationGrantScopeAllows($row, $subject)) continue;
        $viewerId = (int)$row['id'];
        if ($viewerId === $subjectUserId
            || adminCanViewAppraisalSubject($db, $viewerId, $companyId, $subjectUserId, 0)) {
            $eligible[] = $row;
        }
    }
    return companyNotificationUniqueRecipients($eligible, $excludeIds);
}

/**
 * Additional notifications only. Do not use for per-recipient private inbox
 * messages or manually composed emails, which have a different audience.
 * Call only after ensureNotificationsTable(), before any write transaction.
 * Returns the exact grantee IDs notified, for post-commit email delivery.
 */
function createGrantNotificationsForSubject(
    mysqli $db, int $companyId, int $subjectUserId, int $appraisalId,
    string $type, string $title, string $message, string $linkUrl,
    array $excludeIds = [],
    ?string $messageKey = null,
    array $messageParams = []
): array {
    $recipients = companyNotificationGrantRecipients($db, $companyId, $subjectUserId, $appraisalId, $excludeIds);
    foreach ($recipients as $recipient) {
        createNotification($db, $companyId, (int)$recipient['id'], $type, $title,
            $message, $linkUrl, $subjectUserId, $appraisalId, $messageKey, $messageParams);
    }
    return array_map(static fn($row) => (int)$row['id'], $recipients);
}

/**
 * For legacy personal actions without an enclosing transaction, a notification
 * fan-out failure must not turn an already-saved acknowledgement or follow-up
 * into a failed/retryable submission. Administrative transactions use the
 * strict variant so their notification insert can still roll back with them.
 */
function tryCreateGrantNotificationsForSubject(
    mysqli $db, int $companyId, int $subjectUserId, int $appraisalId,
    string $type, string $title, string $message, string $linkUrl,
    array $excludeIds = [],
    ?string $messageKey = null,
    array $messageParams = []
): array {
    try {
        return createGrantNotificationsForSubject(
            $db, $companyId, $subjectUserId, $appraisalId,
            $type, $title, $message, $linkUrl, $excludeIds, $messageKey, $messageParams
        );
    } catch (Throwable $e) {
        error_log('Granted-company notification fan-out failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Best-effort delivery after the event has committed. A second live query
 * eliminates demoted/revoked/opted-out recipients before sending. Do not send
 * to admins granted *after* the event: intersect with IDs actually notified.
 * All outbound messages go through the existing sendMail delivery switch.
 */
function emailGrantNotificationRecipients(
    mysqli $db, int $companyId, int $subjectUserId, int $appraisalId,
    array $notifiedIds, string $title, string $message, string $linkUrl, array $eventParams = []
): void {
    if (!$notifiedIds) return;
    $allowed = array_fill_keys(array_map('intval', $notifiedIds), true);
    try {
        require_once __DIR__ . '/../routes/utils/Mailer.php';
        require_once __DIR__ . '/../routes/utils/EmailTemplates.php';
        $recipients = companyNotificationGrantRecipients($db, $companyId, $subjectUserId, $appraisalId);
        $stmt = $db->prepare('SELECT name FROM companies WHERE id = ? AND is_active = 1 LIMIT 1');
        if (!$stmt) throw new Exception('Unable to load company name for notification email.', 500);
        $stmt->bind_param('i', $companyId);
        $stmt->execute();
        $companyName = (string)($stmt->get_result()->fetch_assoc()['name'] ?? '');
        $stmt->close();
        if ($companyName === '') return;

        $appUrl = rtrim((string)($_ENV['APP_URL'] ?? 'https://lambertelectromec.com.ng'), '/');
        $path = '/' . ltrim($linkUrl, '/');
        // Only internal paths are permitted in outbound notification links.
        if (str_starts_with($path, '//') || str_contains($path, '\\') || preg_match('/[\r\n]/', $path)) return;
        foreach ($recipients as $recipient) {
            if (!isset($allowed[(int)$recipient['id']])) continue;
            $email = trim((string)($recipient['email'] ?? ''));
            if (!isDeliverableEmail($email)) continue;
            $recipientName = trim(($recipient['first_name'] ?? '') . ' ' . ($recipient['last_name'] ?? ''));
            $locale = appEffectiveLocale($recipient['preferred_locale'] ?? null, $recipient['default_locale'] ?? null);
            [$localizedTitle, $localizedMessage] = appEmailGrantEvent($title, $message, $locale, $eventParams);
            $html = getGrantedCompanyEventEmail([
                'recipient_name' => $recipientName,
                'company_name' => $companyName,
                'title' => $localizedTitle,
                'message' => $localizedMessage,
                'locale' => $locale,
                'app_url' => $appUrl . $path,
            ]);
            $result = sendMail($email, $localizedTitle . ' — ' . $companyName, $html, $companyName);
            if ($result !== true) error_log('Granted-company notification email failed for user ' . (int)$recipient['id']);
        }
    } catch (Throwable $e) {
        // Email transport failures must not turn an already-committed appraisal
        // or onboarding action into a failed/retryable write.
        error_log('Granted-company notification emails were not completed: ' . $e->getMessage());
    }
}
