<?php

require_once __DIR__ . '/../../includes/AdminAppraisalVisibility.php';
require_once __DIR__ . '/../../includes/CompanyNotificationDispatch.php';
require_once __DIR__ . '/../../includes/NotificationLocalization.php';

function ensureNotificationsTable(mysqli $conn): void
{
    // CREATE TABLE causes an implicit commit in MariaDB, even with IF NOT
    // EXISTS. Repeated calls within a request must never issue DDL during
    // appraisal or grant-write transactions after the preflight call.
    static $ready = [];
    $connectionId = spl_object_id($conn);
    if (isset($ready[$connectionId])) return;
    $createSql = "
        CREATE TABLE IF NOT EXISTS notifications (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id INT UNSIGNED DEFAULT NULL,
            user_id INT UNSIGNED NOT NULL,
            type VARCHAR(80) NOT NULL DEFAULT 'info',
            title VARCHAR(180) NOT NULL,
            message TEXT DEFAULT NULL,
            link_url VARCHAR(500) DEFAULT NULL,
            subject_user_id INT UNSIGNED DEFAULT NULL,
            subject_appraisal_id INT UNSIGNED DEFAULT NULL,
            message_key VARCHAR(100) DEFAULT NULL,
            message_params JSON DEFAULT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            read_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_notifications_user (user_id, is_read, created_at),
            KEY idx_notifications_company (company_id),
            KEY idx_notifications_company_subject_user (company_id, subject_user_id),
            KEY idx_notifications_company_subject_appraisal (company_id, subject_appraisal_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ";

    if (!$conn->query($createSql)) {
        throw new Exception('Unable to initialise notifications table: ' . $conn->error, 500);
    }
    $ready[$connectionId] = true;
}

function createNotification(
    mysqli $conn,
    $companyId,
    $userId,
    string $type,
    string $title,
    string $message = '',
    string $linkUrl = '',
    int $subjectUserId = 0,
    int $subjectAppraisalId = 0,
    ?string $messageKey = null,
    array $messageParams = []
): bool {
    $userId = (int) $userId;

    if ($userId <= 0) {
        return false;
    }

    ensureNotificationsTable($conn);

    $companyId = $companyId ? (int) $companyId : null;
    $subjectUserId = $subjectUserId > 0 ? $subjectUserId : null;
    $subjectAppraisalId = $subjectAppraisalId > 0 ? $subjectAppraisalId : null;

    // Keep unkeyed, user-written and legacy notifications on the original
    // insert path. Structured events use the new columns (migration 25).
    $jsonParams = appNotificationParams($messageKey, $messageParams);
    $stmt = $conn->prepare($messageKey === null ? "
        INSERT INTO notifications (
            company_id, user_id, type, title, message, link_url,
            subject_user_id, subject_appraisal_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    " : "
        INSERT INTO notifications (
            company_id, user_id, type, title, message, link_url,
            subject_user_id, subject_appraisal_id, message_key, message_params
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        throw new Exception('Unable to prepare notification insert: ' . $conn->error, 500);
    }

    if ($messageKey === null) {
        $stmt->bind_param('iissssii', $companyId, $userId, $type, $title,
            $message, $linkUrl, $subjectUserId, $subjectAppraisalId);
    } else {
        $stmt->bind_param('iissssiiss', $companyId, $userId, $type, $title,
            $message, $linkUrl, $subjectUserId, $subjectAppraisalId, $messageKey, $jsonParams);
    }

    if (!$stmt->execute()) {
        $error = $stmt->error ?: $conn->error;
        $stmt->close();

        throw new Exception('Unable to create notification: ' . $error, 500);
    }

    $stmt->close();

    return true;
}

function createNotificationsForCompanyRoles(
    mysqli $conn,
    $companyId,
    array $roles,
    string $type,
    string $title,
    string $message = '',
    string $linkUrl = '',
    array $excludeUserIds = [],
    int $appraisalSubjectUserId = 0,
    int $appraisalSupervisorId = 0,
    ?array &$grantRecipientIds = null,
    ?string $messageKey = null,
    array $messageParams = []
): bool {
    ensureNotificationsTable($conn);
    $grantRecipientIds = [];

    $companyId = (int) $companyId;

    if ($companyId <= 0 || empty($roles)) {
        return false;
    }

    $normalisedRoles = array_values(array_unique(array_map(
        function ($role) {
            return strtolower(str_replace(' ', '_', trim((string) $role)));
        },
        $roles
    )));

    $rolePlaceholders = implode(',', array_fill(0, count($normalisedRoles), '?'));
    $types = 'i' . str_repeat('s', count($normalisedRoles));
    $params = array_merge([$companyId], $normalisedRoles);

    $excludeSql = '';

    if (!empty($excludeUserIds)) {
        $excludeUserIds = array_values(array_filter(array_map('intval', $excludeUserIds)));
    }

    if (!empty($excludeUserIds)) {
        $excludePlaceholders = implode(',', array_fill(0, count($excludeUserIds), '?'));
        $excludeSql = " AND u.id NOT IN ({$excludePlaceholders})";
        $types .= str_repeat('i', count($excludeUserIds));
        $params = array_merge($params, $excludeUserIds);
    }

    $stmt = $conn->prepare("
        SELECT u.id, r.name AS role_name
        FROM users u
        INNER JOIN roles r ON r.id = u.role_id
        WHERE u.company_id = ?
          AND u.is_active = 1
          AND LOWER(REPLACE(TRIM(r.name), ' ', '_')) IN ({$rolePlaceholders})
          {$excludeSql}
    ");

    if (!$stmt) {
        throw new Exception('Unable to prepare notification recipient lookup: ' . $conn->error, 500);
    }

    $stmt->bind_param($types, ...$params);

    if (!$stmt->execute()) {
        $error = $stmt->error ?: $conn->error;
        $stmt->close();

        throw new Exception('Unable to load notification recipients: ' . $error, 500);
    }

    $result = $stmt->get_result();
    $recipients = [];

    while ($row = $result->fetch_assoc()) {
        $recipients[] = [
            'id' => (int) $row['id'],
            'role_key' => aavRoleKey($row['role_name'] ?? ''),
        ];
    }

    $stmt->close();

    // Deduplicate home-company role recipients before processing the event.
    $recipients = companyNotificationUniqueRecipients($recipients, $excludeUserIds);
    foreach ($recipients as $recipient) {
        $recipientId = (int) $recipient['id'];

        // When the appraisee is another Admin, do not create a notification that
        // exposes the appraisal to an Admin who cannot open that record. Super
        // Admins and normal staff appraisal notifications remain unchanged.
        if (
            $appraisalSubjectUserId > 0
            && $recipient['role_key'] === 'admin'
            && !adminCanViewAppraisalSubject(
                $conn,
                $recipientId,
                $companyId,
                $appraisalSubjectUserId,
                $appraisalSupervisorId
            )
        ) {
            continue;
        }

        createNotification(
            $conn,
            $companyId,
            $recipientId,
            $type,
            $title,
            $message,
            $linkUrl,
            $appraisalSubjectUserId,
            notificationAppraisalIdFromLink($linkUrl),
            $messageKey,
            $messageParams
        );
    }

    // Only company-wide appraisal events with a VERIFIED appraisal subject
    // may be broadcast to opt-in grantees. Generic targeted messages are not
    // broadcast, avoiding accidental disclosure of private inbox content.
    $appraisalId = notificationAppraisalIdFromLink($linkUrl);
    if ($appraisalSubjectUserId > 0 && $appraisalId > 0) {
        $grantRecipientIds = createGrantNotificationsForSubject(
            $conn, $companyId, $appraisalSubjectUserId, $appraisalId,
            $type, $title, $message, $linkUrl,
            array_merge($excludeUserIds, array_column($recipients, 'id')),
            $messageKey,
            $messageParams
        );
    }

    return true;
}

/** Recognise a canonical internal appraisal detail path only. */
function notificationAppraisalIdFromLink(string $link): int
{
    if (!preg_match('~^/appraisals/view/([1-9][0-9]*)$~D', $link, $matches)) return 0;
    return (int)$matches[1];
}