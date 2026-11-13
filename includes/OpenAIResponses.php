<?php
/** Minimal HTTPS client for OpenAI Responses API; no additional Composer SDK. */
require_once __DIR__ . '/AiFoundation.php';

function aiExtractResponseText(array $response): string
{
    $pieces = [];
    foreach (($response['output'] ?? []) as $item) {
        if (!is_array($item) || ($item['type'] ?? '') !== 'message') continue;
        foreach (($item['content'] ?? []) as $content) {
            if (is_array($content) && ($content['type'] ?? '') === 'output_text' && is_string($content['text'] ?? null)) {
                $pieces[] = $content['text'];
            }
        }
    }
    return trim(implode("\n", $pieces));
}

function aiAskOpenAI(string $message, string $role): array
{
    aiRequireAvailable();
    $apiKey = aiEnv('OPENAI_API_KEY');
    $model = aiEnv('OPENAI_MODEL', 'gpt-4.1-mini');
    $payload = [
        'model' => $model,
        'instructions' => aiInstructions($role),
        'input' => $message,
        'store' => false,
        'max_output_tokens' => 600,
    ];
    $handle = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $raw = curl_exec($handle);
    $httpStatus = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $curlError = $raw === false ? curl_errno($handle) : 0;
    curl_close($handle);

    if ($raw === false || $curlError !== 0) {
        error_log('[AppraisalSuite AI] OpenAI transport error category: ' . $curlError);
        throw new Exception('AI is temporarily unavailable. Please try again later.', 502);
    }
    if ($httpStatus < 200 || $httpStatus >= 300) {
        // Do not expose provider error bodies, credentials or raw requests.
        error_log('[AppraisalSuite AI] OpenAI responded with HTTP ' . $httpStatus);
        throw new Exception('AI is temporarily unavailable. Please try again later.', $httpStatus === 429 ? 503 : 502);
    }
    try {
        $response = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        throw new Exception('The AI service returned an unexpected response.', 502);
    }
    if (!is_array($response) || ($response['status'] ?? '') !== 'completed') {
        throw new Exception('The AI response was incomplete. Please try again.', 502);
    }
    $answer = aiExtractResponseText($response);
    if ($answer === '') {
        throw new Exception('The AI service did not return an answer.', 502);
    }
    return [
        'answer' => $answer,
        'usage' => [
            'input_tokens' => (int)($response['usage']['input_tokens'] ?? 0),
            'output_tokens' => (int)($response['usage']['output_tokens'] ?? 0),
        ],
    ];
}
