<?php

function gqFrameworkNormalize(array $row, int $index): array
{
    $numericOrNull = static function ($value, string $label) use ($index) {
        if ($value === null || $value === '') return null;
        if (!is_numeric($value)) throw new Exception("{$label} must be numeric on row " . ($index + 1) . '.', 400);
        return round((float)$value, 3);
    };

    $profileIds = [];
    if (isset($row['profile_ids'])) {
        if (!is_array($row['profile_ids'])) throw new Exception("Profile applicability must be an array on row " . ($index + 1) . '.', 400);
        $profileIds = array_values(array_unique(array_filter(array_map('intval', $row['profile_ids']), fn($id) => $id > 0)));
    }

    return [
        'question_code' => trim((string)($row['question_code'] ?? '')) ?: null,
        'question_label' => trim((string)($row['question_label'] ?? '')) ?: null,
        'anchor_low_value' => $numericOrNull($row['anchor_low_value'] ?? null, 'Low anchor value'),
        'anchor_low_text' => trim((string)($row['anchor_low_text'] ?? '')) ?: null,
        'anchor_mid_value' => $numericOrNull($row['anchor_mid_value'] ?? null, 'Middle anchor value'),
        'anchor_mid_text' => trim((string)($row['anchor_mid_text'] ?? '')) ?: null,
        'anchor_high_value' => $numericOrNull($row['anchor_high_value'] ?? null, 'High anchor value'),
        'anchor_high_text' => trim((string)($row['anchor_high_text'] ?? '')) ?: null,
        'profile_ids' => $profileIds,
    ];
}

function gqFrameworkSaveProfileRules(mysqli $conn, int $questionId, int $companyId, int $cycleId, array $profileIds): void
{
    $delete = $conn->prepare('DELETE FROM general_question_scoring_profiles WHERE general_question_id=?');
    if (!$delete) throw new Exception('Database error: ' . $conn->error, 500);
    $delete->bind_param('i', $questionId);
    if (!$delete->execute()) throw new Exception('Unable to reset question profile applicability: ' . $delete->error, 500);
    $delete->close();

    if (!$profileIds) return;

    $check = $conn->prepare('SELECT id FROM appraisal_scoring_profiles WHERE id=? AND company_id=? AND cycle_id=? AND is_active=1 LIMIT 1');
    $insert = $conn->prepare('INSERT INTO general_question_scoring_profiles (general_question_id, profile_id) VALUES (?, ?)');
    if (!$check || !$insert) throw new Exception('Database error: ' . $conn->error, 500);

    foreach ($profileIds as $profileId) {
        $profileId = (int)$profileId;
        $check->bind_param('iii', $profileId, $companyId, $cycleId);
        $check->execute();
        if ($check->get_result()->num_rows === 0) {
            throw new Exception("Scoring profile ID {$profileId} is not active for the selected cycle.", 409);
        }
        $insert->bind_param('ii', $questionId, $profileId);
        if (!$insert->execute()) throw new Exception('Unable to save question profile applicability: ' . $insert->error, 500);
    }
    $check->close();
    $insert->close();
}

function gqFrameworkAttachProfileIds(mysqli $conn, array &$questions): void
{
    if (!$questions) return;
    $ids = array_values(array_unique(array_filter(array_map(fn($row) => (int)($row['id'] ?? 0), $questions))));
    if (!$ids) return;

    $idSql = implode(',', $ids);
    $result = $conn->query("SELECT general_question_id, profile_id FROM general_question_scoring_profiles WHERE general_question_id IN ({$idSql}) ORDER BY profile_id ASC");
    if (!$result) throw new Exception('Database error: ' . $conn->error, 500);
    $map = [];
    while ($row = $result->fetch_assoc()) $map[(int)$row['general_question_id']][] = (int)$row['profile_id'];
    foreach ($questions as &$question) $question['profile_ids'] = $map[(int)$question['id']] ?? [];
    unset($question);
}
