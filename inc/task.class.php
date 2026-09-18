<?php

class PluginTarefasTask extends CommonDBTM
{
    public static $rightname = 'ticket';

    public static function getTypeName($nb = 0): string
    {
        return _n('Tarefa do plugin', 'Tarefas do plugin', $nb, 'tarefas');
    }

    public static function canCreate(): bool
    {
        return Session::haveRight('ticket', UPDATE) || TicketTask::canCreate();
    }

    public static function canView(): bool
    {
        return Session::getLoginUserID() !== false;
    }

    /**
     * Mesma regra da tarefa nativa: quem pode lançar tarefa no chamado pode usar o plugin.
     */
    public static function canAddToTicket(Ticket $ticket): bool
    {
        if (!$ticket->getID() || !$ticket->canViewItem()) {
            return false;
        }

        $blocked = array_merge(Ticket::getSolvedStatusArray(), Ticket::getClosedStatusArray());
        if (in_array((int) ($ticket->fields['status'] ?? 0), $blocked, true)) {
            return false;
        }

        // can() recebe $input por referência, por isso a variável intermediária.
        $input = ['tickets_id' => $ticket->getID()];
        $task = new TicketTask();
        return $task->can(-1, CREATE, $input)
            || Session::haveRight('ticket', UPDATE);
    }

    /**
     * Botão “Criar uma tarefa (NOVO)”: permissão no chamado + perfil autorizado na configuração global.
     */
    public static function canUsePluginTimelineAction(Ticket $ticket): bool
    {
        return self::canAddToTicket($ticket)
            && PluginTarefasConfig::currentProfileCanSeeTimelineButton();
    }

    public function canViewItem(): bool
    {
        if (!$this->canAccessTicket((int) ($this->fields['tickets_id'] ?? 0))) {
            return false;
        }

        if (empty($this->fields['is_private'])) {
            return true;
        }

        $ticket = new Ticket();
        return $ticket->getFromDB((int) $this->fields['tickets_id'])
            && self::canSeePrivateTasks($ticket);
    }

    /**
     * Tarefa privada: só perfil de atendimento (técnico), não o usuário que abriu o chamado.
     */
    public static function canSeePrivateTasks(Ticket $ticket): bool
    {
        if (!$ticket->getID() || !$ticket->canViewItem()) {
            return false;
        }

        if (Session::haveRight('ticket', UPDATE) || TicketTask::canCreate()) {
            return true;
        }

        return $ticket->isUser(CommonITILActor::ASSIGN, (int) Session::getLoginUserID());
    }

    public function canCreateItem(): bool
    {
        $ticketsId = (int) ($this->input['tickets_id'] ?? $this->fields['tickets_id'] ?? 0);
        if ($ticketsId <= 0 && isset($this->input['parent']) && $this->input['parent'] instanceof Ticket) {
            $ticketsId = (int) $this->input['parent']->getID();
        }

        $ticket = new Ticket();
        return $ticket->getFromDB($ticketsId) && self::canUsePluginTimelineAction($ticket);
    }

    public function canUpdateItem(): bool
    {
        if ($this->isFinished()) {
            return false;
        }

        return self::canCreate() && $this->canAccessTicket((int) ($this->fields['tickets_id'] ?? 0));
    }

    public function canDeleteItem(): bool
    {
        if ($this->isFinished()) {
            return false;
        }

        if (!$this->canAccessTicket((int) ($this->fields['tickets_id'] ?? 0))) {
            return false;
        }

        return (int) ($this->fields['users_id'] ?? 0) === (int) Session::getLoginUserID()
            || self::canCreate();
    }

    public function isFinished(): bool
    {
        $status = (string) ($this->fields['status'] ?? '');
        return $status === 'finished' || $status === 'filled';
    }

    public function isDraft(): bool
    {
        return (string) ($this->fields['status'] ?? '') === 'draft';
    }

    public function allowsSave(): bool
    {
        return !empty($this->fields['allow_save']);
    }

    public static function resolveSaveMode(array $input, bool $allowSave): string
    {
        $mode = (string) ($input['save_mode'] ?? '');
        if ($allowSave && $mode === 'draft') {
            return 'draft';
        }
        return 'finished';
    }

