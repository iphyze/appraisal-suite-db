<?php
/**
 * Batch 10a: report-only EN/FR workbook localisation.
 *
 * English is the original export contract. Only strings authored by the app
 * (headings, labels, controlled statuses) and approved configuration content
 * are localised. Employee names, identifiers, comments and notes are never
 * passed to the translation memory. Numeric cells stay numeric.
 */
require_once __DIR__ . '/Locale.php';
require_once __DIR__ . '/ContentTranslation.php';

function exportLocale(array $user): string
{
    return appLocaleForResponse($user);
}

/** Exact, predictable UI vocabulary. An unknown term is left untouched. */
function exportLabel(string $locale, $source): string
{
    $source = (string)$source;
    if ($locale !== 'fr') return $source;
    static $fr = [
        'S/N'=>'N°', 'Full Name'=>'Nom complet', 'First Name'=>'Prénom', 'Last Name'=>'Nom',
        'Staff ID'=>'Matricule', 'Supervisor Staff ID'=>'Matricule du superviseur',
        'Employee Staff ID'=>'Matricule du salarié', 'Employee'=>'Salarié', 'Assigned Employee'=>'Salarié affecté',
        'Supervisor'=>'Superviseur', 'Supervisors'=>'Superviseurs', 'Assigned Staff'=>'Personnel affecté',
        'Supervisor Department'=>'Département du superviseur', 'Employee Department'=>'Département du salarié',
        'Employee Email'=>'E-mail du salarié', 'Email'=>'E-mail', 'Role'=>'Rôle',
        'Staff Type'=>'Type de personnel', 'Staff Scope'=>'Périmètre du personnel',
        'Department'=>'Département', 'Departments'=>'Départements', 'Job Title'=>'Poste',
        'Location'=>'Lieu', 'Company'=>'Entreprise', 'Company Code'=>'Code entreprise',
        'Date of Joining'=>'Date d’entrée', 'Status'=>'Statut', 'Password Change Required'=>'Changement de mot de passe requis',
        'Last Login'=>'Dernière connexion', 'Created At'=>'Créé le', 'Submitted At'=>'Soumis le',
        'Last Updated'=>'Dernière mise à jour', 'Appraisal Year'=>'Année d’évaluation',
        'Duration In Lambert'=>'Ancienneté chez Lambert', 'Scoring Profile'=>'Profil de notation',
        'Original Weighted Score'=>'Score pondéré initial', 'Final Score'=>'Score final',
        'Breach Declared'=>'Incident déclaré', 'Breach Cap'=>'Plafonnement pour incident',
        'Safety Verification'=>'Vérification de sécurité', 'Evaluation Statement'=>'Appréciation',
        'Evaluation'=>'Appréciation', 'Employee Status'=>'Statut du salarié',
        'Management Review'=>'Examen de la direction', 'Employee Feedback'=>'Retour du salarié',
        'Development'=>'Développement', 'Status Upgrade'=>'Changement de statut',
        'Salary Upgrade'=>'Augmentation salariale', 'Supervisor Updates'=>'Modifications du superviseur',
        'Score'=>'Score', 'Weight Used'=>'Pondération utilisée', 'Section Code'=>'Code de section',
        'Section'=>'Section', 'Appraisals'=>'Évaluations', 'Appraised'=>'Évalués',
        'Acknowledged'=>'Accusé de réception effectué', 'Average Score'=>'Score moyen',
        'Average Weighted Score'=>'Score pondéré moyen', 'Count'=>'Nombre', 'Profile'=>'Profil',
        'Metric'=>'Indicateur', 'Value'=>'Valeur', 'Evaluation Band'=>'Niveau d’évaluation',
        'Objective Code'=>'Code d’objectif', 'Objective'=>'Objectif', 'Ratings'=>'Notes',
        'Average Rating'=>'Note moyenne', 'Actual Result Recorded'=>'Résultat réel enregistré',
        'Required Plans'=>'Plans requis', 'Locked'=>'Verrouillés', 'Draft'=>'Brouillon',
        'Missing'=>'Manquants', 'Progress %'=>'Avancement %', 'Category'=>'Catégorie',
        'Review Status'=>'Statut de l’examen', 'Development Need / Theme'=>'Besoin / thème de développement',
        'Cycle'=>'Cycle', 'Appraisal Cycle'=>'Cycle d’évaluation', 'Generated On'=>'Généré le',
        'Note'=>'Remarque', 'Summary'=>'Résumé', 'Supervisor Summary'=>'Synthèse des superviseurs',
        'Report'=>'Rapport', 'Report Field'=>'Champ du rapport',
        'Report Details'=>'Détails du rapport', 'Users Directory'=>'Répertoire des utilisateurs',
        'Management Appraisal Report'=>'Rapport d’évaluation de la direction',
        'Score Distribution'=>'Répartition des scores', 'Scoring Profiles'=>'Profils de notation',
        'Section Performance'=>'Performance par section', 'Objective Performance'=>'Performance par objectif',
        'Objective Setup'=>'Configuration des objectifs', 'Oversight'=>'Supervision',
        'Development Themes'=>'Thèmes de développement', 'Appraisal Details'=>'Détails des évaluations',
        'Appraisals recorded'=>'Évaluations enregistrées', 'Distinct appraised employees'=>'Salariés évalués distincts',
        'Average final score'=>'Score final moyen', 'Awaiting acknowledgement'=>'En attente d’accusé de réception',
        'Open management follow-ups'=>'Suivis de direction ouverts',
        'Responses awaiting resolution'=>'Réponses en attente de décision',
        'Pending safety/integrity verification'=>'Vérifications sécurité/intégrité en attente',
        'Final score band'=>'Tranche du score final', 'Cycle History'=>'Historique des cycles',
        'Year'=>'Année', 'AI Observations'=>'Observations IA', 'Analysis focus'=>'Axe d’analyse',
        'AI-generated observation'=>'Observation générée par l’IA', 'Generated (UTC)'=>'Généré (UTC)',
        'Overall performance'=>'Performance globale', 'Follow-ups'=>'Suivis',
        'Not generated'=>'Non généré', 'No records available'=>'Aucune donnée disponible',
        'Not shown (<3 staff)'=>'Non affiché (moins de 3 salariés)',
        'Suppressed (<3 people)'=>'Masqué (moins de 3 personnes)',
        'Active'=>'Actif', 'Inactive'=>'Inactif', 'Yes'=>'Oui', 'No'=>'Non',
        'Local'=>'Local', 'Expatriate'=>'Expatrié', 'All'=>'Tous',
        'Pending'=>'En attente', 'Unreviewed'=>'Non examiné',
        'Unreviewed - Changed Since Review'=>'Non examiné – modifié depuis l’examen',
        'Follow-up Required'=>'Suivi requis', 'Reviewed'=>'Examiné',
        'Not recorded'=>'Non renseigné', 'Applied'=>'Appliqué', 'Not applied'=>'Non appliqué',
        'Confirmed'=>'Confirmé', 'Dismissed'=>'Écarté',
        'Pending Verification'=>'Vérification en attente',
        'Supervisor Assignments'=>'Affectations des superviseurs',
        'Appraised Employees'=>'Salariés évalués', 'Pending Acknowledgement'=>'Accusés de réception en attente',
        'Objective Plans Locked'=>'Plans d’objectifs verrouillés', 'Objective Plans Required'=>'Plans d’objectifs requis',
        'Objective Setup Progress %'=>'Avancement des objectifs %',
        'Management Follow-up Required'=>'Suivi de direction requis',
        'Management Reviewed'=>'Examinés par la direction',
        'Management Unreviewed/Stale'=>'Non examinés / à actualiser',
        'Appraisals Revised By Supervisors'=>'Évaluations révisées par les superviseurs',
        'Evidence Exceptions'=>'Anomalies de justificatifs',
        'Staff Assignment Records'=>'Affectations de personnel',
        'Includes supervisors with no assignments. Counts reflect records accessible to the exporting user.'
            =>'Inclut les superviseurs sans affectation. Les totaux concernent uniquement les dossiers accessibles à l’utilisateur.',
        'Appraisal Analytics'=>'Analyse des évaluations',
        'AI Management Insights'=>'Analyses IA de la direction',
        'Management access'=>'Accès direction', 'My team only'=>'Mon équipe uniquement',
        'Outstanding'=>'Exceptionnel', 'Exceeds'=>'Dépasse les attentes',
        'Fully Meets'=>'Répond pleinement aux attentes',
        'Needs Improvement'=>'Amélioration nécessaire', 'Unsatisfactory'=>'Insatisfaisant',
        'Unspecified'=>'Non précisé', 'Level not recorded'=>'Niveau non renseigné',
        'Unscored'=>'Non noté', 'Below 2.0'=>'Inférieur à 2,0',
        '2.0–2.99'=>'2,0–2,99', '3.0–3.99'=>'3,0–3,99', '4.0–5.0'=>'4,0–5,0',
        'above'=>'Au-dessus', 'below'=>'En dessous', 'within'=>'Dans la fourchette',
        'none'=>'Aucun', 'Already reviewed'=>'Déjà examiné',
        'Average score'=>'Score moyen', 'Final score band'=>'Tranche de score final',
        'awaiting_ack'=>'En attente d’accusé de réception',
        'Needs verification'=>'Vérification requise',
        'Super Admin'=>'Super administrateur', 'Admin'=>'Administrateur',
        'Staff'=>'Personnel', 'Supervisor'=>'Superviseur',
        'No Current Result'=>'Aucun résultat actuel',
        'not_required'=>'Non requis', 'pending'=>'En attente', 'confirmed'=>'Confirmé',
        'dismissed'=>'Écarté', 'stale'=>'À actualiser', 'reviewed'=>'Examiné',
        'follow_up'=>'Suivi requis', 'unreviewed'=>'Non examiné',
        'approved'=>'Approuvé', 'rejected'=>'Rejeté', 'Requested'=>'Demandé',
        'Above'=>'Au-dessus', 'Below'=>'En dessous', 'Within'=>'Dans la fourchette',
        'None'=>'Aucun', 'Pending review'=>'Examen en attente',
        'Submitted'=>'Soumis', 'Unrated'=>'Non noté',
        'Level not recorded'=>'Niveau non renseigné',
        'Provisional'=>'Provisoire', 'Management'=>'Direction',
        'Personal'=>'Personnel', 'Administrative'=>'Administratif',
        // Analytics exporter headings and controlled labels
        'Overview'=>'Vue d’ensemble', 'Supervisor Rankings'=>'Classement des superviseurs',
        'Staff Levels'=>'Niveaux du personnel', 'Rating Spread'=>'Répartition des notes',
        'Calibration'=>'Étalonnage', 'Progress'=>'Avancement',
        'Supervisor Progress'=>'Avancement des superviseurs', 'Pending Tasks'=>'Tâches en attente',
        'Score Drops'=>'Baisses de score', 'Repeated Outstanding'=>'Excellence répétée',
        'Development Alerts'=>'Alertes de développement',
        'Indicator'=>'Indicateur', 'Rank'=>'Rang', 'Staff level'=>'Niveau du personnel',
        'Rating band'=>'Tranche de note', 'Share (%)'=>'Part ( %)',
        'People'=>'Personnes', 'Lowest Score'=>'Score le plus faible',
        'Highest Score'=>'Score le plus élevé', 'Outstanding (%)'=>'Excellent ( %)',
        'Guideline min (%)'=>'Minimum indicatif ( %)', 'Guideline max (%)'=>'Maximum indicatif ( %)',
        'Position'=>'Position', 'Difference'=>'Écart', 'Below Meets (%)'=>'Sous les attentes ( %)',
        'Review'=>'Examen', 'Stage'=>'Étape', 'Onboarded'=>'Formés', 'Assigned'=>'Affectés',
        'Level needed'=>'Niveau requis', 'Objectives needed'=>'Objectifs requis',
        'Objectives not locked'=>'Objectifs non verrouillés', 'Ready'=>'Prêts',
        'Ready to appraise'=>'Prêt à évaluer', 'Awaiting ack.'=>'En attente d’accusé',
        'Complete'=>'Terminé', 'Complete (%)'=>'Terminé ( %)',
        'Appraisal link'=>'Lien vers l’évaluation', 'Previous Score'=>'Score précédent',
        'Current Score'=>'Score actuel', 'Consecutive years'=>'Années consécutives',
        'Rates high'=>'Note élevée', 'Rates low'=>'Note faible', 'In line'=>'Dans la norme',
        'Insufficient group size'=>'Groupe trop petit',
        'Authorized management records'=>'Dossiers autorisés de la direction',
        'Assigned staff only'=>'Personnel affecté uniquement',
        'Scope'=>'Périmètre', 'Included views'=>'Vues incluses', 'Generated'=>'Généré',
        'Scored appraisals'=>'Évaluations notées', 'Company average score'=>'Score moyen de l’entreprise',
        'Supervisors flagged for calibration'=>'Superviseurs signalés pour étalonnage',
        'Assigned staff'=>'Personnel affecté', 'Appraisals submitted (%)'=>'Évaluations soumises ( %)',
        'Appraisals acknowledged (%)'=>'Évaluations accusées ( %)',
        'Significant score drops'=>'Baisses importantes de score',
        'Outstanding 3+ years'=>'Excellence depuis au moins 3 ans',
        'Development support flags'=>'Alertes d’accompagnement',
        'APPRAISALSUITE  /  ANALYTICS REPORT'=>'APPRAISALSUITE  /  RAPPORT ANALYTIQUE',
        'APPRAISALSUITE  /  AI MANAGEMENT INSIGHTS'=>'APPRAISALSUITE  /  ANALYSES IA DE LA DIRECTION',
        'Calculated from authorized records; no AI tokens used.'
            =>'Calculé à partir des dossiers autorisés, sans utilisation de jetons IA.',
        'Groups below three distinct people have suppressed score statistics.'
            =>'Les statistiques de score sont masquées pour les groupes de moins de trois personnes.',
        'Guidelines are a calibration reference, not a forced ranking.'
            =>'Les repères servent à l’étalonnage et n’imposent pas de classement.',
        'Differences are signals for review, not determinations of unfairness.'
            =>'Les écarts invitent à un examen, sans conclure à une injustice.',
        'Appraisal workflow status for your authorized scope.'
            =>'État du processus d’évaluation dans votre périmètre autorisé.',
        'Each row uses assignments visible in this cycle.'
            =>'Chaque ligne utilise les affectations visibles pour ce cycle.',
        'Read-only snapshot. Lists are limited to the records returned by Analytics.'
            =>'Instantané en lecture seule limité aux dossiers fournis par l’analyse.',
        'Comparisons with earlier frameworks are indicative.'
            =>'Les comparaisons avec les anciens cadres sont indicatives.',
        'Outstanding scores in at least three consecutive appraisal years.'
            =>'Excellents scores pendant au moins trois années d’évaluation consécutives.',
        'People rated below Fully Meets. Review in context.'
            =>'Personnes notées en dessous des attentes. À examiner en contexte.',
        'Authorized appraisal records only'=>'Dossiers d’évaluation autorisés uniquement',
        'Groups with fewer than three staff have no average'=>'Aucune moyenne pour les groupes de moins de trois salariés',
        'Final score bands'=>'Tranches du score final',
        'Indicative only: scoring structures may differ across cycles'
            =>'Données indicatives : les barèmes peuvent varier selon les cycles',
        'Saved interpretations only  |  Review human decisions independently'
            =>'Interprétations enregistrées uniquement  |  Vérifiez les décisions humaines indépendamment',
        'No current saved AI interpretation is available for this cycle. Generate AI insights in the app first.'
            =>'Aucune analyse IA enregistrée n’est disponible pour ce cycle. Générez d’abord une analyse dans l’application.',
    ];
    return $fr[$source] ?? $source;
}

