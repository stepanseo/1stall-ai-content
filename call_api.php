<?php
/**
 * call_api.php
 *
 * Автономный обработчик запроса к router.cheap, БЕЗ загрузки ядра Битрикса.
 * Не запускается напрямую пользователем — вызывается как подпроцесс из
 * ai_generate_descriptions.php через proc_open(), чтобы обойти зависание
 * cURL, возникающее из-за окружения Битрикса.
 *
 * Вход:  JSON-payload на STDIN (то, что обычно отправляется в теле запроса
 *        к /v1/messages — модель, max_tokens, messages).
 * Выход: JSON на STDOUT вида {"text": "..."} либо {"error": "..."}.
 */

$apiKey = getenv("ROUTER_CHEAP_API_KEY") ?: "";

// Если по какой-то причине переменная окружения не доходит до подпроцесса,
// можно временно прописать ключ прямо здесь (одна строка, без define):
// $apiKey = $apiKey ?: "sk-твой_новый_ключ_сюда";

$payload = stream_get_contents(STDIN);

if (empty($apiKey)) {
    echo json_encode(["error" => "ROUTER_CHEAP_API_KEY не задан в окружении подпроцесса"]);
    exit(1);
}

$ch = curl_init("https://router.cheap/v1/messages");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        "Content-Type: application/json",
        "x-api-key: " . $apiKey,
        "anthropic-version: 2023-06-01",
        "Accept: application/json",
    ],
    CURLOPT_USERAGENT => "curl/7.88.1",
    CURLOPT_TIMEOUT => 90,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, // на случай проблем с IPv6-маршрутом
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo json_encode(["error" => "cURL error: " . $error]);
    exit(1);
}

$data = json_decode($response, true);

if ($httpCode !== 200) {
    echo json_encode(["error" => "API error ({$httpCode}): " . ($data["error"]["message"] ?? $response)]);
    exit(1);
}

$text = "";
foreach ($data["content"] ?? [] as $block) {
    if ($block["type"] === "text") {
        $text .= $block["text"];
    }
}

echo json_encode(["text" => $text]);