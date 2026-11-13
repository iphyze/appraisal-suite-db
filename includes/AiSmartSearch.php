<?php
/**
 * Deterministic, read-only navigation interpreter for the top-bar search.
 * No generated SQL, external model calls, or untrusted path segments.
 * Every result points to an existing, role-appropriate page.
 */

/** Validate a small, single-line search request before interpreting it. */
function aiSmartSearchQuery($raw): string
{
    if (!is_string($raw) || strlen($raw) > 240 || !preg_match('//u', $raw)) {
        throw new Exception('Enter a valid search request (up to 200 characters).', 422);
    }
    $query = trim(preg_replace('/\s+/u', ' ', $raw));
    $length = preg_match_all('/./us', $query);
    if ($length < 3 || $length > 200 || preg_match('/[\x00-\x1F\x7F]/u', $query)) {
        throw new Exception('Enter a search request between 3 and 200 characters.', 422);
    }
    return $query;
}

/** Convert a small supported French navigation vocabulary to existing English rules.
 * Never produce SQL, routes or department identifiers from the translated text.
 * Unrecognized French searches safely fall back to ordinary keyword search.
 */
function aiSmartSearchCanonicalQuery(string $query): string
{
    // Leave English/proper-name-only searches byte-for-byte untouched.
    if (!preg_match('/\b(?:accus[ée]s?\s+de\s+r[ée]ception|en\s+attente|[ée]valuations?|d[ée]partements?|objectifs?|quels?|quelles?|affiche[rz]?|montre[rz]?|liste[rz]?|trouve[rz]?|avancement|progression|classements?|superviseurs?|pr[êe]ts?|termin[ée]e?s?|manquants?|dans|aux|au|à)\b/iu', $query)) {
        return $query;
    }
    // Longer phrases first. Department resolution uses the ORIGINAL query,
    // so replacements can never mutate a verified department identifier.
    $phrases = [
        '/\baccus[ée]s?\s+de\s+r[ée]ception\b/iu' => 'acknowledgements',
        '/\ben\s+attente\b/iu' => 'pending',
        '/\bnon\s+encore\s+[ée]valu[ée]s?\b/iu' => 'pending appraisals',
        '/\bnon\s+verrouill[ée]s?\b/iu' => 'unlocked',
        '/\bau\s+sein\s+de\b/iu' => 'in',
        '/\b(?:dans|chez|aux|au|à)\b/iu' => 'in',
        '/\b(?:[ée]valuations?|entretiens?\s+annuels?)\b/iu' => 'appraisals',
        '/\b(?:termin[ée]e?s?|achev[ée]e?s?|finalis[ée]e?s?)\b/iu' => 'completed',
        '/\b(?:avancement|progression|progr[èe]s)\b/iu' => 'progress',
        '/\b(?:superviseurs?|responsables?)\b/iu' => 'supervisors',
        '/\b(?:d[ée]partements?|services?)\b/iu' => 'departments',
        '/\b(?:classements?|palmar[èe]s)\b/iu' => 'rankings',
        '/\b(?:meilleurs?|plus\s+[ée]lev[ée]s?)\b/iu' => 'highest',
        '/\b(?:pires?|plus\s+faibles?)\b/iu' => 'lowest',
        '/\b(?:objectifs?)\b/iu' => 'objectives',
        '/\b(?:niveaux?|grades?)\b/iu' => 'levels',
        '/\b(?:manquants?|absents?)\b/iu' => 'missing',
        '/\b(?:pr[êe]ts?|disponibles?)\b/iu' => 'ready',
        '/\b(?:personnel|employ[ée]s?|salari[ée]s?)\b/iu' => 'staff',
        '/\b(?:affiche[rz]?|montre[rz]?|liste[rz]?|trouve[rz]?|voir|consulter)\b/iu' => 'show',
        '/\b(?:quels?|quelles?)\b/iu' => 'which',
        '/\bcombien\s+de\b/iu' => 'how many',
        '/\b(?:mon|ma|mes)\b/iu' => 'my',
        '/\b(?:et|ou|sont|est|les|des|du|de|la|le|l[’\']|qui)\b/iu' => ' ',
    ];
    $canonical = preg_replace(array_keys($phrases), array_values($phrases), $query);
    $canonical = preg_replace('/\bin\s+departments?\b/iu', 'in', $canonical ?? $query);
    return trim(preg_replace('/\s+/u', ' ', $canonical ?? $query) ?? $query);
}

/**
 * Returns a department only if the user named one actually in this company.
 * An unrecognised "in [department]" qualifier must NEVER silently become an
 * unfiltered, company-wide search.
 *
 * @return array{0: ?string, 1: bool} matched department; has a department qualifier
 */