/** Composes translated section headings without modifying immutable section codes. */
function exportSectionHeader(string $locale, string $code, string $label, string $suffix): string
{
    $prefix = trim($code) !== '' ? $code . ' - ' : '';
    return $prefix . $label . ' ' . exportLabel($locale, $suffix);
}

/** Date-like report metadata only: user-written strings are never formatted. */
function exportDate(string $locale, $raw, bool $dateTime = false): string
{
    $raw = (string)$raw;
    if ($locale !== 'fr' || $raw === '') return $raw;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::\d{2})?)?$/D', $raw, $m) !== 1) return $raw;
    if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return $raw;
    return $m[3] . '/' . $m[2] . '/' . $m[1]
        . ($dateTime && isset($m[4]) ? ' ' . $m[4] . ':' . $m[5] : '');
}

/** Leave scores numeric for Excel sorting, formulas and the viewer's decimal separator. */
function exportNumber($raw)
{
    return $raw === null || $raw === '' ? '' : (is_numeric($raw) ? (float)$raw : $raw);
}

/**
 * One approved-translation query per export, irrespective of row count.
 * $groups is a list of ['context' => string, 'values' => list<string>].
 * Returns map keyed by context and original source string; duplicates coalesce.
 */
function exportContentMap(mysqli $conn, string $locale, array $groups): array
{
    if ($locale !== 'fr') return [];
    $items = [];
    foreach ($groups as $group) {
        foreach ($group['values'] ?? [] as $source) {
            $source = (string)$source;
            if (trim($source) === '') continue;
            $context = (string)$group['context'];
            $key = $context . "\0" . $source;
            $items[$key] = ['text' => $source, 'context' => $context];
        }
    }
    if ($items === []) return [];
    return contentTranslationResolveBatch($conn, $items, $locale);
}

function exportContent(array $approved, string $context, $source): string
{
    $source = (string)$source;
    return $approved[$context . "\0" . $source] ?? $source;
}

/** Translate only structural worksheet names/headers; optionally first-column report keys. */
function exportSheetLabels(array $sheets, string $locale, array $firstColumnSheetNames = []): array
{
    if ($locale !== 'fr') return $sheets;
    foreach ($sheets as &$sheet) {
        $sourceName = (string)$sheet['name'];
        $sheet['name'] = exportLabel($locale, $sourceName);
        $sheet['headers'] = array_map(static fn($label) => exportLabel($locale, $label), $sheet['headers'] ?? []);
        if (in_array($sourceName, $firstColumnSheetNames, true)) {
            foreach ($sheet['rows'] as &$row) {
                if (isset($row[0])) $row[0] = exportLabel($locale, $row[0]);
            }
            unset($row);
        }
    }
    unset($sheet);
    return $sheets;
}
