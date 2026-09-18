<?php

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);

global $DB;

$configUrl = Plugin::getWebDir('tarefas') . '/front/config.php';

if (!$DB->tableExists(PluginTarefasConfig::getTable())) {
    Html::displayErrorAndDie(
        __('Atualize o plugin Tarefas (Configurar → Plug-ins) antes de abrir esta página.', 'tarefas')
    );
}

$config = PluginTarefasConfig::get();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // GLPI 11 valida CSRF no CheckCsrfListener (plugin csrf_compliant) — não chamar checkCSRF() aqui.
    if (PluginTarefasConfig::save($_POST)) {
        Session::addMessageAfterRedirect(__('Configuração salva.', 'tarefas'), true, INFO);
    } else {
        Session::addMessageAfterRedirect(
            __('Não foi possível salvar a configuração.', 'tarefas'),
            false,
            ERROR
        );
    }
    Html::redirect($configUrl);
}

Html::header(
    __('Configuração — Plugin Tarefas', 'tarefas'),
    $_SERVER['PHP_SELF'],
    'config',
    'Plugin'
);

$profileOptions = [];
foreach ($DB->request([
    'SELECT' => ['id', 'name'],
    'FROM'   => Profile::getTable(),
    'ORDER'  => 'name ASC',
]) as $row) {
    $profileId = (int) ($row['id'] ?? 0);
    if ($profileId <= 0) {
        continue;
    }
    $profileOptions[$profileId] = (string) ($row['name'] ?? $profileId);
}

echo '<div class="center" style="max-width:960px;margin:0 auto;">';
echo '<form method="post" action="' . htmlescape($configUrl) . '">';
echo '<input type="hidden" name="_glpi_csrf_token" value="' . htmlescape(Session::getNewCSRFToken()) . '">';

echo '<table class="tab_cadre_fix">';

echo '<tr class="tab_bg_1"><th colspan="2">';
echo htmlescape(__('Acesso ao botão no chamado', 'tarefas'));
echo '</th></tr>';

echo '<tr class="tab_bg_1">';
echo '<td style="width:35%;">' . htmlescape(__('Opção na timeline', 'tarefas')) . '</td>';
echo '<td>';
echo '<p class="mb-2 text-muted">';
echo htmlescape(__(
    'Durante a transição, a opção nativa “Criar uma tarefa” permanece disponível. '
    . 'Este controle define quem vê “Criar uma tarefa (NOVO)” do plugin.',
    'tarefas'
));
echo '</p>';
echo '<label class="form-label" for="timeline_profiles_mode">';
echo htmlescape(__('Perfis autorizados', 'tarefas'));
echo '</label>';
$mode = (string) ($config['timeline_profiles_mode'] ?? PluginTarefasConfig::MODE_ALL);
echo '<select class="form-select" name="timeline_profiles_mode" id="timeline_profiles_mode">';
echo '<option value="all" ' . ($mode === PluginTarefasConfig::MODE_ALL ? 'selected' : '') . '>';
echo htmlescape(__('Todos os perfis', 'tarefas'));
echo '</option>';
echo '<option value="selected" ' . ($mode === PluginTarefasConfig::MODE_SELECTED ? 'selected' : '') . '>';
echo htmlescape(__('Somente perfis selecionados', 'tarefas'));
echo '</option>';
echo '</select>';
echo '<div id="plugin-tarefas-timeline-profiles" class="mt-3' . ($mode === PluginTarefasConfig::MODE_SELECTED ? '' : ' d-none') . '">';
Dropdown::showFromArray('timeline_profiles_ids', $profileOptions, [
    'multiple' => true,
    'values'   => $mode === PluginTarefasConfig::MODE_SELECTED ? ($config['timeline_profiles_ids'] ?? []) : [],
    'width'    => '100%',
]);
echo '<div class="form-text text-muted mt-1">';
echo htmlescape(__(
    'Independente do controle “Quem pode usar” de cada modelo de tarefa.',
    'tarefas'
));
echo '</div>';
echo '</div>';
echo '</td></tr>';

echo '<tr class="tab_bg_1"><td colspan="2" class="center">';
echo '<button type="submit" class="btn btn-primary">';
echo htmlescape(__('Salvar', 'tarefas'));
echo '</button>';
echo '</td></tr>';

echo '</table>';
echo '</form>';
echo '</div>';

echo <<<'JS'
<script>
(function () {
    const mode = document.getElementById('timeline_profiles_mode');
    const profiles = document.getElementById('plugin-tarefas-timeline-profiles');
    if (!mode || !profiles) {
        return;
    }
    mode.addEventListener('change', () => {
        profiles.classList.toggle('d-none', mode.value !== 'selected');
    });
})();
</script>
JS;

Html::footer();
