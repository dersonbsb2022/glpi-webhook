<?php
/*
 -------------------------------------------------------------------------
 Webhook plugin for GLPI - Render JSON template with GLPI tags
 -------------------------------------------------------------------------
*/

include('../../../inc/includes.php');

Session::checkLoginUser();

header('Content-Type: application/json; charset=UTF-8');
Html::header_nocache();

function webhookRenderResponse(array $payload, int $status = 200): void {
   $payload['new_token'] = Session::getNewCSRFToken();
   http_response_code($status);
   echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
   exit;
}

// Evitar poluir saída JSON com notices/deprecated
@ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE);

try {
   $ticket_id = isset($_REQUEST['ticket_id']) ? (int)$_REQUEST['ticket_id'] : 0;
   $event     = isset($_REQUEST['event']) ? (string)$_REQUEST['event'] : 'update';
   $template  = isset($_REQUEST['template']) ? (string)$_REQUEST['template'] : '';
   Toolbox::logInFile('webhook-test', '[RENDER] raw_template_start: ' . substr($template, 0, 200) . "\n");
   if (strpos($template, '\\') !== false) {
      $template = stripcslashes($template);
      Toolbox::logInFile('webhook-test', '[RENDER] template_after_stripcslashes: ' . substr($template, 0, 200) . "\n");
   }

   if ($ticket_id <= 0) {
      webhookRenderResponse(['success' => false, 'error' => 'ticket_id inválido'], 400);
   }
   if ($template === '') {
      webhookRenderResponse(['success' => false, 'error' => 'Template vazio'], 400);
   }

   $ticket = new Ticket();
   if (!$ticket->getFromDB($ticket_id)) {
      webhookRenderResponse(['success' => false, 'error' => 'Chamado não encontrado'], 404);
   }

   // Preparar NotificationTarget com opções adequadas
   $options = [
      'entities_id'       => $ticket->fields['entities_id'] ?? 0,
      'additionnaloption' => [
         'usertype'     => NotificationTarget::GLPI_USER,
         'show_private' => true,
         'is_self_service' => false,
      ],
   ];

   /** @var NotificationTarget $target */
   $target = NotificationTarget::getInstance($ticket, $event, $options);
   if (!$target) {
      webhookRenderResponse(['success' => false, 'error' => 'Falha ao inicializar NotificationTarget para Ticket'], 500);
   }

   // Alguns eventos (ex.: alertnotclosed) não geram dados individuais (##ticket.id##, etc.).
   // Para a experiência de teste baseada em um ticket específico, forçamos 'update' quando necessário.
   $unsupported_events = ['alertnotclosed'];
   $event_used = in_array($event, $unsupported_events, true) ? 'update' : $event;
   if ($event_used !== $event) {
      Toolbox::logInFile('webhook-test', sprintf('[RENDER] Ajustando evento %s -> %s para ticket %d', $event, $event_used, $ticket_id) . "\n");
   }

   // Obter dados no formato de template (com tags ##...## e arrays para FOREACH)
   $data = $target->getForTemplate($event_used, $options);

   // Processar o template com motor nativo (IF/FOREACH/tags)
   $rendered = NotificationTemplate::process($template, $data);

   // Normalizar finais de linha para \n (alguns endpoints exigem)
   $rendered_normalized = preg_replace("/\r\n?|\n/", "\n", $rendered);

   // Substituir tags não processadas (##campo.xyz##) por strings vazias
   // para evitar JSON inválido quando o campo não tem valor
   $rendered_normalized = preg_replace('/##[^#]+##/', '', $rendered_normalized);

   // Validar se a saída é JSON
   $json_valid = false;
   $json_error = null;
   $rendered_pretty = null;
   $decoded = json_decode($rendered_normalized, true);
   if (json_last_error() === JSON_ERROR_NONE) {
      $json_valid = true;
      $rendered_pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
   } else {
      $json_error = json_last_error_msg();
   }

   // Log opcional para troubleshooting
   Toolbox::logInFile(
      'webhook-test',
      sprintf('[RENDER] ticket %d, event %s, json_valid=%s, sample=%s', $ticket_id, $event, $json_valid ? 'yes' : 'no', substr($rendered_normalized, 0, 200)) . "\n"
   );

   webhookRenderResponse([
      'success'          => true,
      'ticket_id'        => $ticket_id,
      'event'            => $event_used,
      'event_original'   => $event,
      'json_valid'       => $json_valid,
      'json_error'       => $json_error,
      'rendered_json'    => $json_valid ? $decoded : null,
      'rendered'         => $rendered_normalized,
      'rendered_pretty'  => $rendered_pretty,
   ]);
} catch (Throwable $e) {
   Toolbox::logInFile('webhook-test', '[RENDER][ERROR] ' . $e->getMessage() . "\n");
   webhookRenderResponse([
      'success' => false,
      'error'   => 'Falha ao renderizar template: ' . $e->getMessage(),
   ], 500);
}

?>
