<?php
/*
 -------------------------------------------------------------------------
 Webhook plugin for GLPI
 Copyright (C) 2009-2022 by Eric Feron.
 -------------------------------------------------------------------------

 LICENSE
      
 This file is part of Webhook.

 Webhook is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 at your option any later version.

 Webhook is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with Webhook. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 */

$SECURITY_STRATEGY = 'no_check';

include ('../../../inc/includes.php');

Session::checkLoginUser();

header("Content-Type: application/json; charset=UTF-8");
Html::header_nocache();

function webhookTestResponse(array $payload, int $status = 200): void {
    $payload['new_token'] = Session::getNewCSRFToken();
    http_response_code($status);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$webhook_id = isset($_POST['webhook_id']) ? (int)$_POST['webhook_id'] : 0;
$test_payload = isset($_POST['test_payload']) ? $_POST['test_payload'] : '';

if (!$webhook_id) {
    webhookTestResponse([
        'success' => false,
        'error' => 'Webhook ID não fornecido'
    ], 400);
}

if (empty($test_payload)) {
    webhookTestResponse([
        'success' => false,
        'error' => 'Payload vazio'
    ], 400);
}

$payload_trimmed = trim($test_payload);
// Remover BOM UTF-8 se presente, pois quebra json_decode no PHP
if (strncmp($payload_trimmed, "\xEF\xBB\xBF", 3) === 0) {
    $payload_trimmed = substr($payload_trimmed, 3);
}

// Primeira tentativa: decodificar como JSON direto
$decoded = @json_decode($payload_trimmed, true);
$json_warning = null;

if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
    // Segunda tentativa: tratar payload com escapes literais (\r\n, \" etc.)
    $payload_unescaped = stripcslashes($payload_trimmed);
    // Normalizar quebras de linha para \n
    $payload_unescaped = preg_replace("/\r\n?|\n/", "\n", $payload_unescaped);
    $decoded = @json_decode($payload_unescaped, true);
    if ($decoded !== null && json_last_error() === JSON_ERROR_NONE) {
        $json_warning = 'Payload possuía sequências de escape (\\r\\n, \\" etc.) e foi normalizado antes do envio.';
        // Atualiza payload base para a versão normalizada
        $payload_trimmed = $payload_unescaped;
    } else {
        // Ainda inválido: registrar e seguir com bruto
        Toolbox::logInFile('webhook-test', "[TEST] JSON inválido recebido: " . substr($payload_trimmed, 0, 500) . "\nErro: " . json_last_error_msg() . "\n");
        $json_warning = 'JSON inválido: ' . json_last_error_msg();
    }
}

$config = new PluginWebhookConfig();
if (!$config->getFromDB($webhook_id)) {
    webhookTestResponse([
        'success' => false,
        'error' => 'Webhook não encontrado'
    ], 404);
}

$url = $config->fields['address'];
$headers = ['Content-Type: application/json; charset=UTF-8'];

$secrettype = $config->fields['plugin_webhook_secrettypes_id'];
switch ($secrettype) {
    case 1:
        break;
    case 2:
        // Por compatibilidade, se já vier base64 pronto no campo 'secret', usa direto; senão, codifica
        if (!empty($config->fields['user']) && !empty($config->fields['secret'])) {
            $basic = $config->fields['user'] . ':' . $config->fields['secret'];
            if (base64_encode(base64_decode($config->fields['secret'], true) ?: '') === $config->fields['secret']) {
                // Parece já estar base64 no secret; usa como está
                $headers[] = 'Authorization: Basic ' . $config->fields['secret'];
            } else {
                $headers[] = 'Authorization: Basic ' . base64_encode(htmlspecialchars_decode($basic));
            }
        }
        break;
    case 3:
        if (!empty($config->fields['user']) && !empty($config->fields['secret'])) {
            $auth = base64_encode(
                htmlspecialchars_decode($config->fields['user']) . ':' . 
                htmlspecialchars_decode($config->fields['secret'])
            );
            $headers[] = 'Authorization: Basic ' . $auth;
        }
        break;
    case 4:
        if (!empty($config->fields['secret'])) {
            $headers[] = 'Authorization: Bearer ' . $config->fields['secret'];
        }
        break;
}

$start_time = microtime(true);
$curl = curl_init($url);
curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
curl_setopt($curl, CURLOPT_HEADER, false);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_POST, true);
curl_setopt($curl, CURLOPT_POSTFIELDS, $test_payload);
curl_setopt($curl, CURLOPT_TIMEOUT, 30);
curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);

$payload_to_send = isset($decoded) && is_array($decoded)
    ? json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    : $payload_trimmed;

// Ajusta Content-Length para alguns servidores mais estritos
$headers[] = 'Content-Length: ' . strlen($payload_to_send);
curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
curl_setopt($curl, CURLOPT_POSTFIELDS, $payload_to_send);

$response = curl_exec($curl);
$http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
$curl_error = curl_error($curl);
$curl_errno = curl_errno($curl);
$duration = round(microtime(true) - $start_time, 3);

curl_close($curl);

$result = [
    'success' => ($http_code >= 200 && $http_code < 300 && empty($curl_errno)),
    'http_code' => $http_code,
    'url' => $url,
    'duration' => $duration,
    'payload_length_bytes' => strlen($payload_to_send),
    'payload_sent_preview' => substr($payload_to_send, 0, 200),
    'headers' => array_map(function($h) {
        if (stripos($h, 'Authorization:') === 0) {
            return 'Authorization: [HIDDEN]';
        }
        return $h;
    }, $headers),
    'response' => $response,
];

if ($json_warning) {
    $result['json_warning'] = $json_warning;
}

if (!empty($curl_error)) {
    $result['curl_error'] = $curl_error;
    $result['curl_errno'] = $curl_errno;
}

if (!$result['success']) {
    $result['error'] = sprintf(
        'Falha ao enviar webhook: HTTP %d%s',
        $http_code,
        !empty($curl_error) ? ' - cURL: ' . $curl_error : ''
    );
}

if ($config->fields['debug']) {
    Toolbox::logInFile(
        "webhook-test",
        sprintf(
            "[TEST] Webhook ID %d - URL: %s - Status: %d - Duration: %ss - Success: %s\nPayload: %s\nResponse: %s\n",
            $webhook_id,
            $url,
            $http_code,
            $duration,
            $result['success'] ? 'YES' : 'NO',
            $test_payload,
            $response
        )
    );
}

webhookTestResponse($result);
