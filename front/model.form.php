<?php

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);

$model = new PluginTarefasModel();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'save');
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'toggle' && $model->getFromDB($id)) {
        if (!PluginTarefasModel::canUpdate()) {
            Html::displayRightError();
        }
        global $DB;
        $DB->update(PluginTarefasModel::getTable(), [
            'is_active' => empty($model->fields['is_active']) ? 1 : 0,
            'date_mod'  => $_SESSION['glpi_currenttime'],
        ], ['id' => $id]);
        Html::redirect('model.php');
    }

    if ($action === 'clone' && $model->getFromDB($id)) {
        if (!PluginTarefasModel::canCreate()) {
            Html::displayRightError();
        }
        $newId = $model->cloneFrom($id);
        if ($newId > 0) {
            Session::addMessageAfterRedirect(__('Modelo clonado.', 'tarefas'));
            Html::redirect('model.form.php?id=' . $newId);
        }
        Session::addMessageAfterRedirect(
            __('Não foi possível clonar o modelo.', 'tarefas'),
            false,
            ERROR
        );
        Html::redirect('model.php');
    }

    if ($action === 'delete' && $model->getFromDB($id)) {
        if (!PluginTarefasModel::canPurge()) {
            Html::displayRightError();
        }
        if ($model->delete(['id' => $id], true)) {
            Session::addMessageAfterRedirect(__('Modelo excluído.', 'tarefas'));
        }
        Html::redirect('model.php');
    }

    $input = [
        'name'                      => $_POST['name'] ?? '',
        'description'               => $_POST['description'] ?? '',
        'webhook_url'               => $_POST['webhook_url'] ?? '',
        'webhook_enabled'           => isset($_POST['webhook_enabled']) ? 1 : 0,
        'schema_json'               => $_POST['schema_json'] ?? '',
        'is_active'                 => isset($_POST['is_active']) ? 1 : 0,
        'has_default_time'          => isset($_POST['has_default_time']) ? 1 : 0,
        'default_time_seconds'      => $_POST['default_time_seconds'] ?? 0,
        'taskcategories_id'         => $_POST['taskcategories_id'] ?? 0,
        'access_mode'               => $_POST['access_mode'] ?? 'all',
        'access_groups_id'          => $_POST['access_groups_id'] ?? [],
        'access_profiles_id'        => $_POST['access_profiles_id'] ?? [],
        'allow_save'                => isset($_POST['allow_save']) ? 1 : 0,
        'automations_json'          => $_POST['automations_json'] ?? '',
    ];

    if ($id > 0 && $model->getFromDB($id)) {
        $input['id'] = $id;
        if ($model->update($input)) {
            Session::addMessageAfterRedirect(__('Modelo atualizado.', 'tarefas'));
            Html::redirect('model.php');
        }
    } else {
        if (!PluginTarefasModel::canCreate()) {
            Html::displayRightError();
        }
        $newId = $model->add($input);
        if ($newId) {
            Session::addMessageAfterRedirect(__('Modelo criado.', 'tarefas'));
            Html::redirect('model.php');
        }
    }
}

$id = (int) ($_GET['id'] ?? 0);
$isNew = true;
$fields = [
    'name'                 => '',
    'description'          => '',
    'webhook_url'          => '',
    'webhook_enabled'      => 1,
    'is_active'            => 1,
    'version'              => 1,
    'default_time_minutes' => 0,
    'taskcategories_id'    => 0,
    'access_mode'          => 'all',
    'access_ids'           => '[]',
    'allow_save'           => 0,
    'automations_json'     => PluginTarefasModel::encodeJson(PluginTarefasAutomation::blank()),
    'schema_json'          => PluginTarefasModel::encodeJson([
        'title'   => '',
        'version' => 1,
        'fields'  => [],
    ]),
];
if ($id > 0 && $model->getFromDB($id)) {
    $fields = array_merge($fields, $model->fields);
    $isNew = false;
}

if ($isNew && !PluginTarefasModel::canCreate()) {
    Html::displayRightError();
}

Html::header(
    $isNew ? __('Novo modelo', 'tarefas') : __('Editar modelo', 'tarefas'),
    $_SERVER['PHP_SELF'],
    'helpdesk',
    PluginTarefasMenu::class
);
?>

