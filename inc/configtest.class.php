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
 -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

/**
 * Aba de testes para Webhook Config
 */
class PluginWebhookConfigTest extends CommonGLPI {

   static $rightname = 'plugin_webhook_configuration';

   function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
      if ($item->getType() === 'PluginWebhookConfig') {
         return __('Test', 'webhook');
      }
      return '';
   }

   static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
      if ($item->getType() === 'PluginWebhookConfig') {
         self::showTestForm($item);
      }
      return true;
   }

   static function showTestForm(PluginWebhookConfig $config) {
      global $CFG_GLPI;

      if (empty($config->fields['id'])) {
         echo "<div class='center'>";
         echo "<p class='red'>" . __('Please save the webhook configuration before testing', 'webhook') . "</p>";
         echo "</div>";
         return;
      }

      $webhook_id = (int)$config->fields['id'];
      $csrf_token = Session::getNewCSRFToken();
      $root_doc   = $CFG_GLPI['root_doc'];

      $method = __('N/A');
      $operation = new PluginWebhookOperationType();
      if (!empty($config->fields['plugin_webhook_operationtypes_id'])
          && $operation->getFromDB($config->fields['plugin_webhook_operationtypes_id'])) {
         $method = $operation->fields['name'];
      }

      $secret_type = __('None');
      $secret = new PluginWebhookSecretType();
      if (!empty($config->fields['plugin_webhook_secrettypes_id'])
          && $secret->getFromDB($config->fields['plugin_webhook_secrettypes_id'])) {
         $secret_type = $secret->fields['name'];
      }

        // Lista de eventos será obtida dinamicamente mais abaixo via NotificationEvent::dropdownEvents('Ticket')

      $example_json = json_encode([
         'test'      => true,
         'event'     => 'manual_test',
         'timestamp' => date('c'),
         'ticket'    => [
            'id'          => 999,
            'title'       => 'Teste Manual do Webhook',
            'description' => 'Este é um payload de teste enviado manualmente',
            'status'      => 'em_atendimento',
            'priority'    => 'media',
         ],
         'message' => 'Teste realizado via interface do GLPI'
      ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

      $template_example = <<<'JSON'
{
  "event": "##ticket.action##",
  "timestamp": "##ticket.creationdate##",
  "ticket": {
    "id": "##ticket.id##",
    "title": "##ticket.title##",
    "description": "##ticket.content##",
    "status": "##ticket.status##",
    "priority": "##ticket.priority##",
    "urgency": "##ticket.urgency##",
    "impact": "##ticket.impact##",
    "category": "##ticket.category##",
    "type": "##ticket.type##",
    "requesttype": "##ticket.requesttype##",
    "entity": "##ticket.entity##",
    "location": "##ticket.location##",
    "creationdate": "##ticket.creationdate##",
    "closedate": "##ticket.closedate##",
    "solvedate": "##ticket.solvedate##",
    "lastupdater": "##ticket.lastupdater##",
    "url": "##ticket.url##"
  },
  "requester": {
    "id": "##ticket.openbyuser##",
    "assigned_groups": "##ticket.assigntogroups##",
    "assigned_users": "##ticket.assigntousers##",
    "observer_groups": "##ticket.observergroups##",
    "observer_users": "##ticket.observerusers##"
  },
  "authors": [
##FOREACHauthors##
    {
      "id": "##author.id##",
      "name": "##author.name##",
      "email": "##author.email##",
      "phone": "##author.phone##",
      "phone2": "##author.phone2##",
      "mobile": "##author.mobile##",
      "location": "##author.location##",
      "category": "##author.category##",
      "title": "##author.title##"
    }##ENDFOREACHauthors##
  ],
  "tasks": [
##FOREACHtasks##
    {
      "date": "##task.date##",
      "author": "##task.author##",
      "description": "##task.description##",
      "category": "##task.category##",
      "status": "##task.status##",
      "user": "##task.user##",
      "group": "##task.group##",
      "time": "##task.time##",
      "begin": "##task.begin##",
      "end": "##task.end##",
      "isprivate": "##task.isprivate##"
    }##ENDFOREACHtasks##
  ],
  "followups": [
##FOREACHfollowups##
    {
      "date": "##followup.date##",
      "author": "##followup.author##",
      "description": "##followup.description##",
      "requesttype": "##followup.requesttype##",
      "isprivate": "##followup.isprivate##"
    }##ENDFOREACHfollowups##
  ],
  "solution": {
    "author": "##ticket.solution.author##",
    "type": "##ticket.solution.type##",
    "description": "##ticket.solution.description##"
  },
  "metrics": {
    "number_of_followups": "##ticket.numberoffollowups##",
    "number_of_tasks": "##ticket.numberoftasks##",
    "number_of_documents": "##ticket.numberofdocuments##",
    "total_time": "##ticket.time##",
    "total_cost": "##ticket.totalcost##"
  }
}
JSON;

      echo "<div class='spaced'>";

      echo "<div class='center'>";
      echo "<table class='tab_cadre_fixe'>";
      echo "<tr class='tab_bg_1'><th colspan='4'>" . __('Webhook Information', 'webhook') . "</th></tr>";
      echo "<tr class='tab_bg_2'>";
      echo "<td><strong>" . __('Name') . ":</strong></td>";
      echo "<td>" . Html::entities_deep($config->fields['name']) . "</td>";
      echo "<td><strong>" . __('URL', 'webhook') . ":</strong></td>";
      echo "<td><code>" . Html::entities_deep($config->fields['address']) . "</code></td>";
      echo "</tr>";
      echo "<tr class='tab_bg_2'>";
      echo "<td><strong>" . __('Method', 'webhook') . ":</strong></td>";
      echo "<td>" . Html::entities_deep($method) . "</td>";
      echo "<td><strong>" . __('Authentication Type', 'webhook') . ":</strong></td>";
      echo "<td>" . Html::entities_deep($secret_type) . "</td>";
      echo "</tr>";
      echo "<tr class='tab_bg_2'>";
      echo "<td><strong>" . __('Debug mode', 'webhook') . ":</strong></td>";
      echo "<td>" . Dropdown::getYesNo($config->fields['debug']) . "</td>";
      echo "<td><strong>ID:</strong></td>";
      echo "<td>" . $webhook_id . "</td>";
      echo "</tr>";
      echo "</table>";
      echo "</div>";

      echo "<form id='form_webhook_test' method='post' action='" . $root_doc . "/plugins/webhook/front/test_webhook.php'>";
      echo Html::hidden('webhook_id', ['value' => $webhook_id]);
      echo Html::hidden('_glpi_csrf_token', ['value' => $csrf_token]);

      echo "<table class='tab_cadre_fixe spaced'>";
      echo "<tr class='tab_bg_1'><th colspan='4'>" . __('Test parameters', 'webhook') . "</th></tr>";
        echo "<tr class='tab_bg_2'>";
        echo "<td style='width:20%'><strong>" . __('Ticket', 'webhook') . ":</strong></td>";
        echo "<td style='width:30%'>";
        // Dropdown único com os últimos 5 chamados (id + título)
        $last_options = [];
        $last_selected = '';
        if (isset($GLOBALS['DB'])) {
            $iterator = $GLOBALS['DB']->request([
                'FROM'   => Ticket::getTable(),
                'SELECT' => ['id', 'name'],
                'ORDER'  => 'id DESC',
                'LIMIT'  => 5
            ]);
            foreach ($iterator as $row) {
               $label = sprintf('#%d - %s', $row['id'], Html::entities_deep($row['name']));
               $last_options[$row['id']] = $label;
               if ($last_selected === '') {
                  $last_selected = (string)$row['id'];
               }
            }
        }
        echo "<select name='ticket_id' id='select_ticket' class='form-control' style='max-width:360px'>";
        if (!empty($last_options)) {
            foreach ($last_options as $id => $label) {
               echo "<option value='" . (int)$id . "'" . ($id == $last_selected ? ' selected' : '') . ">" . $label . "</option>";
            }
        } else {
            echo "<option value='' selected>" . __('No recent tickets', 'webhook') . "</option>";
        }
        echo "</select>";
        echo "</td>";
        echo "<td style='width:20%'><strong>" . __('Event', 'webhook') . ":</strong></td>";
        echo "<td style='width:30%'>";
        // Dropdown completo de eventos do GLPI para Ticket
        echo NotificationEvent::dropdownEvents('Ticket', [
           'display' => false,
           'name' => 'event',
           'value' => 'update',
           'class' => 'form-select form-control',
           'display_emptychoice' => false
        ]);
        echo "</td>";
      echo "</tr>";
      echo "<tr class='tab_bg_2'>";
      echo "<td colspan='4' class='center'>";
      echo "<button type='button' class='btn btn-secondary' id='btn_load_from_ticket'><i class='fas fa-database'></i> " . __('Load from ticket', 'webhook') . "</button> ";
      echo "<button type='button' class='btn btn-secondary' id='btn_render_template'><i class='fas fa-magic'></i> " . __('Render template', 'webhook') . "</button> ";
      echo "<button type='button' class='btn btn-secondary' id='btn_reset_template'><i class='fas fa-undo'></i> " . __('Reset template', 'webhook') . "</button>";
      echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'><th colspan='4'>" . __('Template JSON with GLPI tags', 'webhook') . "</th></tr>";
      echo "<tr class='tab_bg_2'>";
      echo "<td colspan='4'>";
        echo Html::textarea([
            'name'       => 'json_template_tags',
            'editor_id'  => 'json_template_tags',
            'rows'       => 14,
            'cols'       => 120,
            'value'      => Html::cleanPostForTextArea($template_example)
        ]);
      echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'><th colspan='4'>" . __('JSON payload to send', 'webhook') . "</th></tr>";
      echo "<tr class='tab_bg_2'>";
      echo "<td colspan='4'>";
        echo Html::textarea([
            'name'       => 'test_payload',
            'editor_id'  => 'test_payload',
            'rows'       => 18,
            'cols'       => 120,
            'value'      => Html::cleanPostForTextArea($example_json)
        ]);
      echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_2'>";
      echo "<td colspan='4' class='center'>";
      echo "<button type='button' class='btn btn-secondary' id='btn_validate_json'><i class='fas fa-check'></i> " . __('Validate JSON', 'webhook') . "</button> ";
      echo "<button type='button' class='btn btn-secondary' id='btn_reset_json'><i class='fas fa-undo'></i> " . __('Reset example', 'webhook') . "</button> ";
      echo "<button type='submit' class='btn btn-primary' id='btn_send_test'><i class='fas fa-paper-plane'></i> " . __('Send test', 'webhook') . "</button>";
      echo "</td>";
      echo "</tr>";
      echo "</table>";

      echo "</form>";
      echo "<div id='webhook_test_result' class='spaced'></div>";
      echo "<div class='spaced'><small>" . __('Diagnostic log:', 'webhook') . " files/_log/webhook-test.log</small></div>";
      echo "</div>";

      $js = <<<'JAVASCRIPT'
jQuery(function($) {
    const $form = $('#form_webhook_test');
    if (!$form.length) {
        return;
    }

    const $payloadField = $('#test_payload');
    const exampleJsonString = $payloadField.val();
    const $templateField = $('#json_template_tags');
    const $csrfField = $form.find('input[name="_glpi_csrf_token"]');
    const csrfMetaEl = document.querySelector('meta[property="glpi:csrf_token"]');

    // Salvar o template padrão original antes de qualquer modificação
    const defaultTemplate = $templateField.val();
    $templateField.data('default', defaultTemplate);

    // Chave localStorage específica para este webhook
    const webhookId = $('input[name="webhook_id"]').val();
    const storageKeyTemplate = 'webhook_test_template_' + webhookId;
    const storageKeyPayload = 'webhook_test_payload_' + webhookId;

    // Restaurar template e payload salvos (se existirem)
    const savedTemplate = localStorage.getItem(storageKeyTemplate);
    const savedPayload = localStorage.getItem(storageKeyPayload);
    
    if (savedTemplate && savedTemplate.trim().length > 0) {
        $templateField.val(savedTemplate);
    }
    if (savedPayload && savedPayload.trim().length > 0) {
        $payloadField.val(savedPayload);
    }

    // Salvar automaticamente ao editar (com debounce)
    let saveTimer;
    $templateField.on('input', function() {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(function() {
            localStorage.setItem(storageKeyTemplate, $templateField.val());
        }, 1000);
    });

    $payloadField.on('input', function() {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(function() {
            localStorage.setItem(storageKeyPayload, $payloadField.val());
        }, 1000);
    });

    function getCsrfToken() {
        if ($csrfField.length && $csrfField.val()) {
            return $csrfField.val();
        }
        return csrfMetaEl ? csrfMetaEl.getAttribute('content') : null;
    }

    function refreshCsrfToken(token) {
        if (!token) {
            return;
        }
        if ($csrfField.length) {
            $csrfField.val(token);
        }
        if (csrfMetaEl) {
            csrfMetaEl.setAttribute('content', token);
        }
    }

    function ajaxHeaders(token) {
        const headers = { 'X-Requested-With': 'XMLHttpRequest' };
        if (token) {
            headers['X-Glpi-Csrf-Token'] = token;
        }
        return headers;
    }

    function parseErrorToken(xhr) {
        if (xhr && xhr.responseText) {
            try {
                const parsed = JSON.parse(xhr.responseText);
                if (parsed.new_token) {
                    refreshCsrfToken(parsed.new_token);
                }
                return parsed;
            } catch (e) {}
        }
        return null;
    }

    // Garantir que o dropdown de eventos seja acessível via id fixo para o JS
    const $eventSelectByName = $('select[name="event"]');
    if ($eventSelectByName.length && !$eventSelectByName.attr('id')) {
        $eventSelectByName.attr('id', 'select_event');
    }

    $('#btn_load_from_ticket').on('click', function() {
        const tid = $('#select_ticket').val();
        if (!tid) {
            alert('Selecione um chamado.');
            return;
        }
        $('#webhook_test_result').html('<div class="alert alert-info"><i class="fas fa-spinner fa-spin"></i> Carregando dados do chamado #' + tid + '...</div>');
        $.ajax({
            url: '/plugins/webhook/front/build_test_payload.php?v=' + Date.now(),
            type: 'GET',
            headers: ajaxHeaders(getCsrfToken()),
            data: { ticket_id: tid },
            dataType: 'json'
        }).done(function(resp) {
            if (resp && resp.success && resp.payload) {
                const pretty = JSON.stringify(resp.payload, null, 2);
                $payloadField.val(pretty);
                localStorage.setItem(storageKeyPayload, pretty);
                $('#webhook_test_result').html('<div class="alert alert-success">Payload carregado do chamado #' + tid + '.</div>');
            } else {
                $('#webhook_test_result').html('<div class="alert alert-danger">Falha ao carregar payload: ' + (resp && resp.error ? resp.error : 'erro desconhecido') + '</div>');
            }
        }).fail(function(xhr, textStatus) {
            parseErrorToken(xhr);
            const body = (xhr && xhr.responseText) ? xhr.responseText.substring(0, 300) : '';
            $('#webhook_test_result').html('<div class="alert alert-danger">Erro ao buscar payload (' + xhr.status + ' - ' + textStatus + ').<br><small>' + $('<div/>').text(body).html() + '</small></div>');
        });
    });

    $('#btn_validate_json').on('click', function() {
        try {
            JSON.parse($payloadField.val());
            alert('✓ JSON válido!');
            $payloadField.css('border', '2px solid green');
            setTimeout(() => $payloadField.css('border', ''), 2000);
        } catch (e) {
            alert('✗ JSON inválido: ' + e.message);
            $payloadField.css('border', '2px solid red');
        }
    });

    $('#btn_reset_json').on('click', function() {
        if (confirm('Deseja resetar o payload para o exemplo padrão?')) {
            $payloadField.val(exampleJsonString);
            localStorage.setItem(storageKeyPayload, exampleJsonString);
        }
    });

    $('#btn_reset_template').on('click', function() {
        if (confirm('Deseja resetar o template para o exemplo padrão?')) {
            const defaultTemplate = $templateField.data('default');
            if (defaultTemplate) {
                $templateField.val(defaultTemplate);
                localStorage.setItem(storageKeyTemplate, defaultTemplate);
            } else {
                // Se não houver default, limpa do storage e recarrega
                localStorage.removeItem(storageKeyTemplate);
                location.reload();
            }
        }
    });

    $('#btn_render_template').on('click', function() {
        const tid = $('#select_ticket').val();
        if (!tid) {
            alert('Selecione um chamado para renderizar o template.');
            return;
        }
        // Buscar valor do template com mais resiliência
        let tpl = $templateField.val();
        if (!tpl || tpl.trim().length === 0) {
            // Tentar via seletor direto caso o jQuery não capture corretamente
            const textarea = document.getElementById('json_template_tags');
            if (textarea) {
                tpl = textarea.value;
            }
        }
        if (!tpl || tpl.trim().length === 0) {
            alert('Informe o Template JSON com tags GLPI.');
            return;
        }
        let ev = $('#select_event').val();
        if (!ev) {
            ev = $('select[name="event"]').val() || 'update';
        }
        const token = getCsrfToken();

        $('#webhook_test_result').html('<div class="alert alert-info"><i class="fas fa-spinner fa-spin"></i> Renderizando template (' + ev + ')...</div>');        function applyTemplateResponse(resp, via) {
            if (resp && resp.new_token) {
                refreshCsrfToken(resp.new_token);
            }
            if (resp && resp.success) {
                let applied = '';
                if (resp.json_valid && resp.rendered_json) {
                    applied = JSON.stringify(resp.rendered_json, null, 2);
                } else if (resp.rendered) {
                    applied = resp.rendered;
                }
                if (!applied) {
                    $('#webhook_test_result').html('<div class="alert alert-warning">Template processado' + via + ', mas não houve saída.</div>');
                    return;
                }
                $payloadField.val(applied);
                if (resp.json_valid) {
                    $('#webhook_test_result').html('<div class="alert alert-success">Template renderizado com sucesso' + via + ' e JSON válido.</div>');
                } else {
                    $('#webhook_test_result').html('<div class="alert alert-warning">Template renderizado' + via + ', porém o resultado não é um JSON válido: ' + (resp.json_error || 'erro de sintaxe') + '</div>');
                }
                // Salvar payload renderizado
                localStorage.setItem(storageKeyPayload, applied);
            } else {
                $('#webhook_test_result').html('<div class="alert alert-danger">Falha ao renderizar' + via + ': ' + (resp && resp.error ? resp.error : 'erro desconhecido') + '</div>');
            }
        }

        $.ajax({
            url: '/plugins/webhook/front/render_template.php?v=' + Date.now(),
            type: 'POST',
            headers: ajaxHeaders(token),
            dataType: 'json',
            data: {
                ticket_id: tid,
                event: ev,
                template: tpl,
                _glpi_csrf_token: token
            }
        }).done(function(resp) {
            applyTemplateResponse(resp, '');
        }).fail(function(xhr, textStatus) {
            parseErrorToken(xhr);
            const body = (xhr && xhr.responseText) ? xhr.responseText.substring(0, 300) : '';

            if (xhr && (xhr.status === 403 || xhr.status === 405)) {
                // 403: token expirado — tenta via GET com token renovado da resposta anterior
                const retryToken = getCsrfToken();
                $.ajax({
                    url: '/plugins/webhook/front/render_template.php?v=' + Date.now()
                        + '&ticket_id=' + encodeURIComponent(tid)
                        + '&event=' + encodeURIComponent(ev)
                        + '&template=' + encodeURIComponent(tpl)
                        + (retryToken ? '&_glpi_csrf_token=' + encodeURIComponent(retryToken) : ''),
                    type: 'GET',
                    headers: ajaxHeaders(retryToken),
                    dataType: 'json'
                }).done(function(resp) {
                    applyTemplateResponse(resp, ' (via GET)');
                }).fail(function(xhr2, textStatus2) {
                    parseErrorToken(xhr2);
                    const body2 = (xhr2 && xhr2.responseText) ? xhr2.responseText.substring(0, 300) : '';
                    $('#webhook_test_result').html('<div class="alert alert-danger">Erro ao renderizar template (fallback GET) (' + xhr2.status + ' - ' + textStatus2 + ').<br><small>' + $('<div/>').text(body2).html() + '</small></div>');
                });
                return;
            }

            $('#webhook_test_result').html('<div class="alert alert-danger">Erro ao renderizar template (' + (xhr ? xhr.status : '??') + ' - ' + textStatus + ').<br><small>' + $('<div/>').text(body).html() + '</small></div>');
        });
    });

    $form.on('submit', function(e) {
        e.preventDefault();

        try {
            JSON.parse($payloadField.val());
        } catch (e) {
            alert('✗ JSON inválido! Corrija antes de enviar: ' + e.message);
            return false;
        }

        const token = getCsrfToken();
        const $sendButton = $('#btn_send_test');
        $sendButton.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Enviando...');
        $('#webhook_test_result').html('<div class="alert alert-info"><i class="fas fa-spinner fa-spin"></i> Enviando requisição...</div>');

        $.ajax({
            url: '/plugins/webhook/front/test_webhook.php?v=' + Date.now(),
            type: 'POST',
            headers: ajaxHeaders(token),
            data: $form.serialize(),
            dataType: 'json'
        }).done(function(response) {
            displayResult(response);
        }).fail(function(xhr, status, error) {
            parseErrorToken(xhr);
            displayResult({
                success: false,
                error: 'Erro na requisição AJAX: ' + error,
                http_code: xhr.status
            });
        }).always(function() {
            $sendButton.prop('disabled', false).html('<i class="fas fa-paper-plane"></i> Enviar Teste');
        });

        return false;
    });

    function displayResult(response) {
        if (response && response.new_token) {
            refreshCsrfToken(response.new_token);
        }

        let html = '<table class="tab_cadre_fixe">';
        html += '<tr class="tab_bg_1"><th colspan="2">Resultado do Teste</th></tr>';

        if (response.success) {
            html += '<tr class="tab_bg_2"><td colspan="2" class="center"><span class="badge badge-success" style="background-color: green; color: white; padding: 5px 10px; border-radius: 3px;"><i class="fas fa-check"></i> Requisição enviada com sucesso!</span></td></tr>';
        } else {
            html += '<tr class="tab_bg_2"><td colspan="2" class="center"><span class="badge badge-danger" style="background-color: red; color: white; padding: 5px 10px; border-radius: 3px;"><i class="fas fa-times"></i> Falha no envio</span></td></tr>';
        }

        html += '<tr class="tab_bg_2">';
        html += '<td><strong>HTTP Status:</strong></td>';
        const statusColor = response.http_code >= 200 && response.http_code < 300 ? 'green' : 'red';
        html += '<td><span style="color: ' + statusColor + ';"><strong>' + (response.http_code || 'N/A') + '</strong></span></td>';
        html += '</tr>';

        if (response.json_warning) {
            html += '<tr class="tab_bg_2">';
            html += '<td valign="top"><strong>Aviso JSON:</strong></td>';
            html += '<td><span style="color:#b36b00;">' + response.json_warning + '</span></td>';
            html += '</tr>';
        }

        if (response.duration) {
            html += '<tr class="tab_bg_2">';
            html += '<td><strong>Tempo de resposta:</strong></td>';
            html += '<td>' + response.duration + ' segundos</td>';
            html += '</tr>';
        }

        if (response.url) {
            html += '<tr class="tab_bg_2">';
            html += '<td><strong>URL:</strong></td>';
            html += '<td><code>' + response.url + '</code></td>';
            html += '</tr>';
        }

        if (response.payload_sent_preview) {
            html += '<tr class="tab_bg_2">';
            html += '<td><strong>Payload enviado (' + (response.payload_length_bytes || '?') + ' bytes):</strong></td>';
            html += '<td><pre style="background: #f5f5f5; padding: 10px; border-radius: 3px; max-height: 150px; overflow: auto;">' + response.payload_sent_preview + '</pre></td>';
            html += '</tr>';
        }

        if (response.headers) {
            html += '<tr class="tab_bg_2">';
            html += '<td valign="top"><strong>Headers enviados:</strong></td>';
            html += '<td><pre style="background: #f5f5f5; padding: 10px; border-radius: 3px; max-height: 150px; overflow: auto;">' + JSON.stringify(response.headers, null, 2) + '</pre></td>';
            html += '</tr>';
        }

        if (response.response) {
            let responseFormatted = response.response;
            try {
                responseFormatted = JSON.stringify(JSON.parse(response.response), null, 2);
            } catch (e) {}
            html += '<tr class="tab_bg_2"><td valign="top"><strong>Resposta:</strong></td><td><pre style="background: #f5f5f5; padding: 10px; border-radius: 3px; max-height: 200px; overflow: auto;">' + responseFormatted + '</pre></td></tr>';
        }

        if (response.error) {
            html += '<tr class="tab_bg_2">';
            html += '<td valign="top"><strong>Erro:</strong></td>';
            html += '<td><span style="color: red;">' + response.error + '</span></td></tr>';
        }

        if (response.curl_error) {
            html += '<tr class="tab_bg_2">';
            html += '<td valign="top"><strong>cURL Error:</strong></td>';
            html += '<td><span style="color: red;">' + response.curl_error + ' (errno: ' + response.curl_errno + ')</span></td></tr>';
        }

        html += '</table>';
        $('#webhook_test_result').html(html);
    }
});
JAVASCRIPT;

      echo Html::scriptBlock($js);
   }
}
?>
