<?php
/**
 * Batch 8B: live, subject-verified access to the authenticated user's inbox.
 *
 * Notification ownership is ALWAYS enforced by the calling endpoints via
 * n.user_id = ?. An admin's inbox includes their home company (unchanged)
 * and opted-in granted companies only while the grant, role and company are
 * active. This does not authorize company-data writes; a view-only recipient
 * may mark their own notifications as read.
 *
 * New events use the subject_user_id / subject_appraisal_id fields installed
 * by migration 22. A legacy appraisal may use the exact canonical appraisal
 * link ONLY when both subject fields are NULL. A mismatched or unverifiable
 * subject fails closed. Appraisal scoping uses saved appraisals.staff_type;
 * other events use the subject user's current users.staff_type. The granted
 * company's peer-admin policy always applies, with NO supervisor exception.
 */
require_once __DIR__ . '/CompanyAccess.php';

/**
 * All three endpoints (list, pulse, mark read) share this same SQL predicate.
 * @return array{sql:string,types:string,params:array}
 */
function companyNotificationVisibilityWhere(array $user): array
{
    $role = companyAccessRoleKey($user['role_key'] ?? $user['role'] ?? '');
    $homeId = (int) ($user['company_id'] ?? 0);
    $actorId = (int) ($user['id'] ?? 0);

    if ($actorId <= 0) {
        throw new Exception('Unauthorized: Invalid authenticated user.', 401);
    }
    if ($role === 'super_admin') {
        return ['sql' => '', 'types' => '', 'params' => []];
    }
    if ($homeId <= 0) {
        throw new Exception('Unauthorized: No company has been assigned to this account.', 403);
    }
    if ($role !== 'admin') {
        // Do not change staff and supervisor personal inbox visibility.
        return ['sql' => ' AND (n.company_id IS NULL OR n.company_id = ?)',
            'types' => 'i', 'params' => [$homeId]];
    }

    // The policy below is checked against aca.company_id, NEVER the actor's
    // home company. This intentionally does not permit the foreign-company
    // "I was their supervisor" shortcut to read another admin's appraisal.
    $sql = <<<'SQL'
 AND (
     n.company_id IS NULL
     OR n.company_id = ?
     OR EXISTS (
         SELECT 1
         FROM admin_company_access aca
         INNER JOIN companies granted_company
             ON granted_company.id = aca.company_id AND granted_company.is_active = 1
         INNER JOIN users grant_actor
             ON grant_actor.id = aca.user_id AND grant_actor.is_active = 1
         INNER JOIN roles grant_role ON grant_role.id = grant_actor.role_id
         WHERE aca.user_id = ?
           AND aca.company_id = n.company_id
           AND aca.company_id <> grant_actor.company_id
           AND aca.revoked_at IS NULL
           AND aca.receive_notifications = 1
           AND aca.access_level IN ('view', 'manage')
           AND aca.staff_scope IN ('All', 'Local', 'Expatriate')
           AND LOWER(REPLACE(TRIM(grant_role.name), ' ', '_')) = 'admin'
           AND (
               -- Verified appraisal snapshot, including non-appraisal event
               -- links (e.g. supervisor onboarding). Legacy rows without
               -- metadata may use only an exact canonical appraisal URL.
               EXISTS (
                   SELECT 1 FROM appraisals ap
                   WHERE ap.company_id = aca.company_id
                     AND (
                         (n.subject_appraisal_id IS NOT NULL
                          AND ap.id = n.subject_appraisal_id
                          AND n.subject_user_id = ap.staff_user_id)
                         OR
                         (n.subject_appraisal_id IS NULL
                          AND n.subject_user_id IS NULL
                          AND n.link_url LIKE '/appraisals/view/%'
                          AND ap.id = IF(n.link_url REGEXP '^/appraisals/view/[0-9]+$', CAST(SUBSTRING(n.link_url, 18) AS UNSIGNED), NULL)
                          AND n.link_url = CONCAT('/appraisals/view/', ap.id))
                     )
                     AND (aca.staff_scope = 'All' OR ap.staff_type = aca.staff_scope)
                     AND (
                         ap.staff_user_id = aca.user_id
                         OR NOT EXISTS (
                             SELECT 1 FROM users subject_user
                             INNER JOIN roles subject_role ON subject_role.id = subject_user.role_id
                             WHERE subject_user.id = ap.staff_user_id
                               AND subject_user.company_id = aca.company_id
                               AND LOWER(REPLACE(TRIM(subject_role.name), ' ', '_')) = 'admin'
                         )
                         OR EXISTS (
                             SELECT 1 FROM admin_appraisal_visibility_policies peer_all
                             WHERE peer_all.viewer_admin_id = aca.user_id
                               AND peer_all.company_id = aca.company_id
                               AND peer_all.visibility_mode = 'all'
                         )
                         OR EXISTS (
                             SELECT 1 FROM admin_appraisal_visibility_policies peer_selected
                             INNER JOIN admin_appraisal_visibility_targets peer_target
                               ON peer_target.viewer_admin_id = peer_selected.viewer_admin_id
                              AND peer_target.company_id = peer_selected.company_id
                             WHERE peer_selected.viewer_admin_id = aca.user_id
                               AND peer_selected.company_id = aca.company_id
                               AND peer_selected.visibility_mode = 'selected'
                               AND peer_target.target_admin_id = ap.staff_user_id
                         )
                     )
               )
               OR
               -- Subject-only event (such as onboarding). A grant can see
               -- the event only when the *actual subject user* belongs to its
               -- company, matches its current staff scope and passes the
               -- company's independent peer-admin visibility policy.
               (
                   n.subject_user_id IS NOT NULL
                   AND n.subject_appraisal_id IS NULL
                   AND EXISTS (
                       SELECT 1 FROM users subject_user
                       INNER JOIN roles subject_role ON subject_role.id = subject_user.role_id
                       WHERE subject_user.id = n.subject_user_id
                         AND subject_user.company_id = aca.company_id
                         AND (aca.staff_scope = 'All' OR subject_user.staff_type = aca.staff_scope)
                         AND (
                             subject_user.id = aca.user_id
                             OR LOWER(REPLACE(TRIM(subject_role.name), ' ', '_')) <> 'admin'
                             OR EXISTS (
                                 SELECT 1 FROM admin_appraisal_visibility_policies peer_all
                                 WHERE peer_all.viewer_admin_id = aca.user_id
                                   AND peer_all.company_id = aca.company_id
                                   AND peer_all.visibility_mode = 'all'
                             )
                             OR EXISTS (
                                 SELECT 1 FROM admin_appraisal_visibility_policies peer_selected
                                 INNER JOIN admin_appraisal_visibility_targets peer_target
                                   ON peer_target.viewer_admin_id = peer_selected.viewer_admin_id
                                  AND peer_target.company_id = peer_selected.company_id
                                 WHERE peer_selected.viewer_admin_id = aca.user_id
                                   AND peer_selected.company_id = aca.company_id
                                   AND peer_selected.visibility_mode = 'selected'
                                   AND peer_target.target_admin_id = subject_user.id
                             )
                         )
                   )
               )
           )
     )
 )
SQL;
    return ['sql' => $sql, 'types' => 'ii', 'params' => [$homeId, $actorId]];
}

/** Never bind a client-supplied recipient ID. */
function companyNotificationBindings(array $user, string $extraTypes = '', array $extraParams = []): array
{
    $filter = companyNotificationVisibilityWhere($user);
    return [
        'sql' => $filter['sql'],
        'types' => 'i' . $filter['types'] . $extraTypes,
        'params' => array_merge([(int) $user['id']], $filter['params'], $extraParams),
    ];
}
