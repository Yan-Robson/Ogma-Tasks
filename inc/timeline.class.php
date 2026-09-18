<?php

class PluginTarefasTimeline
{
    public static function answerActions(array $options): array
    {
        $ticket = $options['item'] ?? null;
        if (!$ticket instanceof Ticket) {
            return [];
        }

        return [
            'plugin_tarefas_task' => [
                'type'        => PluginTarefasTask::class,
                'class'       => 'action-plugin-tarefas',
                'icon'        => 'ti ti-list-check',
                'label'       => __('Criar uma tarefa (NOVO)', 'tarefas'),
                'short_label' => __('Tarefa (NOVO)', 'tarefas'),
                'template'    => '@tarefas/timeline/task.html.twig',
                'item'        => new PluginTarefasTask(),
                'hide_in_menu' => !PluginTarefasTask::canUsePluginTimelineAction($ticket),
            ],
        ];
    }

    /**
     * Formulário completo exibido dentro do chamado, sem sair da tela.
     */
    public static function renderCreateForm(int $ticketId): string
    {
        $ticket = new Ticket();
        if (!$ticket->getFromDB($ticketId) || !PluginTarefasTask::canUsePluginTimelineAction($ticket)) {
            return '<div class="alert alert-warning m-2">'
                . htmlescape(__('Sem permissão para criar tarefa neste chamado.', 'tarefas'))
                . '</div>';
        }

        $models = PluginTarefasModel::getUsableModels();
        if ($models === []) {
            return '<div class="alert alert-warning m-2">'
                . htmlescape(__('Nenhum modelo de tarefa disponível para o seu grupo ou perfil.', 'tarefas'))
                . '</div>';
        }

        $webDir = Plugin::getWebDir('tarefas');
        $single = count($models) === 1;
        $options = $single ? '' : '<option value="">-----</option>';
        foreach ($models as $model) {
            $options .= '<option value="' . (int) $model['id'] . '"'
                . ($single ? ' selected' : '') . '>'
                . htmlescape((string) $model['name'])
                . '</option>';
        }

        $html = '<form method="post" enctype="multipart/form-data" class="p-1 plugin-tarefas-native-skin"'
            . ' id="plugin-tarefas-task-form"'
            . ' action="' . htmlescape($webDir . '/front/task.form.php') . '"'
            . ' data-ticket-id="' . $ticketId . '"'
            . ' data-fields-url="' . htmlescape($webDir . '/ajax/task.php') . '">';
        $html .= '<input type="hidden" name="_glpi_csrf_token" value="'
            . htmlescape(Session::getNewCSRFToken()) . '">';
        $html .= '<input type="hidden" name="action" value="save">';
        $html .= '<input type="hidden" name="id" value="0">';
        $html .= '<input type="hidden" name="tickets_id" value="' . $ticketId . '">';
        $html .= '<div class="plugin-tarefas-head-row plugin-tarefas-head-row--create mb-2">'
            . '<div class="plugin-tarefas-head-row__model">'
            . '<label class="plugin-tarefas-meta-label" for="plugin-tarefas-model-select">'
            . htmlescape(__('Modelo de tarefa', 'tarefas')) . ' <span class="text-danger">*</span></label>'
            . '<select class="form-select" id="plugin-tarefas-model-select"'
            . ' name="plugin_tarefas_models_id">' . $options . '</select>'
            . '</div>'
            . '<div class="plugin-tarefas-head-row__visibility">'
            . PluginTarefasForm::renderVisibilityHtml(false)
            . '</div></div>';
        $html .= '<div id="plugin-tarefas-form-body"></div>';
        $html .= '</form>';

        return $html;
    }

