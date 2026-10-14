<?php
// Минимальный тест: чистый PHP + cURL, без загрузки Битрикса.
// Цель — понять, зависает ли именно PHP-cURL на этом сервере,
// или проблема где-то в окружении/загрузке ядра Битрикса.

echo "PHP версия: " . phpversion() . "\n";
echo "cURL версия: " . curl_version()['version'] . "\n";
echo "SSL версия: " . curl_version()['ssl_version'] . "\n";
echo "Начинаю запрос...\n";

$start = microtime(true);

$ch = curl_init("https://router.cheap/v1/messages");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode([
        "model" => "claude-sonnet-5",
        "max_tokens" => 16,
        "messages" => [["role" => "user", "content" => "Reply with OK"]],
    ]),
    CURLOPT_HTTPHEADER => [
        "Content-Type: application/json",
        "x-api-key: test",
        "anthropic-version: 2023-06-01",
        "Accept: application/json",
    ],
    CURLOPT_USERAGENT => "curl/7.88.1",
    CURLOPT_TIMEOUT => 20,
    CURLOPT_VERBOSE => true,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

$elapsed = round(microtime(true) - $start, 2);

echo "\n--- РЕЗУЛЬТАТ ---\n";
echo "Время выполнения: {$elapsed} сек.\n";
echo "HTTP код: {$httpCode}\n";
echo "Ошибка cURL: " . ($error ?: "нет") . "\n";
echo "Ответ: " . ($response ?: "(пусто)") . "\n";