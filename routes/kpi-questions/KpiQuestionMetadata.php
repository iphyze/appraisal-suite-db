<?php

function kpiOptionalText($value, int $maxLength = 0): ?string {
    if ($value === null) return null;
    $text = trim((string) $value);
    if ($text === '') return null;

    if ($maxLength > 0) {
        $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if ($length > $maxLength) {
            throw new Exception("Text value is too long. Maximum length is {$maxLength} characters.", 400);
        }
    }

    return $text;
}

function kpiObjectiveMetadata(array $row, int $rowNumber = 1): array {
    $objectiveCode = kpiOptionalText($row['objective_code'] ?? null, 50);
    $measureTarget = kpiOptionalText($row['measure_target'] ?? null);
    $evidenceSource = kpiOptionalText($row['evidence_source'] ?? null, 500);
    $reviewNote = kpiOptionalText($row['review_note'] ?? null, 500);

    $reviewStatus = strtolower(trim((string)($row['review_status'] ?? 'ready')));
    if (!in_array($reviewStatus, ['ready', 'needs_review'], true)) {
        throw new Exception("Review status on row {$rowNumber} must be either 'ready' or 'needs_review'.", 400);
    }

    return [
        'objective_code' => $objectiveCode,
        'measure_target' => $measureTarget,
        'evidence_source' => $evidenceSource,
        'review_status' => $reviewStatus,
        'review_note' => $reviewNote,
    ];
}