    public function post_deleteFromDB()
    {
        global $DB;

        $taskId = (int) ($this->fields['id'] ?? 0);
        if ($taskId > 0 && $DB->tableExists('glpi_plugin_tarefas_task_documents')) {
            $DB->delete('glpi_plugin_tarefas_task_documents', [
                'plugin_tarefas_tasks_id' => $taskId,
            ]);
        }
    }

    public function prepareInputForAdd($input): array|false
    {
        if (!self::canCreate()) {
            return false;
        }

        $ticketsId = (int) ($input['tickets_id'] ?? 0);
        $modelId   = (int) ($input['plugin_tarefas_models_id'] ?? 0);
        $minutes   = (int) ($input['time_minutes'] ?? 0);

        $ticket = new Ticket();
        $canAdd = $ticket->getFromDB($ticketsId) && self::canUsePluginTimelineAction($ticket);

        if (!$canAdd || $minutes <= 0) {
            Session::addMessageAfterRedirect(
                __('Informe um chamado válido e um tempo de atendimento maior que zero.', 'tarefas'),
                false,
                ERROR
            );
            return false;
        }

        $model = new PluginTarefasModel();
        $modelExists = $modelId > 0 && $model->getFromDB($modelId);
        $snapshot = self::snapshotFromInput($input, $modelExists ? $model : null);
        if ($snapshot === null) {
            Session::addMessageAfterRedirect(
                __('O modelo selecionado não está disponível.', 'tarefas'),
                false,
                ERROR
            );
            return false;
        }

        if ($modelExists && !$model->canCurrentUserUse() && empty($input['snapshot_schema_json'])) {
            Session::addMessageAfterRedirect(
                __('O modelo selecionado não está disponível.', 'tarefas'),
                false,
                ERROR
            );
            return false;
        }

        $schema = $snapshot['schema'];
        $allowSave = $snapshot['allow_save'];
        $saveMode = self::resolveSaveMode($input, $allowSave);

        $content = self::sanitizeContent(
            $schema,
            PluginTarefasModel::decodeJson((string) ($input['content_json'] ?? ''), []),
            $saveMode === 'finished'
        );

        if ($content['errors'] !== []) {
            Session::addMessageAfterRedirect(implode(' ', $content['errors']), false, ERROR);
            return false;
        }

        $userId = (int) Session::getLoginUserID();
        $executorId = (int) ($input['users_id_executor'] ?? $userId);
        if ($executorId <= 0) {
            $executorId = $userId;
        }
        $groupId = max(0, (int) ($input['groups_id'] ?? 0));
        $automations = $snapshot['automations'];
        $values = $content['values'];
        $overrides = is_array($input['_automations'] ?? null) ? $input['_automations'] : [];
        if ($overrides !== []) {
            $values['_automations'] = $overrides;
        }

        $input = [
            'tickets_id'                => $ticketsId,
            'plugin_tarefas_models_id'  => $modelId,
            'model_name'                => $snapshot['model_name'],
            'model_version'             => $snapshot['model_version'],
            'schema_json'               => PluginTarefasModel::encodeJson($schema),
            'content_json'              => PluginTarefasModel::encodeJson($values),
            'automations_json'          => PluginTarefasModel::encodeJson($automations),
            'webhook_url'               => PluginTarefasAutomation::firstWebhookUrl($automations)
                ?: $snapshot['webhook_url'],
            'users_id'                  => $userId,
            'user_name'                 => self::getUserDisplayName($userId),
            'users_id_executor'         => $executorId,
            'executor_name'             => self::getUserDisplayName($executorId),
            'groups_id'                 => $groupId,
            'group_name'                => PluginTarefasModel::groupName($groupId),
            'taskcategories_id'         => $snapshot['taskcategories_id'],
            'time_minutes'              => $minutes,
            'is_private'                => !empty($input['is_private']) ? 1 : 0,
            'allow_save'                => $allowSave ? 1 : 0,
            'status'                    => $saveMode,
            'date_creation'             => $_SESSION['glpi_currenttime'],
            'date_mod'                  => $_SESSION['glpi_currenttime'],
        ];

        return $input;
    }

