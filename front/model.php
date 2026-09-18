<?php

include('../../../inc/includes.php');

Session::checkRight('ticket', READ);

Html::header(
    __('Plugin Tarefas', 'tarefas'),
    $_SERVER['PHP_SELF'],
    'helpdesk',
    PluginTarefasMenu::class
);

$canManage = Session::haveRight('config', UPDATE);
$canCreate = PluginTarefasModel::canCreate();
$canDelete = PluginTarefasModel::canPurge();
$rows = [];
global $DB;
$iterator = $DB->request([
    'FROM'  => PluginTarefasModel::getTable(),
    'ORDER' => ['name ASC'],
]);
foreach ($iterator as $row) {
    $rows[] = $row;
}
?>

<div class="container-xl plugin-tarefas-page">
    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="card-title"><?= htmlescape(__('Plugin Tarefas', 'tarefas')) ?></h3>
                <div class="text-muted small">
                    <?= htmlescape(__('Modelos de formulários disponíveis para os chamados.', 'tarefas')) ?>
                </div>
            </div>
            <?php if ($canCreate): ?>
                <div class="card-actions">
                    <a class="btn btn-primary" href="model.form.php">
                        <i class="ti ti-plus"></i>
                        <?= htmlescape(__('Novo modelo', 'tarefas')) ?>
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th><?= htmlescape(__('Nome')) ?></th>
                        <th><?= htmlescape(__('Versão', 'tarefas')) ?></th>
                        <th><?= htmlescape(__('Campos', 'tarefas')) ?></th>
                        <th><?= htmlescape(__('Automações', 'tarefas')) ?></th>
                        <th><?= htmlescape(__('Status')) ?></th>
                        <th><?= htmlescape(__('Criado em', 'tarefas')) ?></th>
                        <th><?= htmlescape(__('Atualizado em', 'tarefas')) ?></th>
                        <th><?= htmlescape(__('Usos', 'tarefas')) ?></th>
                        <?php if ($canManage): ?><th></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="9" class="text-center text-muted py-5">
                        <?= htmlescape(__('Nenhum modelo cadastrado.', 'tarefas')) ?>
                    </td></tr>
                <?php endif; ?>

                <?php foreach ($rows as $row):
                    $schema = PluginTarefasModel::decodeJson((string) $row['schema_json'], []);
                    $fieldsCount = count($schema['fields'] ?? []);
                    $usage = PluginTarefasModel::usageCount((int) $row['id']);
                    $automations = PluginTarefasAutomation::normalize(
                        $row['automations_json'] ?? '',
                        (string) ($row['webhook_url'] ?? ''),
                        !array_key_exists('webhook_enabled', $row) || !empty($row['webhook_enabled'])
                    );
                    $autoCount = count($automations['items'] ?? []);
                ?>
                    <tr class="<?= empty($row['is_active']) ? 'plugin-tarefas-model-row--inactive' : '' ?>">
                        <td>
                            <?php if ($canManage): ?>
                                <a href="model.form.php?id=<?= (int) $row['id'] ?>">
                                    <strong><?= htmlescape($row['name']) ?></strong>
                                </a>
                            <?php else: ?>
                                <strong><?= htmlescape($row['name']) ?></strong>
                            <?php endif; ?>
                            <?php if (!empty($row['description'])): ?>
                                <div class="text-muted small"><?= htmlescape($row['description']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge bg-blue-lt">v<?= (int) $row['version'] ?></span></td>
                        <td><?= $fieldsCount ?></td>
                        <td>
                            <?php if ($autoCount > 0): ?>
                                <span class="badge <?= !empty($automations['enabled']) ? 'bg-yellow-lt' : 'bg-secondary-lt' ?>">
                                    <?= $autoCount ?> · <?= htmlescape(!empty($automations['enabled']) ? __('Ativo', 'tarefas') : __('Pausado', 'tarefas')) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= !empty($row['is_active']) ? 'bg-green-lt' : 'bg-orange-lt' ?>">
                                <?= htmlescape(!empty($row['is_active']) ? __('Ativo') : __('Inativo')) ?>
                            </span>
                        </td>
                        <td><?= htmlescape(Html::convDateTime($row['date_creation'])) ?></td>
                        <td><?= htmlescape(Html::convDateTime($row['date_mod'])) ?></td>
                        <td><?= $usage ?></td>
                        <?php if ($canManage): ?>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-primary"
                                   href="model.form.php?id=<?= (int) $row['id'] ?>">
                                    <?= htmlescape(__('Editar')) ?>
                                </a>
                                <form method="post" action="model.form.php" class="d-inline">
                                    <input type="hidden" name="_glpi_csrf_token"
                                           value="<?= htmlescape(Session::getNewCSRFToken()) ?>">
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <input type="hidden" name="action" value="toggle">
                                    <button class="btn btn-sm btn-outline-secondary" type="submit">
                                        <?= htmlescape(!empty($row['is_active']) ? __('Desativar', 'tarefas') : __('Ativar', 'tarefas')) ?>
                                    </button>
                                </form>
                                <?php if ($canCreate): ?>
                                    <form method="post" action="model.form.php" class="d-inline">
                                        <input type="hidden" name="_glpi_csrf_token"
                                               value="<?= htmlescape(Session::getNewCSRFToken()) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                        <input type="hidden" name="action" value="clone">
                                        <button class="btn btn-sm btn-outline-secondary" type="submit">
                                            <?= htmlescape(__('Clonar', 'tarefas')) ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($canDelete): ?>
                                    <form method="post" action="model.form.php" class="d-inline"
                                          onsubmit="return confirm(<?= htmlescape(json_encode(sprintf(__('Excluir o modelo “%s”? Esta ação não desfaz.', 'tarefas'), $row['name']))) ?>);">
                                        <input type="hidden" name="_glpi_csrf_token"
                                               value="<?= htmlescape(Session::getNewCSRFToken()) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <button class="btn btn-sm btn-outline-danger" type="submit">
                                            <?= htmlescape(__('Excluir')) ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php Html::footer(); ?>
