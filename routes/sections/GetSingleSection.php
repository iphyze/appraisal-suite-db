<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/CompanySetupWrite.php';

require_once 'includes/ContentTranslationResponse.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Bad Request: Only GET method is allowed", 400);
    }

    $userData = authenticateUser();

    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception("Missing required parameter: 'id'.", 400);
    }

    $id = (int) $_GET['id'];
    if ($id <= 0) throw new Exception("Invalid section ID.", 400);

    $stmt = $conn->prepare("
        SELECT
            s.id, s.code, s.label, s.description, s.type,
            s.weight, s.default_rating_mode, s.lock_rating_mode, s.sort_order, s.is_active,
            s.created_at, s.updated_at,
            COALESCE(os.enabled, 0) AS objective_setup_enabled,
            COALESCE(os.min_objectives, 1) AS objective_min_objectives,
            os.max_objectives AS objective_max_objectives,
            COALESCE(os.require_weights, 0) AS objective_require_weights,
            COALESCE(os.required_weight_total, 100.00) AS objective_required_weight_total,
            COALESCE(os.require_target, 0) AS objective_require_target,
            COALESCE(os.require_evidence, 0) AS objective_require_evidence,
            COALESCE(os.require_actual_result, 0) AS objective_require_actual_result,
            COALESCE(os.allow_individual_objectives, 1) AS objective_allow_individual_objectives,
            COALESCE(os.require_locked_plan, 1) AS objective_require_locked_plan,
            COALESCE(os.lock_questions_during_appraisal, 1) AS objective_lock_questions_during_appraisal,
            COALESCE(os.force_per_question_ratings, 1) AS objective_force_per_question_ratings,
            ac.id    AS cycle_id,
            ac.year  AS cycle_year,
            ac.title AS cycle_title,
            c.id     AS company_id,
            c.code   AS company_code,
            c.name   AS company_name,
            (
                SELECT COALESCE(SUM(s2.weight), 0)
                FROM appraisal_sections s2
                WHERE s2.cycle_id = s.cycle_id
                  AND s2.company_id = s.company_id
                  AND s2.is_active = 1
            ) AS total_weight_used
        FROM appraisal_sections s
        INNER JOIN appraisal_cycles ac ON ac.id = s.cycle_id
        INNER JOIN companies c         ON c.id  = s.company_id
        LEFT JOIN appraisal_section_objective_settings os ON os.section_id = s.id
        WHERE s.id = ?
        LIMIT 1
    ");
    if (!$stmt) throw new Exception("Database error: " . $conn->error, 500);

    $stmt->bind_param("i", $id);
    $stmt->execute();
    $section = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$section) throw new Exception("Section not found.", 404);

    $companyScope = companySetupReadScope($userData);
    if ($companyScope !== null && (int) $section['company_id'] !== (int) $companyScope) {
        throw new Exception("Unauthorized: This section is outside the selected company scope.", 403);
    }

    // Batch 6b: translate approved setup content in one batched read.
    contentTranslationApplyPaths($conn, $section, $userData, contentTranslationSectionFields(''));

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "Section fetched successfully",
        "data"    => $section
    ]);

} catch (Exception $e) {
    error_log("GetSingleSection Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}
