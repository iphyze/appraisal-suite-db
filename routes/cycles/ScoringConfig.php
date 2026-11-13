<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/CompanySetupWrite.php';
require_once 'includes/ContentTranslationResponse.php';

header('Content-Type: application/json; charset=UTF-8');

function scoringJson($status, $message, $data = [], $code = 200)
{
    http_response_code($code);
    echo json_encode(['status' => $status, 'message' => $message, 'data' => $data]);
    exit;
}

function scoringFetchOne($conn, $sql)
{
    $result = $conn->query($sql);
    if (!$result) throw new Exception('Database error: ' . $conn->error, 500);
    return $result->fetch_assoc();
}

function scoringFetchAll($conn, $sql)
{
    $result = $conn->query($sql);
    if (!$result) throw new Exception('Database error: ' . $conn->error, 500);
    $rows = [];
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    return $rows;
}

function scoringEsc($conn, $value)
{
    return $conn->real_escape_string(trim((string) $value));
}

function scoringCycleForUser($conn, array $userData, int $cycleId): array
{
    if ($cycleId <= 0) throw new Exception('A valid cycle_id is required.', 400);

    $scope = companySetupReadScope($userData);
    $scopeSql = $scope !== null ? ' AND ac.company_id = ' . (int) $scope : '';
    $cycle = scoringFetchOne($conn, "
        SELECT ac.id, ac.company_id, ac.year, ac.title, ac.is_active, c.name AS company_name, c.code AS company_code
        FROM appraisal_cycles ac
        INNER JOIN companies c ON c.id = ac.company_id
        WHERE ac.id = {$cycleId} {$scopeSql}
        LIMIT 1
    ");

    if (!$cycle) throw new Exception('Appraisal cycle not found or outside your company scope.', 404);
    return $cycle;
}

function scoringDefaultBands(): array
{
    return [
        ['label' => 'Requires Development', 'min_score' => 1.00, 'max_score' => 1.09, 'sort_order' => 1, 'is_active' => 1],
        ['label' => 'Marginally Meets Requirements', 'min_score' => 1.10, 'max_score' => 2.09, 'sort_order' => 2, 'is_active' => 1],
        ['label' => 'Completely Meet Requirements', 'min_score' => 2.10, 'max_score' => 3.09, 'sort_order' => 3, 'is_active' => 1],
        ['label' => 'Consistently Exceeds Requirements', 'min_score' => 3.10, 'max_score' => 4.59, 'sort_order' => 4, 'is_active' => 1],
        ['label' => 'Significant / Outstanding', 'min_score' => 4.60, 'max_score' => 5.00, 'sort_order' => 5, 'is_active' => 1],
    ];
}

function scoringGetConfig($conn, array $cycle): array
{
    $cycleId = (int) $cycle['id'];
    $companyId = (int) $cycle['company_id'];

    $settings = scoringFetchOne($conn, "
        SELECT rating_min, rating_max, rating_step, require_explicit_profile, management_review_enabled
        FROM appraisal_cycle_settings
        WHERE cycle_id = {$cycleId} AND company_id = {$companyId}
        LIMIT 1
    ");
    if (!$settings) {
        $settings = ['rating_min' => '1.00', 'rating_max' => '5.00', 'rating_step' => '0.10', 'require_explicit_profile' => 0, 'management_review_enabled' => 0];
    }

    $sections = scoringFetchAll($conn, "
        SELECT id, code, label, description, type, weight, sort_order, is_active
        FROM appraisal_sections
        WHERE cycle_id = {$cycleId} AND company_id = {$companyId}
        ORDER BY sort_order ASC, id ASC
    ");

    $bands = scoringFetchAll($conn, "
        SELECT id, label, min_score, max_score, sort_order, is_active
        FROM appraisal_rating_bands
        WHERE cycle_id = {$cycleId} AND company_id = {$companyId}
        ORDER BY sort_order ASC, min_score ASC, id ASC
    ");
    if (!$bands) $bands = scoringDefaultBands();

    $ratingPoints = scoringFetchAll($conn, "
        SELECT id, rating_value, label, definition, evidence_requirement, requires_comment, sort_order, is_active
        FROM appraisal_rating_points
        WHERE cycle_id = {$cycleId} AND company_id = {$companyId}
        ORDER BY sort_order ASC, rating_value ASC, id ASC
    ");

    $profiles = scoringFetchAll($conn, "
        SELECT id, code, label, description, is_default, is_active, sort_order
        FROM appraisal_scoring_profiles
        WHERE cycle_id = {$cycleId} AND company_id = {$companyId}
        ORDER BY sort_order ASC, id ASC
    ");

    foreach ($profiles as &$profile) {
        $profileId = (int) $profile['id'];
        $weights = scoringFetchAll($conn, "
            SELECT section_id, weight
            FROM appraisal_profile_section_weights
            WHERE profile_id = {$profileId}
        ");
        $profile['section_weights'] = [];
        foreach ($weights as $weight) {
            $profile['section_weights'][(string) (int) $weight['section_id']] = (float) $weight['weight'];
        }
    }
    unset($profile);

    return [
        'cycle' => $cycle,
        'settings' => [
            'rating_min' => (float) $settings['rating_min'],
            'rating_max' => (float) $settings['rating_max'],
            'rating_step' => (float) $settings['rating_step'],
            'require_explicit_profile' => (int) ($settings['require_explicit_profile'] ?? 0),
            'management_review_enabled' => (int) ($settings['management_review_enabled'] ?? 0),
        ],
        'sections' => $sections,
        'rating_bands' => $bands,
        'rating_points' => $ratingPoints,
        'profiles' => $profiles,
    ];
}

/** Localize the complete scoring configuration with one approved-memory query. */
function scoringGetLocalizedConfig(mysqli $conn, array $cycle, array $account): array
{
    $result = scoringGetConfig($conn, $cycle);
    contentTranslationApplyPaths($conn, $result, $account, [
        'cycle.title' => 'cycle_title',
        'sections.*.label' => 'section',
        'sections.*.description' => 'section',
        'rating_bands.*.label' => 'rating_band',
        'rating_points.*.label' => 'rating_label',
        'rating_points.*.definition' => 'rating_label',
        'rating_points.*.evidence_requirement' => 'rating_label',
        'profiles.*.label' => 'scoring_profile',
        'profiles.*.description' => 'scoring_profile',
    ]);
    return $result;
}

function scoringValidateBands(array $bands, float $ratingMin, float $ratingMax, float $ratingStep): array
{
    if (!$bands) throw new Exception('At least one rating band is required.', 400);

    $normalized = [];
    foreach ($bands as $index => $band) {
        $label = trim((string) ($band['label'] ?? ''));
        $min = isset($band['min_score']) && is_numeric($band['min_score']) ? round((float) $band['min_score'], 2) : null;
        $max = isset($band['max_score']) && is_numeric($band['max_score']) ? round((float) $band['max_score'], 2) : null;
        if ($label === '' || $min === null || $max === null) {
            throw new Exception('Every rating band requires a label, minimum score and maximum score.', 400);
        }
        if ($min > $max) throw new Exception("Rating band '{$label}' has a minimum score greater than its maximum score.", 400);
        $normalized[] = [
            'label' => $label,
            'min_score' => $min,
            'max_score' => $max,
            'sort_order' => isset($band['sort_order']) ? (int) $band['sort_order'] : ($index + 1),
            'is_active' => array_key_exists('is_active', $band) ? (int) !!$band['is_active'] : 1,
        ];
    }

    $active = array_values(array_filter($normalized, fn($band) => (int) $band['is_active'] === 1));
    if (!$active) throw new Exception('At least one active rating band is required.', 400);
    usort($active, fn($a, $b) => $a['min_score'] <=> $b['min_score']);

    foreach ($active as $band) {
        if ($band['min_score'] < $ratingMin - 0.0001 || $band['max_score'] > $ratingMax + 0.0001) {
            throw new Exception("Rating band '{$band['label']}' must stay within the configured rating range {$ratingMin} to {$ratingMax}.", 400);
        }
    }
    if (abs((float)$active[0]['min_score'] - $ratingMin) > 0.0001) {
        throw new Exception('Active rating bands must start at the cycle minimum rating.', 400);
    }

    for ($i = 1; $i < count($active); $i++) {
        $previous = $active[$i - 1];
        $current = $active[$i];
        if ($current['min_score'] <= $previous['max_score']) {
            throw new Exception("Rating bands '{$previous['label']}' and '{$current['label']}' overlap.", 400);
        }
        // Calculated section/final scores are stored to two decimal places. Ensure
        // there is no uncovered hundredth between adjacent bands.
        if ($current['min_score'] - $previous['max_score'] > 0.0101) {
            throw new Exception("Rating bands '{$previous['label']}' and '{$current['label']}' leave an uncovered score gap.", 400);
        }
    }
    $lastBand = $active[count($active) - 1];
    if (abs((float)$lastBand['max_score'] - $ratingMax) > 0.0001) {
        throw new Exception('Active rating bands must end at the cycle maximum rating.', 400);
    }

    $steps = (int) round(($ratingMax - $ratingMin) / $ratingStep);
    for ($i = 0; $i <= $steps; $i++) {
        $value = round($ratingMin + ($i * $ratingStep), 2);
        $matches = array_filter($active, fn($band) => $value >= $band['min_score'] - 0.0001 && $value <= $band['max_score'] + 0.0001);
        if (count($matches) !== 1) {
            throw new Exception("Active rating bands must classify every valid rating. Rating {$value} is not covered exactly once.", 400);
        }
    }

    return $normalized;
}


function scoringValidatePoints(array $points, float $ratingMin, float $ratingMax, float $ratingStep): array
{
    if (!$points) return [];

    $normalized = [];
    $seen = [];
    foreach ($points as $index => $point) {
        if (!isset($point['rating_value']) || !is_numeric($point['rating_value'])) {
            throw new Exception('Every rating point requires a numeric rating value.', 400);
        }
        $value = round((float) $point['rating_value'], 2);
        if ($value < $ratingMin - 0.0001 || $value > $ratingMax + 0.0001) {
            throw new Exception("Rating point {$value} is outside the configured rating range.", 400);
        }
        $stepIndex = ($value - $ratingMin) / $ratingStep;
        if (abs($stepIndex - round($stepIndex)) > 0.001) {
            throw new Exception("Rating point {$value} does not align with the configured rating step.", 400);
        }
        $key = number_format($value, 2, '.', '');
        if (isset($seen[$key])) throw new Exception("Rating point {$value} is duplicated.", 400);
        $seen[$key] = true;

        $label = trim((string) ($point['label'] ?? ''));
        if ($label === '') throw new Exception("Rating point {$value} requires a label.", 400);

        $normalized[] = [
            'rating_value' => $value,
            'label' => $label,
            'definition' => trim((string) ($point['definition'] ?? '')),
            'evidence_requirement' => trim((string) ($point['evidence_requirement'] ?? '')),
            'requires_comment' => !empty($point['requires_comment']) ? 1 : 0,
            'sort_order' => isset($point['sort_order']) ? (int) $point['sort_order'] : ($index + 1),
            'is_active' => array_key_exists('is_active', $point) ? (int) !!$point['is_active'] : 1,
        ];
    }

    return $normalized;
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $userData = strtoupper($method) === 'GET'
        ? requireRoles(['super_admin', 'admin', 'supervisor'])
        : requireRoles(['super_admin', 'admin']);
    $loggedInUserId = (int) ($userData['id'] ?? 0);
    $loggedInEmail = trim((string) ($userData['email'] ?? 'System user'));

    if ($method === 'GET') {
        $cycleId = isset($_GET['cycle_id']) ? (int) $_GET['cycle_id'] : 0;
        $cycle = scoringCycleForUser($conn, $userData, $cycleId);
        scoringJson('Success', 'Cycle scoring configuration fetched successfully.', scoringGetLocalizedConfig($conn, $cycle, $userData));
    }

    if ($method !== 'PUT') throw new Exception('Bad Request: Only GET and PUT methods are allowed.', 405);
    $setupContext = companySetupWriteContext($userData);

    $data = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($data)) throw new Exception('Invalid request format. Expected a JSON object.', 400);

    $cycleId = isset($data['cycle_id']) ? (int) $data['cycle_id'] : 0;
    $cycle = scoringCycleForUser($conn, $userData, $cycleId);
    $companyId = (int) $cycle['company_id'];
    companySetupAssertCompany($userData, $setupContext, $companyId);

    $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
    $ratingMin = isset($settings['rating_min']) && is_numeric($settings['rating_min']) ? round((float) $settings['rating_min'], 2) : 1.0;
    $ratingMax = isset($settings['rating_max']) && is_numeric($settings['rating_max']) ? round((float) $settings['rating_max'], 2) : 5.0;
    $ratingStep = isset($settings['rating_step']) && is_numeric($settings['rating_step']) ? round((float) $settings['rating_step'], 2) : 0.1;
    $requireExplicitProfile = !empty($settings['require_explicit_profile']) ? 1 : 0;
    $managementReviewEnabled = !empty($settings['management_review_enabled']) ? 1 : 0;

    if ($ratingMin < 0 || $ratingMax <= $ratingMin || $ratingMax > 100) {
        throw new Exception('The cycle rating range is invalid.', 400);
    }
    if ($ratingStep <= 0 || $ratingStep > ($ratingMax - $ratingMin)) {
        throw new Exception('The rating step must be greater than zero and smaller than the rating range.', 400);
    }
    $stepCount = ($ratingMax - $ratingMin) / $ratingStep;
    if (abs($stepCount - round($stepCount)) > 0.001) {
        throw new Exception('The rating step must divide evenly across the configured rating range.', 400);
    }

    $bands = scoringValidateBands(is_array($data['rating_bands'] ?? null) ? $data['rating_bands'] : [], $ratingMin, $ratingMax, $ratingStep);
    $ratingPoints = scoringValidatePoints(is_array($data['rating_points'] ?? null) ? $data['rating_points'] : [], $ratingMin, $ratingMax, $ratingStep);
    $profiles = is_array($data['profiles'] ?? null) ? $data['profiles'] : [];

    $sectionRows = scoringFetchAll($conn, "
        SELECT id, code, label, weight, is_active
        FROM appraisal_sections
        WHERE cycle_id = {$cycleId} AND company_id = {$companyId}
        ORDER BY sort_order ASC, id ASC
    ");
    $sectionMap = [];
    $activeSectionIds = [];
    foreach ($sectionRows as $section) {
        $sectionId = (int) $section['id'];
        $sectionMap[$sectionId] = $section;
        if ((int) $section['is_active'] === 1) $activeSectionIds[] = $sectionId;
    }

    $profileCodes = [];
    $defaultCount = 0;
    foreach ($profiles as $profile) {
        $label = trim((string) ($profile['label'] ?? ''));
        $code = strtoupper(trim((string) ($profile['code'] ?? '')));
        if ($label === '' || $code === '') throw new Exception('Every scoring profile requires a code and label.', 400);
        if (isset($profileCodes[$code])) throw new Exception("Scoring profile code '{$code}' is duplicated.", 400);
        $profileCodes[$code] = true;
        $isProfileActive = (int)($profile['is_active'] ?? 1) === 1;
        if (!empty($profile['is_default'])) {
            if (!$isProfileActive) throw new Exception("Default scoring profile '{$label}' must be active.", 400);
            $defaultCount++;
        }

        $weights = is_array($profile['section_weights'] ?? null) ? $profile['section_weights'] : [];
        if ((int) ($profile['is_active'] ?? 1) === 1 && $activeSectionIds) {
            $total = 0.0;
            foreach ($activeSectionIds as $sectionId) {
                $raw = $weights[(string) $sectionId] ?? $weights[$sectionId] ?? null;
                if ($raw === null || $raw === '' || !is_numeric($raw)) {
                    throw new Exception("Profile '{$label}' needs a weight for every active section.", 400);
                }
                $weight = round((float) $raw, 2);
                if ($weight < 0 || $weight > 100) throw new Exception("Profile '{$label}' has an invalid section weight.", 400);
                $total += $weight;
            }
            if (abs($total - 100.0) > 0.01) {
                throw new Exception("Section weights for profile '{$label}' must total exactly 100%. Current total: " . number_format($total, 2) . '%.', 400);
            }
        }
    }
    if ($defaultCount > 1) throw new Exception('Only one scoring profile can be the default for a cycle.', 400);

    $conn->begin_transaction();
    try {
        $settingsStmt = $conn->prepare("\n            INSERT INTO appraisal_cycle_settings\n                (cycle_id, company_id, rating_min, rating_max, rating_step, require_explicit_profile, management_review_enabled, created_by, updated_by)\n            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)\n            ON DUPLICATE KEY UPDATE\n                company_id = VALUES(company_id), rating_min = VALUES(rating_min), rating_max = VALUES(rating_max),\n                rating_step = VALUES(rating_step), require_explicit_profile = VALUES(require_explicit_profile), management_review_enabled = VALUES(management_review_enabled), updated_by = VALUES(updated_by)\n        ");
        if (!$settingsStmt) throw new Exception('Database error: ' . $conn->error, 500);
        $settingsStmt->bind_param('iidddiiii', $cycleId, $companyId, $ratingMin, $ratingMax, $ratingStep, $requireExplicitProfile, $managementReviewEnabled, $loggedInUserId, $loggedInUserId);
        if (!$settingsStmt->execute()) throw new Exception('Unable to save cycle rating settings: ' . $settingsStmt->error, 500);
        $settingsStmt->close();

        if (!$conn->query("DELETE FROM appraisal_rating_bands WHERE cycle_id = {$cycleId} AND company_id = {$companyId}")) {
            throw new Exception('Unable to reset rating bands: ' . $conn->error, 500);
        }
        $bandStmt = $conn->prepare('INSERT INTO appraisal_rating_bands (company_id, cycle_id, label, min_score, max_score, sort_order, is_active, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        if (!$bandStmt) throw new Exception('Database error: ' . $conn->error, 500);
        foreach ($bands as $band) {
            $label = $band['label'];
            $min = (float) $band['min_score'];
            $max = (float) $band['max_score'];
            $sort = (int) $band['sort_order'];
            $active = (int) $band['is_active'];
            $bandStmt->bind_param('iisddiiii', $companyId, $cycleId, $label, $min, $max, $sort, $active, $loggedInUserId, $loggedInUserId);
            if (!$bandStmt->execute()) throw new Exception('Unable to save rating band: ' . $bandStmt->error, 500);
        }
        $bandStmt->close();

        if (!$conn->query("DELETE FROM appraisal_rating_points WHERE cycle_id = {$cycleId} AND company_id = {$companyId}")) {
            throw new Exception('Unable to reset rating points: ' . $conn->error, 500);
        }
        if ($ratingPoints) {
            $pointStmt = $conn->prepare('INSERT INTO appraisal_rating_points (company_id, cycle_id, rating_value, label, definition, evidence_requirement, requires_comment, sort_order, is_active, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            if (!$pointStmt) throw new Exception('Database error: ' . $conn->error, 500);
            foreach ($ratingPoints as $point) {
                $value = (float) $point['rating_value'];
                $label = $point['label'];
                $definition = $point['definition'];
                $evidence = $point['evidence_requirement'];
                $requiresComment = (int) $point['requires_comment'];
                $sortOrder = (int) $point['sort_order'];
                $isActive = (int) $point['is_active'];
                $pointStmt->bind_param('iidsssiiiii', $companyId, $cycleId, $value, $label, $definition, $evidence, $requiresComment, $sortOrder, $isActive, $loggedInUserId, $loggedInUserId);
                if (!$pointStmt->execute()) throw new Exception('Unable to save rating point: ' . $pointStmt->error, 500);
            }
            $pointStmt->close();
        }

        $existingProfiles = scoringFetchAll($conn, "SELECT id FROM appraisal_scoring_profiles WHERE cycle_id = {$cycleId} AND company_id = {$companyId}");
        $existingIds = array_map(fn($row) => (int) $row['id'], $existingProfiles);
        $keptIds = [];

        foreach ($profiles as $index => $profile) {
            $profileId = isset($profile['id']) ? (int) $profile['id'] : 0;
            $code = strtoupper(trim((string) $profile['code']));
            $label = trim((string) $profile['label']);
            $description = trim((string) ($profile['description'] ?? ''));
            $isDefault = !empty($profile['is_default']) ? 1 : 0;
            $isActive = array_key_exists('is_active', $profile) ? (int) !!$profile['is_active'] : 1;
            $sortOrder = isset($profile['sort_order']) ? (int) $profile['sort_order'] : ($index + 1);

            if ($profileId > 0) {
                if (!in_array($profileId, $existingIds, true)) throw new Exception('A scoring profile does not belong to this cycle.', 409);
                $stmt = $conn->prepare('UPDATE appraisal_scoring_profiles SET code=?, label=?, description=?, is_default=?, is_active=?, sort_order=?, updated_by=? WHERE id=? AND cycle_id=? AND company_id=?');
                if (!$stmt) throw new Exception('Database error: ' . $conn->error, 500);
                $stmt->bind_param('sssiiiiiii', $code, $label, $description, $isDefault, $isActive, $sortOrder, $loggedInUserId, $profileId, $cycleId, $companyId);
                if (!$stmt->execute()) throw new Exception('Unable to update scoring profile: ' . $stmt->error, 500);
                $stmt->close();
            } else {
                $stmt = $conn->prepare('INSERT INTO appraisal_scoring_profiles (company_id, cycle_id, code, label, description, is_default, is_active, sort_order, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                if (!$stmt) throw new Exception('Database error: ' . $conn->error, 500);
                $stmt->bind_param('iisssiiiii', $companyId, $cycleId, $code, $label, $description, $isDefault, $isActive, $sortOrder, $loggedInUserId, $loggedInUserId);
                if (!$stmt->execute()) throw new Exception('Unable to create scoring profile: ' . $stmt->error, 500);
                $profileId = (int) $stmt->insert_id;
                $stmt->close();
            }
            $keptIds[] = $profileId;

            if (!$conn->query("DELETE FROM appraisal_profile_section_weights WHERE profile_id = {$profileId}")) {
                throw new Exception('Unable to reset scoring profile weights: ' . $conn->error, 500);
            }
            $weights = is_array($profile['section_weights'] ?? null) ? $profile['section_weights'] : [];
            $weightStmt = $conn->prepare('INSERT INTO appraisal_profile_section_weights (profile_id, section_id, weight) VALUES (?, ?, ?)');
            if (!$weightStmt) throw new Exception('Database error: ' . $conn->error, 500);
            foreach ($sectionRows as $section) {
                $sectionId = (int) $section['id'];
                $raw = $weights[(string) $sectionId] ?? $weights[$sectionId] ?? null;
                if ($raw === null || $raw === '' || !is_numeric($raw)) continue;
                $weight = round((float) $raw, 2);
                $weightStmt->bind_param('iid', $profileId, $sectionId, $weight);
                if (!$weightStmt->execute()) throw new Exception('Unable to save scoring profile section weight: ' . $weightStmt->error, 500);
            }
            $weightStmt->close();
        }

        $removeIds = array_values(array_diff($existingIds, $keptIds));
        if ($removeIds) {
            $idsSql = implode(',', array_map('intval', $removeIds));
            $assigned = scoringFetchOne($conn, "SELECT COUNT(*) AS cnt FROM appraisal_staff_scoring_profiles WHERE cycle_id = {$cycleId} AND profile_id IN ({$idsSql})");
            if ((int)($assigned['cnt'] ?? 0) > 0) {
                throw new Exception('A scoring profile assigned to staff cannot be deleted. Reassign those staff first.', 409);
            }
            if (!$conn->query("DELETE FROM appraisal_scoring_profiles WHERE id IN ({$idsSql}) AND cycle_id = {$cycleId} AND company_id = {$companyId}")) {
                throw new Exception('Unable to remove deleted scoring profiles: ' . $conn->error, 500);
            }
        }

        if (!companyUserWriteIsGranted($setupContext)) {
        $description = scoringEsc($conn, "{$loggedInEmail} updated scoring configuration for {$cycle['title']} ({$cycle['year']}).");
        $conn->query("INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description) VALUES ({$companyId}, {$loggedInUserId}, 'update_cycle_scoring', 'appraisal_cycles', {$cycleId}, '{$description}')");

        }
        companySetupAuditGranted($conn, $userData, $setupContext, $companyId,
            'update_cycle_scoring', 'appraisal_cycles', $cycleId,
            "updated scoring configuration for {$cycle['title']} ({$cycle['year']})");

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    scoringJson('Success', 'Cycle scoring configuration updated successfully.', scoringGetLocalizedConfig($conn, $cycle, $userData));
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    $code = ($code >= 400 && $code <= 599) ? $code : 500;
    scoringJson('Failed', $e->getMessage(), [], $code);
}
