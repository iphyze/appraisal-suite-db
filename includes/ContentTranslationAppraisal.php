<?php
/**
 * Read-only localisation of historical appraisal snapshots (Batch 6c).
 *
 * Only whitelisted company-authored English content is eligible. Never
 * translate comments, objective targets, evidence, feedback, reasons, notes,
 * names, codes or other staff-entered content. All stored snapshots remain
 * untouched. Each route calls contentTranslationApplyPaths exactly once after
 * its existing authorisation and data-visibility checks have completed.
 */
require_once __DIR__ . '/ContentTranslationResponse.php';

/** @return array<string,string|array{context:string,into:string}> */
function contentTranslationAppraisalDetailPaths(): array
{
    return [
        'cycle_title' => 'cycle_title',
        'evaluation_statement' => 'evaluation_statement',
        'scoring_profile_label' => 'scoring_profile',
        'staff_department' => ['context' => 'department', 'into' => 'staff_department_display'],
        'supervisor_department' => ['context' => 'department', 'into' => 'supervisor_department_display'],
        // Frozen section scores and both saved question-response tables.
        'section_scores.*.section_label' => 'section',
        'general_responses.*.section_label' => 'section',
        'general_responses.*.question_text' => 'question',
        'kpi_responses.*.section_label' => 'section',
        'kpi_responses.*.question_text' => 'question',
        // Presentation groups include imported appraisals that have no saved
        // response records; reference text also qualifies as a question.
        'sections.*.section_label' => 'section',
        'sections.*.responses.*.question_text' => 'question',
        // Revision JSON is already decoded by apRevisionHistory(), including
        // prior section labels and evaluation statements.
        'revision_history.*.evaluation_statement' => 'evaluation_statement',
        'revision_history.*.section_scores.*.section_label' => 'section',
        // Rated objective before/after evidence (reason/targets/results are
        // employee-authored and intentionally never translated).
        'revision_history.*.objective_changes.*.before.*.question_text' => 'question',
        'revision_history.*.objective_changes.*.locked_plan.*.question_text' => 'question',
        'revision_history.*.objective_changes.*.after.*.question_text' => 'question',
        'revision_history.*.objective_changes.*.difference.added.*.question_text' => 'question',
        'revision_history.*.objective_changes.*.difference.removed.*.question_text' => 'question',
        'revision_history.*.objective_changes.*.difference.changed.*.before.question_text' => 'question',
        'revision_history.*.objective_changes.*.difference.changed.*.after.question_text' => 'question',
        // The decoded saved rating_config_snapshot is for display only.
        // Do not modify the raw snapshot JSON or any calculation inputs.
        'rating_config.rating_bands.*.label' => 'rating_band',
        'rating_config.rating_points.*.label' => 'rating_label',
        'rating_config.rating_points.*.definition' => 'rating_label',
        'rating_config.rating_points.*.evidence_requirement' => 'rating_label',
    ];
}

/** @return array<string,string|array{context:string,into:string}> */
function contentTranslationAppraisalListPaths(): array
{
    return [
        'rows.*.cycle_title' => 'cycle_title',
        'rows.*.evaluation_statement' => 'evaluation_statement',
        'rows.*.staff_department' => ['context' => 'department', 'into' => 'staff_department_display'],
    ];
}

/** @return array<string,string|array{context:string,into:string}> */
function contentTranslationAppraisalCandidatePaths(): array
{
    return [
        'rows.*.evaluation_statement' => 'evaluation_statement',
        'rows.*.scoring_profile_label' => 'scoring_profile',
        'cycle.title' => 'cycle_title',
        'profiles.*.label' => 'scoring_profile',
        'profiles.*.description' => 'scoring_profile',
    ];
}

/** @return array<string,string|array{context:string,into:string}> */
function contentTranslationAppraisalFormPaths(): array
{
    return [
        'appraisal.cycle_title' => 'cycle_title',
        'appraisal.evaluation_statement' => 'evaluation_statement',
        'appraisal.scoring_profile_label' => 'scoring_profile',
        'cycle.title' => 'cycle_title',
        'sections.*.label' => 'section',
        'sections.*.description' => 'section',
        'sections.*.questions.*.question_text' => 'question',
        'objective_change_summary.added.*.question_text' => 'question',
        'objective_change_summary.removed.*.question_text' => 'question',
        'objective_change_summary.sections.*.added.*.question_text' => 'question',
        'objective_change_summary.sections.*.removed.*.question_text' => 'question',
        'objective_change_summary.sections.*.changed.*.before.question_text' => 'question',
        'objective_change_summary.sections.*.changed.*.after.question_text' => 'question',
        'sections.*.questions.*.question_label' => 'question',
        'sections.*.questions.*.anchor_low_text' => 'anchor',
        'sections.*.questions.*.anchor_mid_text' => 'anchor',
        'sections.*.questions.*.anchor_high_text' => 'anchor',
        'rating_config.rating_bands.*.label' => 'rating_band',
        'rating_config.rating_points.*.label' => 'rating_label',
        'rating_config.rating_points.*.definition' => 'rating_label',
        'rating_config.rating_points.*.evidence_requirement' => 'rating_label',
        'scoring_profile.label' => 'scoring_profile',
        // Department remains a source/assignment key in the form.
        'staff.department' => ['context' => 'department', 'into' => 'department_display'],
        'supervisor.department' => ['context' => 'department', 'into' => 'department_display'],
    ];
}