    public static function injectItems(array $options): array
    {
        $ticket = $options['item'] ?? null;
        if (!$ticket instanceof Ticket || !$ticket->getID() || !PluginTarefasTask::canView()) {
            return $options;
        }

        // Ordem reversa nativa: item mais recente no topo e descrição original no fim.
        $_SESSION['glpitimeline_order'] = CommonITILObject::TIMELINE_ORDER_REVERSE;

        if (!isset($options['timeline']) || !is_array($options['timeline'])) {
            return $options;
        }

        foreach (PluginTarefasTask::getForTicket((int) $ticket->getID()) as $row) {
            $task = new PluginTarefasTask();
            $task->fields = $row;
            if (!$task->canViewItem()) {
                continue;
            }

            $row['date'] = $row['date_creation'];
            $row['users_id_editor'] = $row['users_id'];
            $row['can_edit'] = $task->canUpdateItem();
            $row['can_delete'] = $task->canDeleteItem();
            $row['is_private'] = !empty($row['is_private']);
            $row['is_content_safe'] = true;
            $row['content'] = self::renderSummary($row);
            $row['timeline_position'] = CommonITILObject::TIMELINE_RIGHT;

            $options['timeline']['PluginTarefasTask_' . $row['id']] = [
                'type'     => PluginTarefasTask::class,
                'item'     => $row,
                'object'   => $task,
                'itiltype' => PluginTarefasTask::class,
            ];
        }

        return $options;
    }

    public static function renderSummary(array $task): string
    {
        $schema  = PluginTarefasModel::decodeJson((string) ($task['schema_json'] ?? ''), []);
        $content = PluginTarefasModel::decodeJson((string) ($task['content_json'] ?? ''), []);

        $version = self::versionStatus($task);
        $html = '<div class="plugin-tarefas-timeline">';
        $html .= '<h3 class="plugin-tarefas-timeline__title">'
            . htmlescape((string) $task['model_name'])
            . '</h3>';
        $html .= '<div class="plugin-tarefas-timeline__meta">';
        $html .= '<span class="plugin-tarefas-version">'
            . htmlescape(sprintf(__('v%d', 'tarefas'), $version['task']))
            . '</span>';
        if ($version['obsolete']) {
            $html .= '<span class="plugin-tarefas-version plugin-tarefas-version--obsolete">'
                . htmlescape(__('Obsoleto', 'tarefas'))
                . '</span>';
        }
        $html .= '</div>';
        if ($version['obsolete']) {
            $html .= '<p class="plugin-tarefas-timeline__obsolete-note">'
                . htmlescape(sprintf(
                    __('Este preenchimento ficou na v%d. O modelo vigente é a v%d. Para usar a versão atual, crie uma nova tarefa.', 'tarefas'),
                    $version['task'],
                    $version['current']
                ))
                . '</p>';
        }
        $html .= '<div class="plugin-tarefas-timeline__body">';

        foreach (($schema['fields'] ?? []) as $field) {
            if (($field['type'] ?? '') === 'information') {
                continue;
            }

            if (!PluginTarefasModel::conditionMatches($field, $schema['fields'] ?? [], $content)) {
                continue;
            }

            $name = (string) ($field['name'] ?? '');
            $value = $content[$name] ?? null;
            $html .= '<div class="plugin-tarefas-answer">';
            $html .= '<span class="plugin-tarefas-answer__label">'
                . htmlescape((string) ($field['label'] ?? $name)) . '</span>';
            $html .= '<span>' . self::formatValue($field, $value, (int) ($task['tickets_id'] ?? 0)) . '</span>';
            $html .= '</div>';
        }

        $html .= '</div>';

        $createdAt = trim((string) Html::convDateTime((string) ($task['date_creation'] ?? '')));
        $html .= '<div class="plugin-tarefas-timeline__footer">';
        if ($createdAt !== '') {
            $html .= '<span class="plugin-tarefas-tag">'
                . '<i class="ti ti-calendar"></i>'
                . htmlescape($createdAt)
                . '</span>';
        }
        $creatorName = trim((string) ($task['user_name'] ?? ''));
        if ($creatorName !== '') {
            $html .= '<span class="plugin-tarefas-tag">'
                . '<i class="ti ti-user"></i>'
                . htmlescape($creatorName)
                . '</span>';
        }
        $groupName = trim((string) ($task['group_name'] ?? ''));
        if ($groupName === '' && !empty($task['groups_id'])) {
            $groupName = PluginTarefasModel::groupName((int) $task['groups_id']);
        }
        if ($groupName !== '') {
            $html .= '<span class="plugin-tarefas-tag">'
                . '<i class="ti ti-users"></i>'
                . htmlescape($groupName)
                . '</span>';
        }
        $categoryName = PluginTarefasModel::categoryName((int) ($task['taskcategories_id'] ?? 0));
        if ($categoryName !== '') {
            $html .= '<span class="plugin-tarefas-tag">'
                . '<i class="ti ti-tag"></i>'
                . htmlescape($categoryName)
                . '</span>';
        }
        $html .= '<span class="plugin-tarefas-tag">'
            . '<i class="ti ti-clock"></i>'
            . htmlescape(self::formatMinutes((int) $task['time_minutes']))
            . '</span>';
        $status = (string) ($task['status'] ?? '');
        if ($status === 'draft') {
            $html .= '<span class="plugin-tarefas-tag plugin-tarefas-tag--draft">'
                . '<i class="ti ti-pencil"></i>'
                . htmlescape(__('Rascunho', 'tarefas'))
                . '</span>';
        }
        if (!empty($task['is_private'])) {
            $html .= '<span class="plugin-tarefas-tag plugin-tarefas-tag--private">'
                . '<i class="ti ti-lock"></i>'
                . htmlescape(__('Privada', 'tarefas'))
                . '</span>';
        } else {
            $html .= '<span class="plugin-tarefas-tag plugin-tarefas-tag--public">'
                . '<i class="ti ti-eye"></i>'
                . htmlescape(__('Pública', 'tarefas'))
                . '</span>';
        }
        $html .= '</div></div>';
        return $html;
    }