<?php
$accessMode = (string) ($fields['access_mode'] ?? 'all');
$accessIds = PluginTarefasModel::decodeIdList((string) ($fields['access_ids'] ?? ''));
?>
<div class="container-xl plugin-tarefas-page" id="plugin-tarefas-builder"
     data-condition-items-url="<?= htmlescape(Plugin::getWebDir('tarefas') . '/ajax/condition_items.php') ?>">
    <form method="post" id="plugin-tarefas-model-form">
        <input type="hidden" name="_glpi_csrf_token"
               value="<?= htmlescape(Session::getNewCSRFToken()) ?>">
        <input type="hidden" name="id" value="<?= $isNew ? 0 : (int) $fields['id'] ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="schema_json" id="schema_json">
        <input type="hidden" name="automations_json" id="automations_json">

        <div class="card mb-3">
            <div class="card-header">
                <div>
                    <h3 class="card-title">
                        <?= htmlescape($isNew ? __('Novo modelo de tarefa', 'tarefas') : __('Editar modelo de tarefa', 'tarefas')) ?>
                    </h3>
                    <div class="text-muted small">
                        <?= htmlescape(
                            $isNew
                                ? __('A primeira versão será v1.', 'tarefas')
                                : sprintf(__('Versão atual v%d; salvar gera a v%d.', 'tarefas'), $fields['version'], $fields['version'] + 1)
                        ) ?>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="row mb-2">
                    <label class="col-md-3 col-form-label col-form-label-sm text-md-end">
                        <?= htmlescape(__('Nome da tarefa', 'tarefas')) ?> <span class="text-danger">*</span>
                    </label>
                    <div class="col-md-7">
                        <input class="form-control" type="text" name="name" id="model_name"
                               required value="<?= htmlescape($fields['name']) ?>">
                    </div>
                </div>
                <div class="row mb-2">
                    <label class="col-md-3 col-form-label col-form-label-sm text-md-end">
                        <?= htmlescape(__('Categoria da tarefa', 'tarefas')) ?>
                    </label>
                    <div class="col-md-7">
                        <?php Dropdown::show(TaskCategory::class, [
                            'name'  => 'taskcategories_id',
                            'value' => (int) ($fields['taskcategories_id'] ?? 0),
                            'width' => '100%',
                        ]); ?>
                    </div>
                </div>
                <div class="row mb-2">
                    <label class="col-md-3 col-form-label col-form-label-sm text-md-end"><?= htmlescape(__('Descrição')) ?></label>
                    <div class="col-md-7">
                        <textarea class="form-control" name="description" rows="2"><?= htmlescape($fields['description']) ?></textarea>
                    </div>
                </div>
                <div class="row mb-2">
                    <label class="col-md-3 col-form-label col-form-label-sm text-md-end">
                        <?= htmlescape(__('Tempo padrão', 'tarefas')) ?>
                    </label>
                    <div class="col-md-7">
                        <div class="plugin-tarefas-inline-control">
                            <label class="form-check form-switch mb-0 plugin-tarefas-inline-switch">
                                <input class="form-check-input" type="checkbox" name="has_default_time"
                                       id="has_default_time" value="1"
                                       <?= (int) ($fields['default_time_minutes'] ?? 0) > 0 ? 'checked' : '' ?>>
                                <span class="form-check-label"><?= htmlescape(__('Usar tempo padrão', 'tarefas')) ?></span>
                            </label>
                            <div id="plugin-tarefas-default-time" class="plugin-tarefas-duration<?= (int) ($fields['default_time_minutes'] ?? 0) > 0 ? '' : ' d-none' ?>">
                                <?php PluginTarefasForm::renderTimeInputs(
                                    (int) ($fields['default_time_minutes'] ?? 0),
                                    'default_time_seconds'
                                ); ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row mb-2">
                    <label class="col-md-3 col-form-label col-form-label-sm text-md-end">
                        <?= htmlescape(__('Quem pode usar', 'tarefas')) ?> <span class="text-danger">*</span>
                    </label>
                    <div class="col-md-7">
                        <div class="plugin-tarefas-inline-control">
                            <select class="form-select" name="access_mode" id="plugin-tarefas-access-mode">
                                <option value="all" <?= $accessMode === 'all' ? 'selected' : '' ?>>
                                    <?= htmlescape(__('Todos', 'tarefas')) ?>
                                </option>
                                <option value="groups" <?= $accessMode === 'groups' ? 'selected' : '' ?>>
                                    <?= htmlescape(__('Somente grupos', 'tarefas')) ?>
                                </option>
                                <option value="profiles" <?= $accessMode === 'profiles' ? 'selected' : '' ?>>
                                    <?= htmlescape(__('Somente perfis', 'tarefas')) ?>
                                </option>
                            </select>
                            <div id="plugin-tarefas-access-groups" class="<?= $accessMode === 'groups' ? '' : 'd-none' ?>">
                                <?php Dropdown::show(Group::class, [
                                    'name'     => 'access_groups_id',
                                    'value'    => $accessMode === 'groups' ? $accessIds : [],
                                    'multiple' => true,
                                    'width'    => '100%',
                                ]); ?>
                            </div>
                            <div id="plugin-tarefas-access-profiles" class="<?= $accessMode === 'profiles' ? '' : 'd-none' ?>">
                                <?php Dropdown::show(Profile::class, [
                                    'name'     => 'access_profiles_id',
                                    'value'    => $accessMode === 'profiles' ? $accessIds : [],
                                    'multiple' => true,
                                    'width'    => '100%',
                                ]); ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row mb-2">
                    <label class="col-md-3 col-form-label col-form-label-sm text-md-end">
                        <?= htmlescape(__('Botões da tarefa', 'tarefas')) ?>
                    </label>
                    <div class="col-md-7">
                        <label class="form-check form-switch mt-1">
                            <input class="form-check-input" type="checkbox" name="allow_save"
                                   value="1" <?= !empty($fields['allow_save']) ? 'checked' : '' ?>>
                            <span class="form-check-label"><?= htmlescape(__('Permitir Salvar (rascunho)', 'tarefas')) ?></span>
                        </label>
                        <div class="form-hint">
                            <?= htmlescape(__('Toda tarefa tem Finalizar, que trava o preenchimento. Com esta opção, o analista também vê Salvar e pode voltar depois. Se houver automações, elas também disparam no Salvar.', 'tarefas')) ?>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <label class="col-md-3 col-form-label col-form-label-sm text-md-end"><?= htmlescape(__('Status')) ?></label>
                    <div class="col-md-7">
                        <label class="form-check form-switch mt-1">
                            <input class="form-check-input" type="checkbox" name="is_active"
                                   value="1" <?= !empty($fields['is_active']) ? 'checked' : '' ?>>
                            <span class="form-check-label"><?= htmlescape(__('Disponível para os analistas', 'tarefas')) ?></span>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">
                <div>
                    <h3 class="card-title"><?= htmlescape(__('Campos do formulário', 'tarefas')) ?></h3>
                    <div class="text-muted small">
                        <?= htmlescape(__('A ordem de criação é a ordem de exibição. Use as setas apenas para corrigir.', 'tarefas')) ?>
                    </div>
                </div>
                <div class="card-actions">
                    <button type="button" class="btn btn-primary" id="add-field">
                        <i class="ti ti-plus"></i> <?= htmlescape(__('Adicionar campo', 'tarefas')) ?>
                    </button>
                </div>
            </div>
            <div class="card-body" id="field-list"></div>
        </div>

        <div class="card mb-3">
            <div class="card-header">
                <div>
                    <h3 class="card-title"><?= htmlescape(__('Automações', 'tarefas')) ?></h3>
                    <div class="text-muted small">
                        <?= htmlescape(__('Cadastre os campos primeiro. Cada automação pode ser sempre (ao usar a tarefa) ou só se os campos baterem. Interna e externa entram na mesma lista.', 'tarefas')) ?>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <label class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" id="automations_enabled"
                           <?= !empty(PluginTarefasAutomation::normalize(
                               $fields['automations_json'] ?? '',
                               (string) ($fields['webhook_url'] ?? ''),
                               !isset($fields['webhook_enabled']) || !empty($fields['webhook_enabled'])
                           )['enabled']) ? 'checked' : '' ?>>
                    <span class="form-check-label"><?= htmlescape(__('Usar automações', 'tarefas')) ?></span>
                </label>
                <div id="plugin-tarefas-automations-wrap" class="d-none">
                    <div id="plugin-tarefas-automations-list"></div>
                    <div class="plugin-tarefas-auto-picker mt-2">
                        <select class="form-select form-select-sm" id="plugin-tarefas-auto-scope">
                            <option value=""><?= htmlescape(__('Tipo da automação', 'tarefas')) ?></option>
                            <option value="external"><?= htmlescape(__('Automação externa', 'tarefas')) ?></option>
                            <option value="internal"><?= htmlescape(__('Automação interna', 'tarefas')) ?></option>
                        </select>
                        <select class="form-select form-select-sm d-none" id="plugin-tarefas-auto-internal">
                            <option value=""><?= htmlescape(__('Tipo interno', 'tarefas')) ?></option>
                            <option value="status"><?= htmlescape(__('Automação de status', 'tarefas')) ?></option>
                            <option value="group"><?= htmlescape(__('Automação de grupo', 'tarefas')) ?></option>
                        </select>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="plugin-tarefas-add-automation">
                            + <?= htmlescape(__('Adicionar', 'tarefas')) ?>
                        </button>
                    </div>
                    <div class="form-hint mt-2">
                        <?= htmlescape(__('A ordem da lista é a ordem de execução. Webhook externo dispara no Finalizar, somente se a automação for “sempre” ou se as regras de campos forem verdadeiras (ex.: Conseguiu falar = Não). Automações internas (status/grupo) disparam no Finalizar e também no Salvar, se o modelo permitir rascunho.', 'tarefas')) ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!$isNew && PluginTarefasModel::usageCount((int) $fields['id']) > 0): ?>
            <div class="alert alert-warning">
                <?= htmlescape(__('Este modelo já foi usado. As tarefas existentes continuarão com o schema congelado da versão original.', 'tarefas')) ?>
            </div>
        <?php endif; ?>

        <div class="d-flex justify-content-end gap-2 mb-5">
            <a class="btn btn-outline-secondary" href="model.php"><?= htmlescape(__('Cancelar')) ?></a>
            <button type="button" class="btn btn-outline-secondary" id="show-json"><?= htmlescape(__('Ver JSON', 'tarefas')) ?></button>
            <button type="button" class="btn btn-outline-primary" id="preview-form"><?= htmlescape(__('Visualizar formulário', 'tarefas')) ?></button>
            <button type="submit" class="btn btn-warning">
                <i class="ti ti-device-floppy"></i> <?= htmlescape(__('Salvar')) ?>
            </button>
        </div>
    </form>
