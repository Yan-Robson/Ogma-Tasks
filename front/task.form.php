<?php

include('../../../inc/includes.php');

global $CFG_GLPI, $DB;

if (!PluginTarefasTask::canCreate()) {
    Html::displayRightError();
}

$task = new PluginTarefasTask();
$taskId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$isEdit = $taskId > 0 && $task->getFromDB($taskId);
$ticketId = $isEdit
    ? (int) $task->fields['tickets_id']
    : (int) ($_GET['tickets_id'] ?? $_POST['tickets_id'] ?? 0);

$ticket = new Ticket();
if (!$ticket->getFromDB($ticketId) || !$ticket->canViewItem()) {
    Html::displayErrorAndDie(__('Chamado inválido ou sem permissão.', 'tarefas'));
}

if (!$isEdit && !PluginTarefasTask::canUsePluginTimelineAction($ticket)) {
    Html::displayErrorAndDie(__('Sem permissão para criar tarefa neste chamado.', 'tarefas'));
}

$ticketUrl = $CFG_GLPI['root_doc'] . '/front/ticket.form.php?id=' . $ticketId;

$modelId = $isEdit
    ? (int) $task->fields['plugin_tarefas_models_id']
    : (int) ($_GET['models_id'] ?? $_POST['plugin_tarefas_models_id'] ?? 0);
$model = new PluginTarefasModel();
$schema = [];
$content = [];
$modelName = '';

if ($isEdit) {
    $schema = PluginTarefasModel::decodeJson((string) $task->fields['schema_json'], []);
    $content = PluginTarefasModel::decodeJson((string) $task->fields['content_json'], []);
    $modelName = (string) $task->fields['model_name'];
} elseif ($modelId > 0 && $model->getFromDB($modelId) && $model->canCurrentUserUse()) {
    $schema = PluginTarefasModel::decodeJson((string) $model->fields['schema_json'], []);
    $modelName = (string) $model->fields['name'];
}

$isDeleting = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete';
$isSaving = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save';
$saveMode = (string) ($_POST['save_mode'] ?? 'finished');

if ($isSaving && !$isEdit) {
    $postedSchema = PluginTarefasModel::decodeJson((string) ($_POST['snapshot_schema_json'] ?? ''), null);
    if (is_array($postedSchema) && !empty($postedSchema['fields'])) {
        $schema = $postedSchema;
    }
    if (trim((string) ($_POST['snapshot_model_name'] ?? '')) !== '') {
        $modelName = trim((string) $_POST['snapshot_model_name']);
    }
}

if ($isEdit && $task->isFinished() && !$isDeleting) {
    Session::addMessageAfterRedirect(
        __('Esta tarefa já foi finalizada e não pode ser alterada.', 'tarefas'),
        false,
        ERROR
    );
    Html::redirect($ticketUrl);
}

if ($isDeleting) {
    if ($isEdit && $task->canDeleteItem() && $task->delete(['id' => $taskId], true)) {
        Session::addMessageAfterRedirect(__('Tarefa removida do chamado.', 'tarefas'));
        Html::redirect($ticketUrl);
    }
    Html::displayErrorAndDie(__('Não foi possível excluir esta tarefa.', 'tarefas'));
}

// A criação acontece dentro do chamado; esta tela só trata gravação e edição.
if (!$isEdit && !$isSaving) {
    Html::redirect($ticketUrl);
}

if ($isSaving) {
    $raw = PluginTarefasForm::rawAnswers($schema, $content);
    $minutes = PluginTarefasForm::postedMinutes('actiontime');
    $input = [
        'tickets_id'               => $ticketId,
        'plugin_tarefas_models_id' => $modelId,
        'time_minutes'             => $minutes,
        'is_private'               => (int) ($_POST['is_private'] ?? 0),
        'users_id_executor'        => (int) ($_POST['users_id_executor'] ?? 0),
        'groups_id'                => (int) ($_POST['groups_id'] ?? 0),
        'content_json'             => PluginTarefasModel::encodeJson($raw),
        'save_mode'                => $saveMode,
        '_automations'             => PluginTarefasAutomation::postedOverrides(),
        'snapshot_schema_json'     => (string) ($_POST['snapshot_schema_json'] ?? ''),
        'snapshot_automations_json'=> (string) ($_POST['snapshot_automations_json'] ?? ''),
        'snapshot_model_version'   => (int) ($_POST['snapshot_model_version'] ?? 0),
        'snapshot_model_name'      => (string) ($_POST['snapshot_model_name'] ?? ''),
        'snapshot_allow_save'      => (int) ($_POST['snapshot_allow_save'] ?? 0),
        'snapshot_taskcategories_id' => (int) ($_POST['snapshot_taskcategories_id'] ?? 0),
        'snapshot_webhook_url'     => (string) ($_POST['snapshot_webhook_url'] ?? ''),
    ];

    $savedId = 0;
    if ($isEdit) {
        $input['id'] = $taskId;
        if ($task->update($input)) {
            $savedId = $taskId;
        }
    } else {
        $savedId = (int) $task->add($input);
    }

    if ($savedId > 0) {
        $saved = new PluginTarefasTask();
        $saved->getFromDB($savedId);
        $savedContent = PluginTarefasModel::decodeJson((string) $saved->fields['content_json'], []);
        $savedSchema = PluginTarefasModel::decodeJson((string) $saved->fields['schema_json'], []);
        $savedContent = PluginTarefasForm::storeUploads($savedId, $ticketId, $savedSchema, $savedContent);

        // Atualização direta para não revalidar placeholders de upload já processados.
        $DB->update(PluginTarefasTask::getTable(), [
            'content_json' => PluginTarefasModel::encodeJson($savedContent),
            'date_mod'     => $_SESSION['glpi_currenttime'],
        ], ['id' => $savedId]);

        PluginTarefasTask::sendWebhook($savedId);
        $finished = (string) ($saved->fields['status'] ?? '') !== 'draft';
        Session::addMessageAfterRedirect(
            $finished
                ? __('Tarefa finalizada no chamado.', 'tarefas')
                : ($isEdit ? __('Rascunho atualizado.', 'tarefas') : __('Rascunho salvo no chamado.', 'tarefas'))
        );
        Html::redirect($ticketUrl);
    }

    // Falha de validação: reexibe o formulário com o que o analista já preencheu.
    $content = $raw;
}

