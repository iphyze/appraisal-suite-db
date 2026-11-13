<?php

/**
 * Shared management reporting helpers.
 *
 * All analytics are driven by the caller's already-authorised appraisal WHERE
 * clause. This keeps dashboard/export aggregates aligned with company, staff-type,
 * and Admin-to-Admin visibility rules.
 */

function managementBindAndFetchAll(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Unable to prepare management reporting query: ' . $conn->error, 500);
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function managementBindAndFetchOne(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $rows = managementBindAndFetchAll($conn, $sql, $types, $params);
    return $rows[0] ?? [];
}

function fetchManagementInsights(
    mysqli $conn,
    string $appraisalWhereSql,
    string $types,
    array $params,
    bool $managementReviewEnabled = false
): array {
    $profileDistribution = managementBindAndFetchAll($conn, "
        SELECT
            COALESCE(NULLIF(TRIM(ap.scoring_profile_label), ''), 'Unassigned') AS profile_label,
            COUNT(*) AS cnt,
            ROUND(AVG(ap.appraisal_summary), 2) AS avg_score
        FROM appraisals ap
        WHERE {$appraisalWhereSql}
        GROUP BY COALESCE(NULLIF(TRIM(ap.scoring_profile_label), ''), 'Unassigned')
        ORDER BY cnt DESC, profile_label ASC
    ", $types, $params);

    $sectionPerformance = managementBindAndFetchAll($conn, "
        SELECT
            ss.section_code,
            ss.section_label,
            COUNT(DISTINCT ss.appraisal_id) AS appraisal_count,
            ROUND(AVG(ss.section_avg), 2) AS avg_score,
            ROUND(AVG(ss.weighted_score), 2) AS avg_weighted_score
        FROM appraisal_section_scores ss
        INNER JOIN appraisals ap ON ap.id = ss.appraisal_id
        WHERE {$appraisalWhereSql}
        GROUP BY ss.section_code, ss.section_label
        ORDER BY MIN(ss.id) ASC
    ", $types, $params);

    $objectivePerformance = managementBindAndFetchAll($conn, "
        SELECT
            COALESCE(NULLIF(TRIM(kq.objective_code), ''), CONCAT('OBJ-', akr.kpi_question_id)) AS objective_code,
            akr.question_text AS objective,
            COUNT(*) AS rating_count,
            ROUND(AVG(akr.rating), 2) AS avg_rating,
            SUM(CASE WHEN NULLIF(TRIM(COALESCE(akr.actual_result, '')), '') IS NOT NULL THEN 1 ELSE 0 END) AS actual_result_count
        FROM appraisal_kpi_responses akr
        INNER JOIN appraisals ap ON ap.id = akr.appraisal_id
        LEFT JOIN kpi_questions kq ON kq.id = akr.kpi_question_id
        WHERE {$appraisalWhereSql}
        GROUP BY objective_code, akr.question_text
        ORDER BY avg_rating ASC, rating_count DESC
        LIMIT 12
    ", $types, $params);

    $developmentThemes = managementBindAndFetchAll($conn, "
        SELECT
            TRIM(ap.development) AS development,
            COUNT(*) AS cnt
        FROM appraisals ap
        WHERE {$appraisalWhereSql}
          AND NULLIF(TRIM(COALESCE(ap.development, '')), '') IS NOT NULL
          AND LOWER(TRIM(ap.development)) NOT IN ('no','none','n/a','na','nil','not required','not applicable')
        GROUP BY TRIM(ap.development)
        ORDER BY cnt DESC, development ASC
        LIMIT 10
    ", $types, $params);

    $revisionSummary = managementBindAndFetchOne($conn, "
        SELECT
            SUM(CASE WHEN COALESCE(ap.update_count, 0) > 0 THEN 1 ELSE 0 END) AS revised_appraisals,
            COALESCE(SUM(ap.update_count), 0) AS total_revisions,
            MAX(COALESCE(ap.update_count, 0)) AS max_revisions
        FROM appraisals ap
        WHERE {$appraisalWhereSql}
    ", $types, $params);

    $evidenceExceptions = managementBindAndFetchOne($conn, "
        SELECT COUNT(*) AS cnt
        FROM (
            SELECT akr.id
            FROM appraisal_kpi_responses akr
            INNER JOIN appraisals ap ON ap.id = akr.appraisal_id
            INNER JOIN appraisal_rating_points rp
                ON rp.cycle_id = ap.cycle_id
               AND rp.rating_value = akr.rating
               AND rp.is_active = 1
               AND rp.requires_comment = 1
            WHERE {$appraisalWhereSql}
              AND NULLIF(TRIM(COALESCE(akr.rating_comment, '')), '') IS NULL

            UNION ALL

            SELECT asr.id
            FROM appraisal_section_responses asr
            INNER JOIN appraisals ap ON ap.id = asr.appraisal_id
            INNER JOIN appraisal_rating_points rp
                ON rp.cycle_id = ap.cycle_id
               AND rp.rating_value = asr.rating
               AND rp.is_active = 1
               AND rp.requires_comment = 1
            WHERE {$appraisalWhereSql}
              AND NULLIF(TRIM(COALESCE(asr.rating_comment, '')), '') IS NULL
        ) exceptions
    ", $types . $types, array_merge($params, $params));

    $reviewSummary = [
        'enabled' => $managementReviewEnabled ? 1 : 0,
        'reviewed' => 0,
        'follow_up' => 0,
        'unreviewed' => 0,
        'stale' => 0,
    ];
    $reviewCategories = [];

    if ($managementReviewEnabled) {
        $rows = managementBindAndFetchAll($conn, "
            SELECT
                CASE
                    WHEN mr.id IS NULL THEN 'unreviewed'
                    WHEN mr.appraisal_revision_no <> (COALESCE(ap.update_count, 0) + 1) THEN 'stale'
                    WHEN mr.review_status = 'follow_up' THEN 'follow_up'
                    ELSE 'reviewed'
                END AS review_state,
                COUNT(*) AS cnt
            FROM appraisals ap
            LEFT JOIN appraisal_management_reviews mr ON mr.id = (
                SELECT MAX(mr2.id)
                FROM appraisal_management_reviews mr2
                WHERE mr2.appraisal_id = ap.id
            )
            WHERE {$appraisalWhereSql}
            GROUP BY review_state
        ", $types, $params);
        foreach ($rows as $row) {
            $key = $row['review_state'] ?? 'unreviewed';
            if (array_key_exists($key, $reviewSummary)) {
                $reviewSummary[$key] = (int)($row['cnt'] ?? 0);
            }
        }

        $reviewCategories = managementBindAndFetchAll($conn, "
            SELECT mr.category, mr.review_status, COUNT(*) AS cnt
            FROM appraisals ap
            INNER JOIN appraisal_management_reviews mr ON mr.id = (
                SELECT MAX(mr2.id)
                FROM appraisal_management_reviews mr2
                WHERE mr2.appraisal_id = ap.id
            )
            WHERE {$appraisalWhereSql}
              AND mr.appraisal_revision_no = (COALESCE(ap.update_count, 0) + 1)
            GROUP BY mr.category, mr.review_status
            ORDER BY cnt DESC, mr.category ASC
        ", $types, $params);
    }

    return [
        'profile_distribution' => $profileDistribution,
        'section_performance' => $sectionPerformance,
        'objective_performance' => $objectivePerformance,
        'development_themes' => $developmentThemes,
        'revision_summary' => [
            'revised_appraisals' => (int)($revisionSummary['revised_appraisals'] ?? 0),
            'total_revisions' => (int)($revisionSummary['total_revisions'] ?? 0),
            'max_revisions' => (int)($revisionSummary['max_revisions'] ?? 0),
        ],
        'evidence_exception_count' => (int)($evidenceExceptions['cnt'] ?? 0),
        'management_review' => $reviewSummary,
        'review_categories' => $reviewCategories,
    ];
}

function fetchObjectiveSetupSummary(
    mysqli $conn,
    int $companyId,
    int $cycleId,
    string $eligibleUserWhereSql = '1=1',
    string $eligibleTypes = '',
    array $eligibleParams = []
): array {
    $sections = managementBindAndFetchAll($conn, "
        SELECT s.id, s.code, s.label
        FROM appraisal_sections s
        INNER JOIN appraisal_section_objective_settings os ON os.section_id = s.id
        WHERE s.company_id = ? AND s.cycle_id = ? AND s.is_active = 1 AND os.enabled = 1
        ORDER BY s.sort_order ASC, s.id ASC
    ", 'ii', [$companyId, $cycleId]);

    if (!$sections) {
        return [
            'enabled' => 0,
            'required_plans' => 0,
            'locked' => 0,
            'draft' => 0,
            'missing' => 0,
            'progress_percent' => 0,
            'sections' => [],
        ];
    }

    $totals = ['required_plans' => 0, 'locked' => 0, 'draft' => 0, 'missing' => 0];
    $sectionRows = [];

    foreach ($sections as $section) {
        $sectionId = (int)$section['id'];
        $row = managementBindAndFetchOne($conn, "
            SELECT
                COUNT(DISTINCT sa.staff_id) AS required_plans,
                COUNT(DISTINCT CASE WHEN sop.status = 'locked' THEN sa.staff_id END) AS locked,
                COUNT(DISTINCT CASE WHEN sop.status = 'draft' THEN sa.staff_id END) AS draft
            FROM supervisor_assignments sa
            INNER JOIN users u ON u.id = sa.staff_id
            INNER JOIN roles r ON r.id = u.role_id
            LEFT JOIN staff_objective_plans sop
                ON sop.cycle_id = sa.cycle_id
               AND sop.section_id = ?
               AND sop.staff_user_id = sa.staff_id
            WHERE sa.cycle_id = ?
              AND u.company_id = ?
              AND u.is_active = 1
              AND {$eligibleUserWhereSql}
        ", 'iii' . $eligibleTypes, array_merge([$sectionId, $cycleId, $companyId], $eligibleParams));

        $required = (int)($row['required_plans'] ?? 0);
        $locked = (int)($row['locked'] ?? 0);
        $draft = (int)($row['draft'] ?? 0);
        $missing = max(0, $required - $locked - $draft);
        $progress = $required > 0 ? round(($locked / $required) * 100, 1) : 0;

        $sectionRows[] = [
            'section_id' => $sectionId,
            'section_code' => $section['code'],
            'section_label' => $section['label'],
            'required_plans' => $required,
            'locked' => $locked,
            'draft' => $draft,
            'missing' => $missing,
            'progress_percent' => $progress,
        ];

        $totals['required_plans'] += $required;
        $totals['locked'] += $locked;
        $totals['draft'] += $draft;
        $totals['missing'] += $missing;
    }

    return [
        'enabled' => 1,
        'required_plans' => $totals['required_plans'],
        'locked' => $totals['locked'],
        'draft' => $totals['draft'],
        'missing' => $totals['missing'],
        'progress_percent' => $totals['required_plans'] > 0
            ? round(($totals['locked'] / $totals['required_plans']) * 100, 1)
            : 0,
        'sections' => $sectionRows,
    ];
}