    private static function versionStatus(array $task): array
    {
        $taskVersion = max(1, (int) ($task['model_version'] ?? 0));
        $current = 0;
        $modelId = (int) ($task['plugin_tarefas_models_id'] ?? 0);
        if ($modelId > 0) {
            $model = new PluginTarefasModel();
            if ($model->getFromDB($modelId)) {
                $current = max(0, (int) ($model->fields['version'] ?? 0));
            }
        }

        return [
            'task'     => $taskVersion,
            'current'  => $current,
            'obsolete' => $current > $taskVersion,
        ];
    }

    private static function formatValue(array $field, mixed $value, int $ticketsId = 0): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '<span class="text-muted">' . htmlescape(__('Não informado', 'tarefas')) . '</span>';
        }

        $type = (string) ($field['type'] ?? '');
        if ($type === 'yes_no') {
            return htmlescape($value === 'yes' ? __('Sim') : __('Não'));
        }
        if ($type === 'multiple_choice') {
            return htmlescape(implode(', ', (array) $value));
        }
        if ($type === 'glpi_list') {
            $itemtype = (string) ($field['config']['itemtype'] ?? Group::class);
            $ids = !empty($field['config']['multiple'])
                ? PluginTarefasModel::glpiListSelectedIds($value)
                : [PluginTarefasModel::glpiListSelectedId($value)];
            $names = [];
            foreach ($ids as $id) {
                if ($id <= 0) {
                    continue;
                }
                $label = class_exists($itemtype)
                    ? Dropdown::getDropdownName($itemtype::getTable(), $id)
                    : '';
                $names[] = $label !== '' ? (string) $label : (string) $id;
            }

            return htmlescape($names !== [] ? implode(', ', $names) : '');
        }
        if ($type === 'date' && is_array($value)) {
            return '<span class="plugin-tarefas-date-range-view">'
                . '<span>' . htmlescape(Html::convDate((string) ($value['start'] ?? ''))) . '</span>'
                . '<span class="plugin-tarefas-date-range-view__sep">' . htmlescape(__('até', 'tarefas')) . '</span>'
                . '<span>' . htmlescape(Html::convDate((string) ($value['end'] ?? ''))) . '</span>'
                . '</span>';
        }
        if ($type === 'date') {
            return htmlescape(Html::convDate((string) $value));
        }
        if ($type === 'file') {
            $parts = [];
            foreach ((array) $value as $file) {
                if (!is_array($file) || empty($file['document_id'])) {
                    continue;
                }
                $html = PluginTarefasForm::renderAttachment($file, $ticketsId);
                if ($html !== '') {
                    $parts[] = $html;
                }
            }
            return $parts !== [] ? implode('', $parts) : htmlescape(__('Não informado', 'tarefas'));
        }
        if (is_array($value)) {
            return htmlescape(PluginTarefasModel::encodeJson($value));
        }
        return nl2br(htmlescape((string) $value));
    }

    public static function formatMinutes(int $minutes): string
    {
        return intdiv($minutes, 60) . 'h' . str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }
}
