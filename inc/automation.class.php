<?php

class PluginTarefasAutomation
{
    public static function blank(): array
    {
        return ['enabled' => false, 'items' => []];
    }

    public static function fromWebhook(string $url, bool $enabled): array
    {
        $url = trim($url);
        if ($url === '') {
            return self::blank();
        }

        return [
            'enabled' => $enabled,
            'items'   => [[
                'id'           => 'auto_migrated',
                'kind'         => 'external',
                'webhook_url'  => $url,
                'source'       => 'fixed',
                'value'        => '',
                'field_name'   => '',
                'editable'     => false,
                'when'         => 'always',
                'conditional'  => PluginTarefasModel::normalizeConditional([]),
            ]],
        ];
    }

    public static function normalize(mixed $raw, string $legacyUrl = '', bool $legacyEnabled = true): array
    {
        $data = is_array($raw) ? $raw : PluginTarefasModel::decodeJson((string) $raw, []);
        if (!is_array($data)) {
            $data = [];
        }

        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        if ($items === [] && trim($legacyUrl) !== '') {
            return self::fromWebhook($legacyUrl, $legacyEnabled);
        }

        $normalized = [];
        foreach (array_values($items) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $kind = (string) ($item['kind'] ?? '');
            if (!in_array($kind, ['external', 'status', 'group'], true)) {
                continue;
            }

            $sourceRaw = (string) ($item['source'] ?? 'fixed');
            $source = match ($sourceRaw) {
                'task_field'     => 'task_field',
                'location_group' => 'location_group',
                default          => 'fixed',
            };
            if ($kind === 'status' && $source === 'location_group') {
                $source = 'fixed';
            }
            $when = (string) ($item['when'] ?? 'always') === 'fields' ? 'fields' : 'always';
            $conditional = PluginTarefasModel::normalizeConditional(
                is_array($item['conditional'] ?? null) ? $item['conditional'] : []
            );
            $conditional['enabled'] = $when === 'fields';

            $normalized[] = [
                'id'          => preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($item['id'] ?? '')) ?: ('auto_' . ($index + 1)),
                'kind'        => $kind,
                'webhook_url' => trim((string) ($item['webhook_url'] ?? '')),
                'source'      => $kind === 'external' ? 'fixed' : $source,
                'value'       => trim((string) ($item['value'] ?? '')),
                'field_name'  => trim((string) ($item['field_name'] ?? '')),
                'editable'    => $kind !== 'external' && $source === 'fixed' && !empty($item['editable']),
                'when'        => $when,
                'conditional' => $conditional,
            ];
        }

