<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/CompanyKpiAssignmentAccess.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Bad Request: Only GET method is allowed", 400);
    }

    $userData          = requireRoles(['super_admin', 'admin', 'supervisor']);
    $loggedInUserId    = (int) $userData['id'];
    $loggedInUserRole  = $userData['role'];
    $loggedInCompanyId = (int) $userData['company_id'];

    if (!isset($_GET['staff_user_id']) || !is_numeric($_GET['staff_user_id'])) {
        throw new Exception("Missing required parameter: 'staff_user_id'.", 400);
    }
    if (!isset($_GET['section_id']) || !is_numeric($_GET['section_id'])) {
        throw new Exception("Missing required parameter: 'section_id'.", 400);
    }

    $staffUserId = (int) $_GET['staff_user_id'];
    $sectionId   = (int) $_GET['section_id'];

    // Validate staff exists and belongs to the same company
    $staffStmt = $conn->prepare("
        SELECT u.id, u.first_name, u.last_name, u.department, u.company_id, u.staff_type
        FROM users u WHERE u.id = ? LIMIT 1
    ");
    $staffStmt->bind_param("i", $staffUserId);
    $staffStmt->execute();
    $staffMember = $staffStmt->get_result()->fetch_assoc();
    $staffStmt->close();

    if (!$staffMember) throw new Exception("Staff member not found.", 404);

    // The existing GET did not verify section ownership. Check the actual
    // section company before reading employee-specific assignments.
    $sectionStmt = $conn->prepare('SELECT id, type, company_id FROM appraisal_sections WHERE id=? LIMIT 1');
    $sectionStmt->bind_param('i', $sectionId);
    $sectionStmt->execute();
    $section = $sectionStmt->get_result()->fetch_assoc();
    $sectionStmt->close();
    if (!$section) throw new Exception('KPI section not found.', 404);
    companyKpiAssignmentAssertSection($staffMember, $section);

    $ownTeam = in_array($loggedInUserRole, ['admin', 'supervisor'], true)
        && (int)$staffMember['company_id'] === $loggedInCompanyId
        && companyKpiAssignmentIsOwnTeam($conn, $userData, $staffUserId, $sectionId);
    $assignmentContext = companyKpiAssignmentContext($userData, $staffMember, $ownTeam, false);
    companyKpiAssignmentAssertPeer($conn, $userData, $assignmentContext, $staffUserId);
    // For granted-company reads, never return an individual question targeted
    // at another appraisee, even if legacy assignments contain such a link.
    $grantQuestionWhere = companyKpiAssignmentReadWhere($userData, $assignmentContext, $staffUserId, $sectionId);

    /**
     * Check whether a custom selection exists for this staff + section.
     * If yes → return exactly those questions.
     * If no  → fall back to departmental default questions.
     */
    $assignmentCheckSql = $grantQuestionWhere !== ''
        ? "SELECT COUNT(*) AS cnt FROM staff_kpi_assignments ska
            INNER JOIN kpi_questions kq ON kq.id = ska.kpi_question_id
            WHERE ska.section_id=? AND ska.staff_user_id=? AND kq.is_active=1 {$grantQuestionWhere}"
        : 'SELECT COUNT(*) AS cnt FROM staff_kpi_assignments WHERE section_id=? AND staff_user_id=?';
    $assignmentCheckStmt = $conn->prepare($assignmentCheckSql);
    $assignmentCheckStmt->bind_param("ii", $sectionId, $staffUserId);
    $assignmentCheckStmt->execute();
    $assignmentCount = (int) $assignmentCheckStmt->get_result()->fetch_assoc()['cnt'];
    $assignmentCheckStmt->close();

    $isCustom = $assignmentCount > 0;

    if ($isCustom) {
        // Return exactly the selected questions
        $stmt = $conn->prepare("
            SELECT
                kq.id,
                kq.objective_code,
                kq.question_text,
                COALESCE(ska.target_override, kq.measure_target) AS objective_target,
                COALESCE(ska.evidence_override, kq.evidence_source) AS evidence_source,
                COALESCE(ska.weight_percent, kq.weight_percent) AS weight_percent,
                ska.weight_percent AS assignment_weight_percent,
                kq.weight_percent AS configured_weight_percent,
                kq.sort_order,
                kq.department,
                kq.supervisor_id,
                kq.staff_user_id,
                CASE
                    WHEN kq.staff_user_id IS NOT NULL THEN 'individual'
                    WHEN kq.supervisor_id IS NOT NULL THEN 'supervisor'
                    ELSE 'department'
                END AS scope,
                ska.id AS assignment_id,
                ska.assigned_by,
                CONCAT(ab.first_name,' ',ab.last_name) AS assigned_by_name
            FROM staff_kpi_assignments ska
            INNER JOIN kpi_questions kq ON kq.id = ska.kpi_question_id
            LEFT  JOIN users ab         ON ab.id = ska.assigned_by
            WHERE ska.section_id    = ?
              AND ska.staff_user_id = ?
              AND kq.is_active      = 1
              {$grantQuestionWhere}
            ORDER BY kq.sort_order ASC
        ");
        $stmt->bind_param("ii", $sectionId, $staffUserId);
    } else {
        // Fall back to departmental default questions
        $stmt = $conn->prepare("
            SELECT
                kq.id,
                kq.objective_code,
                kq.question_text,
                kq.measure_target AS objective_target,
                kq.evidence_source,
                kq.weight_percent AS weight_percent,
                NULL AS assignment_weight_percent,
                kq.weight_percent AS configured_weight_percent,
                kq.sort_order,
                kq.department,
                kq.supervisor_id,
                kq.staff_user_id,
                'department' AS scope,
                NULL AS assignment_id,
                NULL AS assigned_by,
                NULL AS assigned_by_name
            FROM kpi_questions kq
            WHERE kq.section_id      = ?
              AND kq.department      = ?
              AND kq.supervisor_id   IS NULL
              AND kq.staff_user_id   IS NULL
              AND kq.is_active       = 1
              {$grantQuestionWhere}
            ORDER BY kq.sort_order ASC
        ");
        $stmt->bind_param("is", $sectionId, $staffMember['department']);
    }

    $stmt->execute();
    $questions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "KPI questions resolved successfully",
        "data"    => [
            "staff"       => [
                "id"         => $staffMember['id'],
                "name"       => $staffMember['first_name'] . " " . $staffMember['last_name'],
                "department" => $staffMember['department'],
            ],
            "section_id"  => $sectionId,
            "is_custom"   => $isCustom,
            "source"      => $isCustom ? "custom_selection" : "departmental_default",
            "has_weights" => count(array_filter($questions, fn($question) => $question['weight_percent'] !== null && $question['weight_percent'] !== '')) > 0,
            "questions"   => $questions,
            "count"       => count($questions),
        ]
    ]);

} catch (Exception $e) {
    error_log("GetKpiAssignments Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}