</div>

<div class="modal modal-blur fade" id="field-modal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="field-modal-title"><?= htmlescape(__('Novo campo', 'tarefas')) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="field-modal-body"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= htmlescape(__('Cancelar')) ?></button>
                <button type="button" class="btn btn-warning" id="save-field"><?= htmlescape(__('Salvar campo', 'tarefas')) ?></button>
            </div>
        </div>
    </div>
</div>

<div class="modal modal-blur fade" id="preview-modal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="preview-title"><?= htmlescape(__('Prévia do formulário', 'tarefas')) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="preview-body"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-primary d-none" id="copy-model-json">
                    <i class="ti ti-copy"></i> <?= htmlescape(__('Copiar JSON', 'tarefas')) ?>
                </button>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= htmlescape(__('Fechar')) ?></button>
            </div>
        </div>
    </div>
</div>

<script type="application/json" id="initial-schema"><?=
    str_replace(['<', '>'], ['\u003C', '\u003E'], (string) $fields['schema_json'])
?></script>
<script type="application/json" id="initial-automations"><?=
    str_replace(
        ['<', '>'],
        ['\u003C', '\u003E'],
        PluginTarefasModel::encodeJson(PluginTarefasAutomation::normalize(
            $fields['automations_json'] ?? '',
            (string) ($fields['webhook_url'] ?? ''),
            !isset($fields['webhook_enabled']) || !empty($fields['webhook_enabled'])
        ))
    )
?></script>
<script type="application/json" id="ticket-statuses"><?=
    str_replace(['<', '>'], ['\u003C', '\u003E'], PluginTarefasModel::encodeJson(PluginTarefasAutomation::ticketStatuses()))
?></script>

<?php Html::footer(); ?>