    public function prepareInputForUpdate($input): array|false
    {
        if (!$this->canUpdateItem()) {
            return false;
        }

        $minutes = (int) ($input['time_minutes'] ?? $this->fields['time_minutes'] ?? 0);
        if ($minutes <= 0) {
            Session::addMessageAfterRedirect(
                __('O tempo de atendimento deve ser maior que zero.', 'tarefas'),
                false,
                ERROR
            );
            return false;
        }

        $allowSave = !empty($this->fields['allow_save']);
        $saveMode = self::resolveSaveMode($input, $allowSave);

        $schema = PluginTarefasModel::decodeJson((string) $this->fields['schema_json'], []);
        $content = self::sanitizeContent(
            $schema,
            PluginTarefasModel::decodeJson((string) ($input['content_json'] ?? ''), []),
            $saveMode === 'finished'
        );

        if ($content['errors'] !== []) {
            Session::addMessageAfterRedirect(implode(' ', $content['errors']), false, ERROR);
            return false;
        }

        $executorId = (int) ($input['users_id_executor'] ?? $this->fields['users_id_executor'] ?? 0);
        if ($executorId <= 0) {
            $executorId = (int) ($this->fields['users_id'] ?? Session::getLoginUserID());
        }
        $groupId = isset($input['groups_id'])
            ? max(0, (int) $input['groups_id'])
            : (int) ($this->fields['groups_id'] ?? 0);

        $values = $content['values'];
        $overrides = is_array($input['_automations'] ?? null) ? $input['_automations'] : [];
        if ($overrides !== []) {
            $values['_automations'] = $overrides;
        } elseif (isset($this->fields['content_json'])) {
            $previous = PluginTarefasModel::decodeJson((string) $this->fields['content_json'], []);
            if (isset($previous['_automations'])) {
                $values['_automations'] = $previous['_automations'];
            }
        }

        return [
            'content_json'      => PluginTarefasModel::encodeJson($values),
            'time_minutes'      => $minutes,
            'is_private'        => isset($input['is_private'])
                ? (!empty($input['is_private']) ? 1 : 0)
                : (int) ($this->fields['is_private'] ?? 0),
            'users_id_executor' => $executorId,
            'executor_name'     => self::getUserDisplayName($executorId),
            'groups_id'         => $groupId,
            'group_name'        => PluginTarefasModel::groupName($groupId),
            'status'            => $saveMode,
            'date_mod'          => $_SESSION['glpi_currenttime'],
        ];
    }

    public static function snapshotFromInput(array $input, ?PluginTarefasModel $model): ?array
    {
        $postedSchema = PluginTarefasModel::decodeJson((string) ($input['snapshot_schema_json'] ?? ''), null);
        $schema = (is_array($postedSchema) && !empty($postedSchema['fields']))
            ? $postedSchema
            : null;
        if ($schema === null && $model !== null) {
            $schema = PluginTarefasModel::decodeJson((string) ($model->fields['schema_json'] ?? ''), []);
        }
        if (!is_array($schema) || empty($schema['fields'])) {
            return null;
        }
        $schema['fields'] = PluginTarefasModel::normalizeFields($schema['fields']);

        $postedAutomations = (string) ($input['snapshot_automations_json'] ?? '');
        $automations = $postedAutomations !== ''
            ? PluginTarefasAutomation::normalize($postedAutomations)
            : PluginTarefasAutomation::blank();
        if ((empty($automations['enabled']) || empty($automations['items'])) && $model !== null) {
            $automations = PluginTarefasAutomation::normalize(
                $model->fields['automations_json'] ?? '',
                (string) ($model->fields['webhook_url'] ?? ''),
                !empty($model->fields['webhook_enabled'] ?? 1)
            );
        }

        $name = trim((string) ($input['snapshot_model_name'] ?? ''));
        if ($name === '' && $model !== null) {
            $name = (string) ($model->fields['name'] ?? '');
        }

        $version = (int) ($input['snapshot_model_version'] ?? 0);
        if ($version <= 0 && $model !== null) {
            $version = (int) ($model->fields['version'] ?? 1);
        }
        if ($version <= 0) {
            $version = 1;
        }

        $allowSave = array_key_exists('snapshot_allow_save', $input)
            ? !empty($input['snapshot_allow_save'])
            : ($model !== null && !empty($model->fields['allow_save']));

        $categoryId = array_key_exists('snapshot_taskcategories_id', $input)
            ? (int) $input['snapshot_taskcategories_id']
            : (int) ($model->fields['taskcategories_id'] ?? 0);

        $webhookUrl = trim((string) ($input['snapshot_webhook_url'] ?? ''));
        if ($webhookUrl === '' && $model !== null) {
            $webhookUrl = (string) ($model->fields['webhook_url'] ?? '');
        }

        return [
            'schema'            => $schema,
            'automations'       => $automations,
            'model_name'        => $name,
            'model_version'     => $version,
            'allow_save'        => $allowSave,
            'taskcategories_id' => $categoryId,
            'webhook_url'       => $webhookUrl,
        ];
    }