$timeTotal = $isSaving
    ? PluginTarefasForm::postedMinutes('actiontime')
    : ($isEdit ? (int) $task->fields['time_minutes'] : 0);

Html::header(
    $isEdit ? __('Editar tarefa', 'tarefas') : __('Criar tarefa', 'tarefas'),
    $_SERVER['PHP_SELF'],
    'helpdesk',
    'ticket'
);
?>

<div class="container-xl plugin-tarefas-page">
    <div class="card plugin-tarefas-native-skin">
        <div class="card-header">
            <div>
                <h3 class="card-title">
                    <?= htmlescape($isEdit ? __('Editar tarefa do plugin', 'tarefas') : __('Criar tarefa do plugin', 'tarefas')) ?>
                </h3>
                <div class="text-muted small">
                    <?= htmlescape(sprintf(__('Chamado #%d — %s', 'tarefas'), $ticketId, $ticket->fields['name'])) ?>
                </div>
            </div>
        </div>

        <div class="card-body">
            <?php if ($schema === []): ?>
                <div class="alert alert-danger"><?= htmlescape(__('O modelo não está disponível.', 'tarefas')) ?></div>
            <?php else: ?>
                <form method="post" enctype="multipart/form-data"
                      id="plugin-tarefas-task-form" data-ticket-id="<?= $ticketId ?>">
                    <input type="hidden" name="_glpi_csrf_token"
                           value="<?= htmlescape(Session::getNewCSRFToken()) ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?= $isEdit ? $taskId : 0 ?>">
                    <input type="hidden" name="tickets_id" value="<?= $ticketId ?>">
                    <input type="hidden" name="plugin_tarefas_models_id" value="<?= $modelId ?>">

                    <?php PluginTarefasForm::renderBody([
                        'schema'     => $schema,
                        'content'    => $content,
                        'model_name' => $modelName,
                        'user_label' => $isEdit
                            ? sprintf(__('Registrado por %s', 'tarefas'), $task->fields['user_name'])
                            : PluginTarefasForm::currentUserLabel(),
                        'cancel_url'   => $ticketUrl,
                        'minutes'      => $timeTotal,
                        'is_private'   => $isSaving
                            ? (int) ($_POST['is_private'] ?? 0)
                            : (int) ($task->fields['is_private'] ?? 0),
                        'tickets_id'   => $ticketId,
                        'users_id_executor' => $isSaving
                            ? (int) ($_POST['users_id_executor'] ?? Session::getLoginUserID())
                            : (int) ($task->fields['users_id_executor'] ?? Session::getLoginUserID()),
                        'groups_id' => $isSaving
                            ? (int) ($_POST['groups_id'] ?? 0)
                            : (int) ($task->fields['groups_id'] ?? PluginTarefasModel::defaultTicketGroupId($ticket)),
                        'taskcategories_id' => $isEdit
                            ? (int) ($task->fields['taskcategories_id'] ?? 0)
                            : (int) ($model->fields['taskcategories_id'] ?? 0),
                        'taskcategory_name' => PluginTarefasModel::categoryName(
                            $isEdit
                                ? (int) ($task->fields['taskcategories_id'] ?? 0)
                                : (int) ($model->fields['taskcategories_id'] ?? 0)
                        ),
                        'allow_save' => $isEdit
                            ? !empty($task->fields['allow_save'])
                            : (isset($_POST['snapshot_allow_save'])
                                ? !empty($_POST['snapshot_allow_save'])
                                : !empty($model->fields['allow_save'])),
                        'show_model_label' => true,
                        'model_version' => $isEdit
                            ? (int) ($task->fields['model_version'] ?? 0)
                            : (int) ($_POST['snapshot_model_version'] ?? ($model->fields['version'] ?? 1)),
                        'webhook_url' => $isEdit
                            ? (string) ($task->fields['webhook_url'] ?? '')
                            : (string) ($_POST['snapshot_webhook_url'] ?? ($model->fields['webhook_url'] ?? '')),
                        'automations' => PluginTarefasAutomation::normalize(
                            $isEdit
                                ? ($task->fields['automations_json'] ?? '')
                                : (string) ($_POST['snapshot_automations_json'] ?? ($model->fields['automations_json'] ?? '')),
                            $isEdit
                                ? (string) ($task->fields['webhook_url'] ?? '')
                                : (string) ($_POST['snapshot_webhook_url'] ?? ($model->fields['webhook_url'] ?? '')),
                            $isEdit || !empty($model->fields['webhook_enabled'] ?? 1)
                        ),
                    ]); ?>
                </form>
                <?php if ($isEdit && $task->canDeleteItem()): ?>
                    <form method="post" class="mt-2"
                          onsubmit="return confirm('<?= htmlescape(__('Excluir esta tarefa do chamado? O modelo não será alterado.', 'tarefas')) ?>');">
                        <input type="hidden" name="_glpi_csrf_token"
                               value="<?= htmlescape(Session::getNewCSRFToken()) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $taskId ?>">
                        <button class="btn btn-outline-danger" type="submit">
                            <i class="ti ti-trash"></i> <?= htmlescape(__('Excluir tarefa', 'tarefas')) ?>
                        </button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
Html::footer();