function aiSmartSearchDepartment(string $query, array $departments, array $aliases = []): array
{
    // Aliases are approved French department labels indexed by exact English source.
    $ordered = [];
    foreach ($departments as $name) {
        if (!is_string($name)) continue;
        $ordered[] = ['source' => $name, 'label' => $name];
        if (isset($aliases[$name]) && is_string($aliases[$name]) && trim($aliases[$name]) !== '') {
            $ordered[] = ['source' => $name, 'label' => trim($aliases[$name])];
        }
    }
    usort($ordered, static fn($a, $b) => strlen($b['label']) <=> strlen($a['label']));
    $prefix = '(?:\bin\b|\bwithin\b|\bfrom\b|\bfor\b|\bdepartment\s+(?:of\s+)?|\bthe\s+department\s+of\s+|\bdans\b|\baux\b|\bau\b|\bà\b|\bau\s+sein\s+de\b)';
    foreach ($ordered as $department) {
        $name = trim($department['label']);
        if ($name !== '' && preg_match('/' . $prefix . '\s+(?:the\s+)?(?:department\s+of\s+)?' . preg_quote($name, '/') . '(?![\pL\pN])/iu', $query)) {
            return [$department['source'], true];
        }
    }
    // Explicit location/department qualifiers with an unknown name must not be dropped.
    $hasQualifier = (bool)preg_match('/\b(?:in|within|from|department\s+of|dans|aux|au|à|au\s+sein\s+de)\s+[\pL\pN]/iu', $query);
    return [null, $hasQualifier];
}

/**
 * Pure parser. Unsupported or ambiguous queries return null, so the frontend
 * can continue to use the existing /search/global keyword results.
 */
function aiSmartSearchInterpret(string $query, array $departments, string $role, array $departmentAliases = []): ?array
{
    if (!in_array($role, ['super_admin', 'admin', 'supervisor', 'staff'], true)) return null;
    $rawQuery = $query;
    $query = aiSmartSearchCanonicalQuery($query);

    $ask = '(?:show|find|list|view|open|see|display|who|which|how\s+many|what|check|pending|awaiting|not\s+yet|incomplete|complete|completed|missing|ready|outstanding'
        . '|without|unlocked|not\s+locked|needed|needs?|progress|status|rank|ranking|rankings|top|highest|lowest|best|worst)';
    if (!preg_match('/\b' . $ask . '\b/iu', $query)) return null;

    // Resolve exact source/approved alias *before* any vocabulary conversion.
    // An unknown qualifier must never silently broaden a company-wide search.
    [$department, $hasQualifier] = aiSmartSearchDepartment($rawQuery, $departments, $departmentAliases);
    if (!$hasQualifier) {
        [$department, $hasQualifier] = aiSmartSearchDepartment($query, $departments, $departmentAliases);
    }
    if ($hasQualifier && $department === null) return null;

    $hasAck = (bool)preg_match('/\backnowledg(?:e?ments?|ing|ed|e)\b/iu', $query);
    $hasAppraisal = (bool)preg_match('/\bappraisals?\b|\breviews?\b/iu', $query);
    $hasProgress = (bool)preg_match('/\b(?:progress|tasks?|pending|overdue|outstanding|incomplete|awaiting|not\s+yet)\b/iu', $query);
    $isPending = (bool)preg_match('/\b(?:pending|awaiting|outstanding|incomplete|not\s+yet|still|overdue|unacknowledged)\b/iu', $query);
    $isComplete = (bool)preg_match('/\b(?:completed?|finished|acknowledged)\b/iu', $query);
    $mentionsSupervisors = (bool)preg_match('/\bsupervisors?\b|\bappraisers?\b/iu', $query);

    $stage = null;
    $focus = null;
    $view = null;
    $groupBy = null;

    // Personal acknowledgement requests lead to the user's own, scoped list.
    // Never treat a department-qualified team request as a personal request.
    if ($hasAck && ($role === 'staff' || preg_match('/\bmy\b/iu', $query))) {
        if ($department !== null || !$isPending && !$isComplete) return null;
        $status = $isPending ? 'Pending' : 'Acknowledged';
        return [
            'intent' => 'my_appraisals', 'title' => 'View my appraisals',
            'subtitle' => $status === 'Pending' ? 'Awaiting your acknowledgement' : 'Acknowledged',
            'filters' => ['status' => $status],
            'url' => '/my-appraisals?status=' . rawurlencode($status),
        ];
    }

    if ($hasAppraisal && $isComplete && !$hasAck && in_array($role, ['admin', 'super_admin', 'supervisor'], true)) {
        $filters = ['status' => 'Acknowledged'];
        if ($department !== null) $filters['department'] = $department;
        $path = $role === 'supervisor' ? '/my-team-appraisals' : '/appraisals';
        return [
            'intent' => 'appraisals', 'title' => 'View completed appraisals',
            'subtitle' => $department ? 'Department: ' . $department : 'Acknowledged appraisals',
            'filters' => $filters,
            'url' => $path . '?' . http_build_query($filters, '', '&', PHP_QUERY_RFC3986),
        ];
    }

    if ($hasAck) {
        // Only interpret acknowledgement requests with a clear pending/finished intent.
        if ($isPending) $stage = 'awaiting_ack';
        elseif ($isComplete) $stage = 'complete';
        else return null;
        $view = 'progress';
    } elseif (preg_match('/\b(?:level|grade)\b/iu', $query) && preg_match('/\b(?:missing|needed|pending|without|unassigned)\b/iu', $query)) {
        $view = 'progress';
        $stage = 'level_needed';
    } elseif (preg_match('/\bobjectives?\b/iu', $query) && preg_match('/\b(?:unlocked|not\s+locked|pending|missing|incomplete)\b/iu', $query)) {
        $view = 'progress';
        $stage = 'objectives_needed';
    } elseif ($hasAppraisal && preg_match('/\bready\s+to\s+(?:be\s+)?apprais/iu', $query)) {
        $view = 'progress';
        $stage = 'ready';
    } elseif (($hasAppraisal || $hasProgress) && $isPending) {
        $view = 'progress';
        $stage = 'incomplete'; // The Progress view will filter all three pre-submission stages.
        if ($mentionsSupervisors) $focus = 'supervisors_pending';
    } elseif ($hasAppraisal && $isComplete) {
        $view = 'progress';
        $stage = 'complete';
    } elseif (preg_match('/\b(?:which|rank|ranking|top|highest|lowest|best|worst)\b/iu', $query)
        && preg_match('/\b(?:departments?|supervisors?|levels?|rating(?:s|\s+bands?)?|scores?)\b/iu', $query)) {
        if (!in_array($role, ['admin', 'super_admin'], true)) return null;
        $view = 'rankings';
        $groupBy = preg_match('/\bsupervisors?\b/iu', $query) ? 'supervisor'
            : (preg_match('/\blevels?\b/iu', $query) ? 'level'
                : (preg_match('/\brating\s+bands?\b/iu', $query) ? 'band' : 'department'));
    } elseif (preg_match('/\bprogress\b/iu', $query)) {
        $view = 'progress';
    } elseif ($hasAppraisal && preg_match('/\b(?:show|find|list|view|open|see|display)\b/iu', $query)) {
        // Appraisal-list filters are applied by the existing page in the next batch.
        $path = $role === 'supervisor' ? '/my-team-appraisals' : '/appraisals';
        if ($role === 'staff') return null;
        $filters = $department ? ['department' => $department] : [];
        return [
            'intent' => 'appraisals', 'title' => 'View appraisals',
            'subtitle' => $department ? 'Department: ' . $department : 'Appraisal list',
            'filters' => $filters, 'url' => $path . ($filters ? '?' . http_build_query($filters, '', '&', PHP_QUERY_RFC3986) : ''),
        ];
    } else {
        return null;
    }

    if ($role === 'staff') return null; // Staff do not have access to Analytics.
    $filters = ['view' => $view];
    if ($stage !== null) $filters['stage'] = $stage;
    if ($groupBy !== null) $filters['group_by'] = $groupBy;
    if ($focus !== null) $filters['focus'] = $focus;
    if ($department !== null) $filters['search'] = $department;
    $title = $view === 'rankings' ? 'View appraisal rankings' : 'View appraisal progress';
    return [
        'intent' => $view, 'title' => $title,
        'subtitle' => trim(implode(' · ', array_filter([
            $stage === 'awaiting_ack' ? 'Awaiting acknowledgement' : ($stage === 'incomplete' ? 'Not yet appraised' : ($stage ? str_replace('_', ' ', ucfirst($stage)) : '')),
            $department ? 'Department: ' . $department : '',
            $focus === 'supervisors_pending' ? 'By supervisor' : '',
        ]))),
        'filters' => $filters,
        'url' => '/analytics?' . http_build_query($filters, '', '&', PHP_QUERY_RFC3986),
    ];
}

