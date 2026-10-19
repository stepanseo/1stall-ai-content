<?php
/**
 * call_api.php
 *
 * Автономный обработчик запроса к router.cheap, БЕЗ загрузки ядра Битрикса.
 * Не запускается напрямую пользователем — вызывается как подпроцесс из
 * ai_generate_descriptions.php через proc_open(), чтобы обойти зависание
 * cURL, возникающее из-за окружения Битрикса (прямой curl_exec() внутри
 * процесса с загруженным ядром Битрикса подвисает на долгих генерациях —
 * см. callClaudeAPIAttempt() в ai_generate_descriptions.php; в этом же
 * подпроцессе, без Битрикса, тот же запрос проходит нормально).
 *
 * Аргумент: URL шлюза (основной или резервный адрес router.cheap). Если не
 * передан — используется основной адрес по умолчанию.
 * Вход:  JSON-payload на STDIN — то, что отправляется в теле запроса к
 *        /v1/messages (модель, max_tokens, messages, "stream": true).
 * Выход: JSON на STDOUT вида {"text": "..."} либо {"error": "..."}.
 *
 * Стриминг (SSE) обязателен, как и раньше в основном процессе: без него
 * шлюз router.cheap буферизует весь ответ и виснет на генерациях,
 * требующих заметного времени — поэтому здесь воспроизводится та же логика
 * разбора data:-событий построчно, что раньше была в callClaudeAPIAttempt()
 * основного скрипта.
 */

$apiKey = getenv("ROUTER_CHEAP_API_KEY") ?: "";

// Если по какой-то причине переменная окружения не доходит до подпроцесса,
// можно временно прописать ключ прямо здесь (одна строка, без define):
// $apiKey = $apiKey ?: "sk-твой_новый_ключ_сюда";

$url = $argv[1] ?? "https://router.cheap/v1/messages";
$payload = stream_get_contents(STDIN);

if (empty($apiKey)) {
    echo json_encode(["error" => "ROUTER_CHEAP_API_KEY не задан в окружении подпроцесса"]);
    exit(1);
}

$accumulatedText = "";
$sseBuffer = "";
$streamError = "";
// Сырое тело ответа копим отдельно от SSE-парсинга: при 401/403/429 и
// подобных ошибках шлюз обычно отдаёт обычный JSON (не data:-события),
// и без этой копии видно только код ошибки без текста причины.
$rawBody = "";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        "Content-Type: application/json",
        "x-api-key: " . $apiKey,
        "anthropic-version: 2023-06-01",
        "Accept: text/event-stream",
    ],
    CURLOPT_USERAGENT => "curl/7.88.1",
    CURLOPT_TIMEOUT => 180,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    // Обрабатываем ответ по мере поступления чанков, а не ждём его целиком
    // (CURLOPT_RETURNTRANSFER здесь не используем) — это и позволяет
    // избежать буферизации долгих генераций на стороне шлюза.
    CURLOPT_WRITEFUNCTION => function ($curlHandle, $chunk) use (&$accumulatedText, &$streamError, &$sseBuffer, &$rawBody) {
        if (strlen($rawBody) < 2000) {
            $rawBody .= $chunk;
        }
        $sseBuffer .= $chunk;

        $lastNewlinePos = strrpos($sseBuffer, "\n");
        if ($lastNewlinePos === false) {
            return strlen($chunk);
        }

        $completeLines = substr($sseBuffer, 0, $lastNewlinePos);
        $sseBuffer = substr($sseBuffer, $lastNewlinePos + 1);

        foreach (explode("\n", $completeLines) as $line) {
            $line = trim($line);
            if (strpos($line, "data:") !== 0) {
                continue;
            }
            $jsonStr = trim(substr($line, 5));
            if ($jsonStr === "" || $jsonStr === "[DONE]") {
                continue;
            }
            $event = json_decode($jsonStr, true);
            if (!is_array($event)) {
                continue;
            }
            if (($event["type"] ?? "") === "content_block_delta"
                && ($event["delta"]["type"] ?? "") === "text_delta") {
                $accumulatedText .= $event["delta"]["text"];
            }
            if (($event["type"] ?? "") === "error") {
                $streamError = $event["error"]["message"] ?? "unknown stream error";
            }
        }
        return strlen($chunk);
    },
]);

$success = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

$rawBodySnippet = trim(preg_replace('/\s+/', ' ', substr($rawBody, 0, 500)));

if ($curlError) {
    echo json_encode(["error" => "cURL error: " . $curlError]);
    exit(1);
}

if ($streamError) {
    echo json_encode(["error" => "API stream error: " . $streamError . ($rawBodySnippet !== "" ? " | тело: " . $rawBodySnippet : "")]);
    exit(1);
}

if ($httpCode !== 200) {
    echo json_encode(["error" => "API error ({$httpCode}), накоплено символов: " . strlen($accumulatedText) . ($rawBodySnippet !== "" ? " | тело: " . $rawBodySnippet : "")]);
    exit(1);
}

if (trim($accumulatedText) === "") {
    echo json_encode(["error" => "Пустой ответ от API (стрим завершился без текста)"]);
    exit(1);
}

echo json_encode(["text" => $accumulatedText], JSON_UNESCAPED_UNICODE);
