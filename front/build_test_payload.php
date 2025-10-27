<?php
/*
 -------------------------------------------------------------------------
  Webhook plugin for GLPI - Build test payload from a real ticket
 -------------------------------------------------------------------------
*/

$SECURITY_STRATEGY = 'no_check';

include('../../../inc/includes.php');

header('Content-Type: application/json; charset=UTF-8');
Html::header_nocache();

// Evitar poluir a saída JSON com HTML de notices/deprecated
@ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE);

$ticket_id = isset($_REQUEST['ticket_id']) ? (int)$_REQUEST['ticket_id'] : 0;
if ($ticket_id <= 0) {
   echo json_encode([
      'success' => false,
      'error'   => 'ticket_id inválido'
   ]);
   exit;
}

try {
   $ticket = new Ticket();
   if (!$ticket->getFromDB($ticket_id)) {
      echo json_encode([
         'success' => false,
         'error'   => 'Chamado não encontrado'
      ]);
      exit;
   }

Toolbox::logInFile('webhook-test', "[BUILD] Ticket $ticket_id carregado.\n");

// Helper simples - agora a limpeza HTML é feita no JSON string, não aqui
function wh_safe($v) {
   if ($v === null) return null;
   // Retornar valor original - limpeza será feita no JSON final
   return $v;
}

// Mapear campos básicos
$data = [
   'test'       => false,
   'event'      => 'manual_test_from_ticket',
   'timestamp'  => date('c'),
   'ticket'     => [
      'id'          => (int)$ticket->fields['id'],
      'title'       => wh_safe($ticket->fields['name'] ?? ''),
      'description' => wh_safe($ticket->fields['content'] ?? ''),
      'status'      => $ticket->fields['status'] ?? null,
      'urgency'     => $ticket->fields['urgency'] ?? null,
      'impact'      => $ticket->fields['impact'] ?? null,
      'priority'    => $ticket->fields['priority'] ?? null,
      'creationdate'=> $ticket->fields['date'] ?? null,
      'closedate'   => $ticket->fields['closedate'] ?? null,
      'solvedate'   => $ticket->fields['solvedate'] ?? null,
      'category'    => null,
      'url'         => ((isset($CFG_GLPI['root_doc']) ? $CFG_GLPI['root_doc'] : '') . '/front/ticket.form.php?id=' . (int)$ticket->fields['id']),
   ],
];

Toolbox::logInFile('webhook-test', "[BUILD] Campos básicos prontos para ticket $ticket_id.\n");

// Categoria
if (!empty($ticket->fields['itilcategories_id'])) {
   $cat = new ITILCategory();
   if ($cat->getFromDB((int)$ticket->fields['itilcategories_id'])) {
      $data['ticket']['category'] = [
         'id'   => (int)$cat->fields['id'],
         'name' => wh_safe($cat->fields['name'] ?? '')
      ];
   }
}

// Solução (se houver)
if (!empty($ticket->fields['solution'])) {
   $data['ticket']['solution'] = wh_safe($ticket->fields['solution']);
}

// Solução estruturada
if (!empty($ticket->fields['solutiontypes_id']) || !empty($ticket->fields['solution'])) {
   $solType = null;
   if (!empty($ticket->fields['solutiontypes_id'])) {
      $st = new SolutionType();
      if ($st->getFromDB((int)$ticket->fields['solutiontypes_id'])) {
         $solType = $st->fields['name'] ?? null;
      }
   }
   $data['ticket']['solution_detail'] = [
      'type'        => $solType,
      'description' => wh_safe($ticket->fields['solution'] ?? '')
   ];
}

// Usuários ligados ao ticket
$data['ticket']['users'] = [
   'requesters' => [],
   'assignees'  => [],
   'observers'  => []
];

// Tabela de ligação usuários do ticket
// Tipos convencionais: requester=1, assigned=2, observer=3
global $DB;
$iterator = $DB->request([
   'FROM'  => 'glpi_tickets_users',
   'LEFT JOIN' => [
      'glpi_users' => [
         'FKEY' => [
            'glpi_tickets_users' => 'users_id',
            'glpi_users'         => 'id'
         ]
      ]
   ],
   'WHERE' => ['tickets_id' => (int)$ticket->fields['id']],
   'ORDER' => 'glpi_tickets_users.id ASC'
]);

foreach ($iterator as $row) {
   $entry = [
      'id'    => (int)$row['users_id'],
      'name'  => wh_safe($row['name'] ?? ''),
      'email' => wh_safe($row['email'] ?? '')
   ];
   switch ((int)$row['type']) {
      case 1: $data['ticket']['users']['requesters'][] = $entry; break;
      case 2: $data['ticket']['users']['assignees'][]  = $entry; break;
      case 3: $data['ticket']['users']['observers'][]  = $entry; break;
      default: $data['ticket']['users']['observers'][] = $entry; break;
   }
}

Toolbox::logInFile('webhook-test', "[BUILD] Usuários mapeados para ticket $ticket_id.\n");

// Itens do timeline (seguimentos)
$data['ticket']['timeline'] = [];
$it = $DB->request([
   'FROM'  => 'glpi_itilfollowups',
   'WHERE' => ['items_id' => (int)$ticket->fields['id'], 'itemtype' => 'Ticket'],
   'ORDER' => 'date ASC',
   'LIMIT' => 10
]);
foreach ($it as $row) {
   // Autor
   $author = null;
   if (!empty($row['users_id'])) {
      $u = new User();
      if ($u->getFromDB((int)$row['users_id'])) {
         $author = [
            'id'    => (int)$u->fields['id'],
            'name'  => wh_safe($u->fields['name'] ?? ''),
            'email' => wh_safe($u->fields['email'] ?? '')
         ];
      }
   }
   $data['ticket']['timeline'][] = [
      'date'        => $row['date'] ?? null,
      'author'      => $author,
      'is_private'  => (int)($row['is_private'] ?? 0),
      'content'     => wh_safe($row['content'] ?? '')
   ];
}

Toolbox::logInFile('webhook-test', "[BUILD] Timeline coletada para ticket $ticket_id.\n");

   // Encodar primeiro para obter string JSON
   $json_string = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
   
   // Limpar HTML do JSON string (mesma lógica da produção)
   $json_string = preg_replace_callback(
      '/"([^"]+)":\s*"((?:[^"\\\\]|\\\\.)*)"/s',
      function($matches) {
         $key = $matches[1];
         $value = $matches[2];
         
         // Decodificar escapes do JSON primeiro
         $value = stripcslashes($value);
         
         // Limpar HTML
         $clean = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
         $clean = preg_replace('/<br\s*\/?>/i', "\n", $clean);
         $clean = preg_replace('/<\/p>\s*<p[^>]*>/i', "\n\n", $clean);
         $clean = preg_replace('/<\/p>/i', "\n", $clean);
         $clean = preg_replace('/<p[^>]*>/i', '', $clean);
         $clean = preg_replace('/<\/div>\s*<div[^>]*>/i', "\n", $clean);
         $clean = preg_replace('/<\/div>/i', "\n", $clean);
         $clean = preg_replace('/<div[^>]*>/i', '', $clean);
         $clean = strip_tags($clean);
         $clean = preg_replace("/\n{3,}/", "\n\n", $clean);
         $lines = explode("\n", $clean);
         $lines = array_map('trim', $lines);
         $clean = implode("\n", $lines);
         $clean = trim($clean);
         
         // Escapar para JSON
         $clean = addcslashes($clean, "\"\\\n\r\t");
         
         return '"' . $key . '":"' . $clean . '"';
      },
      $json_string
   );
   
   // Decodificar novamente para formatar bonito
   $data_cleaned = json_decode($json_string, true);
   
   echo json_encode([
      'success' => true,
      'payload' => $data_cleaned
   ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

   Toolbox::logInFile('webhook-test', "[BUILD] JSON enviado para ticket $ticket_id.\n");
} catch (Throwable $e) {
   Toolbox::logInFile('webhook-test', "[BUILD][ERROR] Ticket $ticket_id: " . $e->getMessage() . "\n");
   echo json_encode([
      'success' => false,
      'error'   => 'Falha ao montar payload: ' . $e->getMessage()
   ]);
}