/** UI labels are not used for filtering; preserve immutable URLs and filter values. */
function aiSmartSearchFrenchResult(array $result): array
{
    $titles = [
        'View my appraisals' => 'Voir mes évaluations',
        'View completed appraisals' => 'Voir les évaluations terminées',
        'View appraisals' => 'Voir les évaluations',
        'View appraisal rankings' => 'Voir le classement des évaluations',
        'View appraisal progress' => 'Voir l’avancement des évaluations',
    ];
    $labels = [
        'Awaiting your acknowledgement' => 'En attente de votre accusé de réception',
        'Acknowledged' => 'Accusé de réception confirmé',
        'Acknowledged appraisals' => 'Évaluations confirmées',
        'Appraisal list' => 'Liste des évaluations',
        'Awaiting acknowledgement' => 'En attente d’accusé de réception',
        'Not yet appraised' => 'Pas encore évalué',
        'By supervisor' => 'Par superviseur',
        'Level needed' => 'Niveau manquant',
        'Objectives needed' => 'Objectifs manquants',
        'Ready' => 'Prêt',
        'Complete' => 'Terminé',
    ];
    $result['title'] = $titles[$result['title'] ?? ''] ?? ($result['title'] ?? '');
    $parts = explode(' · ', (string)($result['subtitle'] ?? ''));
    $result['subtitle'] = implode(' · ', array_map(static function (string $part) use ($labels): string {
        if (str_starts_with($part, 'Department: ')) return 'Département : ' . substr($part, strlen('Department: '));
        return $labels[$part] ?? $part;
    }, $parts));
    return $result;
}