    private function canAccessTicket(int $ticketsId): bool
    {
        if ($ticketsId <= 0) {
            return false;
        }

        $ticket = new Ticket();
        return $ticket->getFromDB($ticketsId) && $ticket->canViewItem();
    }

    private static function getUserDisplayName(int $usersId): string
    {
        $user = new User();
        if ($user->getFromDB($usersId)) {
            return formatUserName(
                $usersId,
                (string) ($user->fields['name'] ?? ''),
                (string) ($user->fields['realname'] ?? ''),
                (string) ($user->fields['firstname'] ?? '')
            );
        }
        return (string) ($_SESSION['glpiname'] ?? $usersId);
    }

    public static function sanitizeContent(array $schema, array $raw, bool $requireMandatory = true): array
    {
        $values = [];
        $errors = [];
        $fields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];

        foreach ($fields as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name === '' || ($field['type'] ?? '') === 'information') {
                continue;
            }

            if (!PluginTarefasModel::conditionMatches($field, $fields, $raw)) {
                $values[$name] = null;
                continue;
            }

            $value = $raw[$name] ?? null;
            $type = (string) ($field['type'] ?? '');

            switch ($type) {
                case 'integer':
                    $digits = preg_replace('/\D+/', '', (string) $value);
                    $value = $digits === '' ? null : $digits;
                    break;

                case 'multiple_choice':
                    $value = is_array($value)
                        ? array_values(array_filter(array_map('strval', $value), 'strlen'))
                        : [];
                    break;

                case 'glpi_list':
                    $value = self::sanitizeGlpiListValue($field, $value);
                    break;

                case 'date':
                    if (!empty($field['config']['range'])) {
                        $value = [
                            'start' => trim((string) ($value['start'] ?? '')),
                            'end'   => trim((string) ($value['end'] ?? '')),
                        ];
                        if (($value['start'] === '') xor ($value['end'] === '')) {
                            $errors[] = sprintf(
                                __('Informe as duas datas de "%s".', 'tarefas'),
                                $field['label']
                            );
                        } elseif ($value['start'] !== '' && $value['end'] < $value['start']) {
                            $errors[] = sprintf(
                                __('A data final de "%s" não pode ser anterior à inicial.', 'tarefas'),
                                $field['label']
                            );
                        }
                    } else {
                        $value = trim((string) $value);
                    }
                    break;

                case 'file':
                    $value = is_array($value) ? $value : [];
                    break;

                default:
                    $value = is_string($value) ? trim($value) : $value;
                    break;
            }

            if (!empty($field['required']) && $requireMandatory && self::isEmptyValue($value)) {
                $errors[] = sprintf(
                    __('O campo "%s" é obrigatório.', 'tarefas'),
                    $field['label']
                );
            }

