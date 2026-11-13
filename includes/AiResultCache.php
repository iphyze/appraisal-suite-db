<?php
/**
 * Saved AI results, so the same request on the same data does not spend tokens twice.
 * Use only for outputs that are not personal to the requester (e.g. aggregate analysis).
 * Every function fails quietly: without migration 17 nothing is cached.
 */

function aiCacheKey(array $parts): string
{
    return hash('sha256', json_encode($parts, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE));
}

function aiCacheGet(mysqli $conn, string $feature, int $companyId, string $key): ?array
{
    try {
        $stmt = $conn->prepare('SELECT result_json, created_at FROM ai_result_cache
            WHERE feature_key = ? AND company_id = ? AND cache_key = ? AND expires_at > UTC_TIMESTAMP() LIMIT 1');
        $stmt->bind_param('sis', $feature, $companyId, $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return null;
        $data = json_decode((string)$row['result_json'], true);
        return is_array($data) ? ['data' => $data, 'created_at' => $row['created_at']] : null;
    } catch (Throwable $e) {
        return null;
    }
}

function aiCachePut(mysqli $conn, string $feature, int $companyId, string $key, array $data, int $userId, int $ttlHours = 168): void
{
    try {
        $json = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
        $ttl = max(1, min($ttlHours, 24 * 90));
        $stmt = $conn->prepare("INSERT INTO ai_result_cache (feature_key, company_id, cache_key, result_json, created_by, created_at, expires_at)
            VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? HOUR))
            ON DUPLICATE KEY UPDATE result_json = VALUES(result_json), created_by = VALUES(created_by),
            created_at = VALUES(created_at), expires_at = VALUES(expires_at)");
        $stmt->bind_param('sissii', $feature, $companyId, $key, $json, $userId, $ttl);
        $stmt->execute();
        $stmt->close();
        // Opportunistic clean-up of expired rows.
        $conn->query('DELETE FROM ai_result_cache WHERE expires_at <= UTC_TIMESTAMP() LIMIT 200');
    } catch (Throwable $e) {
        error_log('[AppraisalSuite AI] Result not saved: ' . get_class($e));
    }
}