        return [
            'enabled' => !empty($data['enabled']) && $normalized !== [],
            'items'   => $normalized,
        ];
    }

    public static function validate(array $automations): ?string
    {
        if (empty($automations['enabled'])) {
            return null;
        }

        foreach ($automations['items'] ?? [] as $item) {
            if (($item['when'] ?? 'always') === 'fields') {
                $conditional = PluginTarefasModel::normalizeConditional(
                    is_array($item['conditional'] ?? null) ? $item['conditional'] : []
                );
                if (!PluginTarefasModel::logicHasRules($conditional['logic'] ?? [])) {
                    return __('Informe as regras de campos de cada automação condicional.', 'tarefas');
                }
            }
            $kind = (string) ($item['kind'] ?? '');
            if ($kind === 'external') {
                $url = (string) ($item['webhook_url'] ?? '');
                if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
                    return __('Informe uma URL válida em cada automação externa.', 'tarefas');
                }
                continue;
            }
            if (($item['source'] ?? 'fixed') === 'task_field') {
                if (trim((string) ($item['field_name'] ?? '')) === '') {
                    return __('Selecione o campo da tarefa usado em cada automação interna.', 'tarefas');
                }
                continue;
            }
            if (($item['source'] ?? 'fixed') === 'location_group') {
                if (($item['kind'] ?? '') !== 'group') {
                    return __('A origem “grupo da localização” só se aplica à automação de grupo.', 'tarefas');
                }
                continue;
            }
            if (trim((string) ($item['value'] ?? '')) === '') {
                return __('Informe o valor fixo de cada automação interna.', 'tarefas');
            }
        }

        return null;
    }

    public static function firstWebhookUrl(array $automations): string
    {
        foreach ($automations['items'] ?? [] as $item) {
            if (($item['kind'] ?? '') === 'external' && trim((string) ($item['webhook_url'] ?? '')) !== '') {
                return trim((string) $item['webhook_url']);
            }
        }
        return '';
    }

    public static function ticketStatuses(): array
    {
        if (!class_exists(Ticket::class) || !method_exists(Ticket::class, 'getAllStatusArray')) {
            return [];
        }
        $statuses = [];
        foreach (Ticket::getAllStatusArray() as $id => $label) {
            $statuses[] = [
                'id'   => (string) $id,
                'name' => (string) $label,
            ];
        }
        return $statuses;
    }

    public static function postedOverrides(): array
    {
        $posted = $_POST['automation_values'] ?? [];
        if (!is_array($posted)) {
            return [];
        }
        $result = [];
        foreach ($posted as $id => $value) {
            $key = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $id);
            if ($key === '') {
                continue;
            }
            if (is_array($value)) {
                $value = (string) ($value['id'] ?? reset($value) ?: '');
            }
            $result[$key] = trim((string) $value);
        }
        return $result;
    }

    public static function itemApplies(array $item, array $fields, array $values): bool
    {
        if (($item['when'] ?? 'always') !== 'fields') {
            return true;
        }

        $conditional = PluginTarefasModel::normalizeConditional(
            is_array($item['conditional'] ?? null) ? $item['conditional'] : []
        );
        if (!PluginTarefasModel::logicHasRules($conditional['logic'] ?? [])) {
            return false;
        }

        return PluginTarefasModel::evaluateLogicGroup($conditional['logic'], $fields, $values, true);
    }

    public static function forTask(PluginTarefasTask $task): array
    {
        $fromTask = self::normalize(
            $task->fields['automations_json'] ?? '',
            (string) ($task->fields['webhook_url'] ?? ''),
            true
        );

        $modelId = (int) ($task->fields['plugin_tarefas_models_id'] ?? 0);
        if ($modelId <= 0) {
            return $fromTask;
        }

        $model = new PluginTarefasModel();
        if (!$model->getFromDB($modelId)) {
            return $fromTask;
        }

        $fromModel = self::normalize(
            $model->fields['automations_json'] ?? '',
            (string) ($model->fields['webhook_url'] ?? ''),
            !empty($model->fields['webhook_enabled'] ?? 1)
        );
        if (!empty($fromModel['items'])) {
            return $fromModel;
        }

        return $fromTask;
    }

    public static function runForTask(int $taskId): void
    {
        $task = new PluginTarefasTask();
        if (!$task->getFromDB($taskId)) {
            return;
        }

        $automations = self::forTask($task);
        if (empty($automations['enabled']) || empty($automations['items'])) {
            return;
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB((int) $task->fields['tickets_id'])) {
            return;
        }

        $schema = PluginTarefasModel::decodeJson((string) ($task->fields['schema_json'] ?? ''), []);
        $content = PluginTarefasModel::decodeJson((string) ($task->fields['content_json'] ?? ''), []);
        $fields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];
        $saveMode = (string) ($task->fields['status'] ?? 'finished');
        $isFinished = $saveMode !== 'draft';
        $isPrivate = !empty($task->fields['is_private']);

        $modelId = (int) ($task->fields['plugin_tarefas_models_id'] ?? 0);
        if ($modelId > 0) {
            $model = new PluginTarefasModel();
            if ($model->getFromDB($modelId)) {
                $modelSchema = PluginTarefasModel::decodeJson((string) ($model->fields['schema_json'] ?? ''), []);
                if (!empty($modelSchema['fields']) && is_array($modelSchema['fields'])) {
                    $fields = $modelSchema['fields'];
                }
            }
        }

        foreach ($automations['items'] as $item) {
            $kind = (string) ($item['kind'] ?? '');
            if ($kind === 'external' && !$isFinished) {
                continue;
            }
            if (!self::itemApplies($item, $fields, $content)) {
                continue;
            }
            if ($kind === 'external') {
                self::runExternal($task, $item, $schema, $content, $saveMode);
                continue;
            }
            $value = self::resolveValue($item, $fields, $content, $ticket);
            if ($value === '') {
                continue;
            }
            if ($kind === 'status') {
                self::runStatus($ticket, $value, $isPrivate);
            } elseif ($kind === 'group') {
                self::runGroup($ticket, $value, $isPrivate);
            }
        }
    }

    private static function resolveValue(
        array $item,
        array $fields,
        array $content,
        ?Ticket $ticket = null
    ): string {
        $id = (string) ($item['id'] ?? '');
        $overrides = is_array($content['_automations'] ?? null) ? $content['_automations'] : [];
        if (!empty($item['editable']) && ($item['source'] ?? '') === 'fixed') {
            $override = trim((string) ($overrides[$id] ?? ''));
            if ($override !== '') {
                return $override;
            }
        }

        if (($item['source'] ?? '') === 'location_group') {
            if ($ticket === null) {
                return '';
            }
            $groupId = PluginTarefasLocation::getGroupIdForTicket($ticket);
            return $groupId > 0 ? (string) $groupId : '';
        }

        if (($item['source'] ?? '') === 'task_field') {
            $name = (string) ($item['field_name'] ?? '');
            $source = null;
            foreach ($fields as $field) {
                if ((string) ($field['name'] ?? '') === $name) {
                    $source = $field;
                    break;
                }
            }
            $raw = $content[$name] ?? null;
            if ($source !== null) {
                $actual = PluginTarefasModel::conditionActualValues($source, $raw);
                return (string) ($actual[0] ?? '');
            }
            if (is_array($raw)) {
                return (string) ($raw['id'] ?? reset($raw) ?: '');
            }
            return trim((string) $raw);
        }

        return trim((string) ($item['value'] ?? ''));
    }

    private static function runExternal(
        PluginTarefasTask $task,
        array $item,
        array $schema,
        array $content,
        string $saveMode
    ): void {
        $url = trim((string) ($item['webhook_url'] ?? ''));
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return;
        }

        $payload = [
            'event'          => $saveMode === 'draft' ? 'task_saved' : 'task_finished',
            'ticket_id'      => (int) $task->fields['tickets_id'],
            'task_id'        => (int) $task->fields['id'],
            'schema_version' => (int) ($task->fields['model_version'] ?? 0),
            'status'         => $saveMode,
            'data'           => $content,
            'schema'         => $schema,
        ];

        $body = PluginTarefasModel::encodeJson($payload);
        if (!self::postJson($url, $body, true) && !self::postJson($url, $body, false)) {
            self::logWebhookError($url, 'falha ao enviar o webhook da tarefa ' . (int) $task->fields['id']);
        }
    }

    private static function postJson(string $url, string $body, bool $verifySsl): bool
    {
        if (!function_exists('curl_init')) {
            return false;
        }

        $handle = curl_init($url);
        if ($handle === false) {
            return false;
        }

        curl_setopt_array($handle, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
        ]);
        curl_exec($handle);
        $errno = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if ($errno !== 0) {
            return false;
        }

        return $status === 0 || ($status >= 200 && $status < 400);
    }

    private static function logWebhookError(string $url, string $detail): void
    {
        $message = '[plugin tarefas] ' . $detail . ' → ' . $url;
        if (class_exists(Toolbox::class) && method_exists(Toolbox::class, 'logInFile')) {
            Toolbox::logInFile('tarefas', $message . "\n");
            return;
        }
        error_log($message);
    }

    private static function runStatus(Ticket $ticket, string $value, bool $isPrivate = false): void
    {
        $status = (int) $value;
        if ($status <= 0 || (int) ($ticket->fields['status'] ?? 0) === $status) {
            return;
        }

        $input = [
            'id'     => $ticket->getID(),
            'status' => $status,
        ];
        // Tarefa privada do plugin: aplica a automação sem notificar o requerente.
        if ($isPrivate) {
            $input['_disablenotif'] = true;
        }
        $ticket->update($input);
        $ticket->getFromDB($ticket->getID());
    }

    private static function runGroup(Ticket $ticket, string $value, bool $isPrivate = false): void
    {
        $groupId = (int) $value;
        if ($groupId <= 0) {
            return;
        }

        $link = new Group_Ticket();
        $existing = $link->find([
            'tickets_id' => $ticket->getID(),
            'type'       => CommonITILActor::ASSIGN,
        ]);

        $alreadyAssigned = false;
        foreach ($existing as $row) {
            $currentId = (int) ($row['groups_id'] ?? 0);
            $rowId = (int) ($row['id'] ?? 0);
            if ($currentId === $groupId) {
                $alreadyAssigned = true;
                continue;
            }
            if ($rowId > 0) {
                $deleteInput = ['id' => $rowId];
                if ($isPrivate) {
                    $deleteInput['_disablenotif'] = true;
                }
                $link->delete($deleteInput, true);
            }
        }

        if ($alreadyAssigned) {
            return;
        }

        $addInput = [
            'tickets_id' => $ticket->getID(),
            'groups_id'  => $groupId,
            'type'       => CommonITILActor::ASSIGN,
        ];
        if ($isPrivate) {
            $addInput['_disablenotif'] = true;
        }
        $link->add($addInput);
    }
}
