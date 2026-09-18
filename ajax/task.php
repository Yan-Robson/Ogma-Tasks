<?php

include('../../../inc/includes.php');

Session::checkLoginUser();

$ticketId = (int) ($_GET['tickets_id'] ?? 0);
$modelId  = (int) ($_GET['models_id'] ?? 0);

$ticket = new Ticket();
if (!$ticket->getFromDB($ticketId) || !PluginTarefasTask::canUsePluginTimelineAction($ticket)) {
    echo '<div class="alert alert-danger">'
        . htmlescape(__('Sem permissão para criar tarefa neste chamado.', 'tarefas'))
        . '</div>';
    return;
}

$model = new PluginTarefasModel();
if ($modelId <= 0 || !$model->getFromDB($modelId) || !$model->canCurrentUserUse()) {
    echo '<div class="alert alert-warning">'
        . htmlescape(__('O modelo selecionado não está disponível.', 'tarefas'))
        . '</div>';
    return;
}

$schema = PluginTarefasModel::decodeJson((string) $model->fields['schema_json'], []);
if (empty($schema['fields'])) {
    echo '<div class="alert alert-warning">'
        . htmlescape(__('Este modelo não possui campos publicados.', 'tarefas'))
        . '</div>';
    return;
}

$defaultMinutes = (int) ($model->fields['default_time_minutes'] ?? 0);
PluginTarefasForm::renderBody([
    'schema'             => $schema,
    'content'            => [],
    'model_name'         => (string) $model->fields['name'],
    'user_label'         => PluginTarefasForm::currentUserLabel(),
    'minutes'            => $defaultMinutes,
    'has_default_time'   => $defaultMinutes > 0,
    'is_private'         => 0,
    'tickets_id'         => $ticketId,
    'users_id_executor'  => (int) Session::getLoginUserID(),
    'groups_id'          => PluginTarefasModel::defaultTicketGroupId($ticket),
    'taskcategories_id'  => (int) ($model->fields['taskcategories_id'] ?? 0),
    'taskcategory_name'  => PluginTarefasModel::categoryName((int) ($model->fields['taskcategories_id'] ?? 0)),
    'allow_save'         => !empty($model->fields['allow_save']),
    'model_version'      => (int) ($model->fields['version'] ?? 1),
    'webhook_url'        => (string) ($model->fields['webhook_url'] ?? ''),
    'include_visibility' => false,
    'automations'        => PluginTarefasAutomation::normalize(
        $model->fields['automations_json'] ?? '',
        (string) ($model->fields['webhook_url'] ?? ''),
        !empty($model->fields['webhook_enabled'] ?? 1)
    ),
]);
