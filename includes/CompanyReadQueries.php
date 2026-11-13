<?php

/** Prepared read helper: dynamic grant-scoped filters must bind their values. */
function companyReadFetchAll(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Unable to prepare company-scoped read query.', 500);
    }
    if ($types !== '') {
        if (strlen($types) !== count($params)) {
            $stmt->close();
            throw new InvalidArgumentException('Incorrect company-scoped query parameters.');
        }
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function companyReadFetchOne(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    return companyReadFetchAll($conn, $sql, $types, $params)[0] ?? [];
}