            $values[$name] = $value;
        }

        return ['values' => $values, 'errors' => $errors];
    }

    private static function sanitizeGlpiListValue(array $field, mixed $value): mixed
    {
        $multiple = !empty($field['config']['multiple']);
        $itemtype = (string) ($field['config']['itemtype'] ?? '');
        $ids = $multiple
            ? PluginTarefasModel::glpiListSelectedIds($value)
            : [PluginTarefasModel::glpiListSelectedId($value)];
        $result = [];

        foreach ($ids as $id) {
            if ($id <= 0 || !class_exists($itemtype)) {
                continue;
            }
            $item = new $itemtype();
            if ($item instanceof CommonDBTM && $item->getFromDB($id)) {
                $result[] = $id;
            }
        }

        return $multiple ? $result : ($result[0] ?? null);
    }

    private static function isEmptyValue(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (is_array($value)) {
            if ($value === []) {
                return true;
            }
            if (array_key_exists('start', $value)) {
                return $value['start'] === '' || $value['end'] === '';
            }
        }
        return false;
    }

    public static function getForTicket(int $ticketsId): array
    {
        global $DB;

        $rows = [];
        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['tickets_id' => $ticketsId],
            'ORDER' => ['date_creation DESC', 'id DESC'],
        ]);
        foreach ($iterator as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Formulário inline na timeline (hover → Editar), com exclusão.
     */
    public function showForm($ID, array $options = []): bool
    {
        $id = (int) $ID;
        if ($id <= 0 || !$this->getFromDB($id) || !$this->canViewItem()) {
            echo '<div class="alert alert-danger m-2">'
                . htmlescape(__('Tarefa indisponível.', 'tarefas'))
                . '</div>';
            return false;
        }

        if ($this->isFinished()) {
            echo '<div class="alert alert-info m-2">'
                . htmlescape(__('Esta tarefa já foi finalizada e não pode ser alterada.', 'tarefas'))
                . '</div>';
            return true;
        }

        $ticketId = (int) $this->fields['tickets_id'];
        $schema = PluginTarefasModel::decodeJson((string) $this->fields['schema_json'], []);
        $content = PluginTarefasModel::decodeJson((string) $this->fields['content_json'], []);
        $webDir = Plugin::getWebDir('tarefas');

        echo '<div class="p-2 plugin-tarefas-native-skin">';
        echo '<form method="post" enctype="multipart/form-data" id="plugin-tarefas-task-form"'
            . ' action="' . htmlescape($webDir . '/front/task.form.php') . '">';
        echo '<input type="hidden" name="_glpi_csrf_token" value="'
            . htmlescape(Session::getNewCSRFToken()) . '">';
        echo '<input type="hidden" name="action" value="save">';
        echo '<input type="hidden" name="id" value="' . $id . '">';
        echo '<input type="hidden" name="tickets_id" value="' . $ticketId . '">';
        echo '<input type="hidden" name="plugin_tarefas_models_id" value="'
            . (int) $this->fields['plugin_tarefas_models_id'] . '">';

        PluginTarefasForm::renderBody([
            'schema'             => $schema,
            'content'            => $content,
            'model_name'         => (string) $this->fields['model_name'],
            'user_label'         => sprintf(__('Registrado por %s', 'tarefas'), (string) $this->fields['user_name']),
            'minutes'            => (int) $this->fields['time_minutes'],
            'is_private'         => (int) ($this->fields['is_private'] ?? 0),
            'tickets_id'         => $ticketId,
            'users_id_executor'  => (int) ($this->fields['users_id_executor'] ?? $this->fields['users_id'] ?? 0),
            'groups_id'          => (int) ($this->fields['groups_id'] ?? 0),
            'taskcategories_id'  => (int) ($this->fields['taskcategories_id'] ?? 0),
            'taskcategory_name'  => PluginTarefasModel::categoryName((int) ($this->fields['taskcategories_id'] ?? 0)),
            'allow_save'         => $this->allowsSave(),
            'show_model_label'   => true,
            'automations'        => PluginTarefasAutomation::normalize(
                $this->fields['automations_json'] ?? '',
                (string) ($this->fields['webhook_url'] ?? ''),
                true
            ),
        ]);
        echo '</form>';

        if ($this->canDeleteItem()) {
            echo '<form method="post" class="mt-2" action="' . htmlescape($webDir . '/front/task.form.php') . '"'
                . ' onsubmit="return confirm(\''
                . htmlescape(__('Excluir esta tarefa do chamado? O modelo não será alterado.', 'tarefas'))
                . '\');">';
            echo '<input type="hidden" name="_glpi_csrf_token" value="'
                . htmlescape(Session::getNewCSRFToken()) . '">';
            echo '<input type="hidden" name="action" value="delete">';
            echo '<input type="hidden" name="id" value="' . $id . '">';
            echo '<button class="btn btn-outline-danger" type="submit">'
                . '<i class="ti ti-trash"></i> '
                . htmlescape(__('Excluir tarefa', 'tarefas'))
                . '</button>';
            echo '</form>';
        }

        echo '</div>';
        return true;
    }

    public static function sendWebhook(int $taskId): void
    {
        PluginTarefasAutomation::runForTask($taskId);
    }
}
