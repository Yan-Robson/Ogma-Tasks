<?php

class PluginTarefasModel extends CommonDBTM
{
    public const FIELD_DESCRIPTION_MAX_LENGTH = 80;

    public static $rightname = 'config';

    public static function getTypeName($nb = 0): string
    {
        return _n('Modelo de tarefa', 'Modelos de tarefa', $nb, 'tarefas');
    }

    public static function isGlpiSuperAdmin(): bool
    {
        $name = (string) ($_SESSION['glpiactiveprofile']['name'] ?? '');
        if (in_array($name, ['Super-Admin', 'Super-Administrador'], true)) {
            return true;
        }

        return Session::haveRight('config', UPDATE)
            && Session::haveRight('profile', CREATE)
            && Session::haveRight('profile', UPDATE)
            && Session::haveRight('profile', PURGE);
    }

    public static function canCreate(): bool
    {
        return self::isGlpiSuperAdmin();
    }

    public static function canUpdate(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    public static function canDelete(): bool
    {
        return self::isGlpiSuperAdmin();
    }

    public static function canPurge(): bool
    {
        return self::isGlpiSuperAdmin();
    }

    public static function uniqueCloneName(string $sourceName): string
    {
        $base = sprintf(__('Clone - %s', 'tarefas'), $sourceName);
        $name = $base;
        $suffix = 2;
        while (countElementsInTable(self::getTable(), ['name' => $name]) > 0) {
            $name = $base . ' (' . $suffix . ')';
            $suffix++;
        }
        return $name;
    }

    public function cloneFrom(int $sourceId): int
    {
        if (!self::canCreate() || $sourceId <= 0 || !$this->getFromDB($sourceId)) {
            return 0;
        }

        $schema = self::decodeJson((string) ($this->fields['schema_json'] ?? ''), []);
        $newName = self::uniqueCloneName((string) ($this->fields['name'] ?? ''));
        $schema['title'] = $newName;
        $schema['version'] = 1;

        $accessMode = (string) ($this->fields['access_mode'] ?? 'all');
        $accessIds = self::decodeIdList((string) ($this->fields['access_ids'] ?? ''));
        $minutes = (int) ($this->fields['default_time_minutes'] ?? 0);

        $copy = new self();
        $newId = $copy->add([
            'name'                 => $newName,
            'description'          => (string) ($this->fields['description'] ?? ''),
            'schema_json'          => self::encodeJson($schema),
            'webhook_url'          => (string) ($this->fields['webhook_url'] ?? ''),
            'webhook_enabled'      => (int) ($this->fields['webhook_enabled'] ?? 1),
            'is_active'            => (int) ($this->fields['is_active'] ?? 1),
            'has_default_time'     => $minutes > 0 ? 1 : 0,
            'default_time_seconds' => $minutes * 60,
            'taskcategories_id'    => (int) ($this->fields['taskcategories_id'] ?? 0),
            'access_mode'          => $accessMode,
            'access_groups_id'     => $accessMode === 'groups' ? $accessIds : [],
            'access_profiles_id'   => $accessMode === 'profiles' ? $accessIds : [],
            'allow_save'           => (int) ($this->fields['allow_save'] ?? 0),
            'automations_json'     => (string) ($this->fields['automations_json'] ?? ''),
        ]);

        return (int) $newId;
    }

    public function prepareInputForAdd($input): array|false
    {
        if (!self::canCreate()) {
            return false;
        }

        $input = $this->normalizeInput($input, true);
        if ($input === false) {
            return false;
        }

        $input['version']       = 1;
        $input['users_id']      = Session::getLoginUserID();
        $input['date_creation'] = $_SESSION['glpi_currenttime'];
        $input['date_mod']      = $_SESSION['glpi_currenttime'];

        return $input;
    }

    public function prepareInputForUpdate($input): array|false
    {
        if (!self::canUpdate()) {
            return false;
        }

        $input = $this->normalizeInput($input, false);
        if ($input === false) {
            return false;
        }

        $currentVersion  = (int) ($this->fields['version'] ?? 1);
        $input['version'] = $currentVersion + 1;
        $input['date_mod'] = $_SESSION['glpi_currenttime'];

        $schema = self::decodeJson((string) $input['schema_json'], []);
        $schema['version'] = $input['version'];
        $input['schema_json'] = self::encodeJson($schema);

        return $input;
    }

    private function normalizeInput(array $input, bool $isNew): array|false
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            Session::addMessageAfterRedirect(
                __('Informe o nome da tarefa.', 'tarefas'),
                false,
                ERROR
            );
            return false;
        }

        $schemaRaw = (string) ($input['schema_json'] ?? '');
        $schema = self::decodeJson($schemaRaw, null);
        if (!is_array($schema) || empty($schema['fields']) || !is_array($schema['fields'])) {
            Session::addMessageAfterRedirect(
                __('Adicione pelo menos um campo válido ao formulário.', 'tarefas'),
                false,
                ERROR
            );
            return false;
        }

        $schema['title'] = $name;
        $schema['version'] = $isNew ? 1 : (int) ($this->fields['version'] ?? 1) + 1;
        $idMap = [];
        $schema['fields'] = self::normalizeFields($schema['fields'], $idMap);

        $hasDefaultTime = !empty($input['has_default_time']);
        $defaultMinutes = 0;
        if ($hasDefaultTime) {
            $postedSeconds = (int) ($input['default_time_seconds'] ?? 0);
            if ($postedSeconds > 0) {
                $defaultMinutes = (int) floor($postedSeconds / 60);
            } else {
                $defaultMinutes = ((int) ($input['default_time_hours'] ?? 0) * 60)
                    + (int) ($input['default_time_minutes_part'] ?? 0);
            }
        }
        if ($hasDefaultTime && $defaultMinutes <= 0) {
            Session::addMessageAfterRedirect(
                __('Informe um tempo padrão maior que zero.', 'tarefas'),
                false,
                ERROR
            );
            return false;
        }

        $accessMode = (string) ($input['access_mode'] ?? 'all');
        if (!in_array($accessMode, ['all', 'groups', 'profiles'], true)) {
            $accessMode = 'all';
        }
        $accessIds = [];
        if ($accessMode === 'groups') {
            $accessIds = self::normalizeIdList($input['access_groups_id'] ?? []);
        } elseif ($accessMode === 'profiles') {
            $accessIds = self::normalizeIdList($input['access_profiles_id'] ?? []);
        }
        if ($accessMode !== 'all' && $accessIds === []) {
            Session::addMessageAfterRedirect(
                __('Informe pelo menos um grupo ou perfil com permissão para usar este modelo.', 'tarefas'),
                false,
                ERROR
            );
            return false;
        }

        $automations = PluginTarefasAutomation::normalize(
            $input['automations_json'] ?? ($this->fields['automations_json'] ?? ''),
            (string) ($this->fields['webhook_url'] ?? ''),
            !empty($this->fields['webhook_enabled'] ?? 1)
        );
        $automations = self::remapAutomationsFieldIds($automations, $idMap);
        $autoError = PluginTarefasAutomation::validate($automations);
        if ($autoError !== null) {
            Session::addMessageAfterRedirect($autoError, false, ERROR);
            return false;
        }

        $input['name']                  = $name;
        $input['description']           = trim((string) ($input['description'] ?? ''));
        $input['automations_json']      = self::encodeJson($automations);
        $input['webhook_url']           = PluginTarefasAutomation::firstWebhookUrl($automations);
        $input['webhook_enabled']       = !empty($automations['enabled']) ? 1 : 0;
        $input['is_active']             = !empty($input['is_active']) ? 1 : 0;
        $input['allow_save']            = !empty($input['allow_save']) ? 1 : 0;
        $input['default_time_minutes']  = $defaultMinutes;
        $input['taskcategories_id']     = max(0, (int) ($input['taskcategories_id'] ?? 0));
        $input['access_mode']           = $accessMode;
        $input['access_ids']            = self::encodeJson($accessIds);
        $input['schema_json']           = self::encodeJson($schema);
        unset(
            $input['has_default_time'],
            $input['default_time_hours'],
            $input['default_time_minutes_part'],
            $input['default_time_seconds'],
            $input['access_groups_id'],
            $input['access_profiles_id']
        );

        return $input;
    }

    public static function normalizeFields(array $fields, ?array &$idMap = null): array
    {
        $result = [];
        $names = [];
        $allowedTypes = [
            'short_text', 'long_text', 'information', 'integer', 'date',
            'datetime', 'single_choice', 'multiple_choice', 'yes_no',
            'file', 'glpi_list',
        ];

        foreach (array_values($fields) as $index => $field) {
            if (!is_array($field) || !in_array($field['type'] ?? '', $allowedTypes, true)) {
                continue;
            }

            $label = trim((string) ($field['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($field['id'] ?? ''));
            if ($id === '') {
                $id = 'field_' . (count($result) + 1);
            }

            $name = self::slug((string) ($field['name'] ?? $label));
            $base = $name;
            $suffix = 2;
            while (isset($names[$name])) {
                $name = $base . '_' . $suffix++;
            }
            $names[$name] = true;

            $conditional = self::normalizeConditional(
                is_array($field['conditional'] ?? null) ? $field['conditional'] : []
            );

            $result[] = [
                'id'          => $id,
                'name'        => $name,
                'type'        => $field['type'],
                'label'       => $label,
                'description' => self::normalizeFieldDescription((string) ($field['description'] ?? '')),
                'required'    => !empty($field['required']) && $field['type'] !== 'information',
                'order'       => count($result) + 1,
                'conditional' => $conditional,
                'config'      => self::normalizeFieldConfig($field),
            ];
        }

        return self::assignSequentialFieldIds($result, $idMap);
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, string>|null $idMap
     * @return array<int, array<string, mixed>>
     */
    private static function assignSequentialFieldIds(array $fields, ?array &$idMap = null): array
    {
        $map = [];
        foreach ($fields as $index => $field) {
            $oldId = (string) ($field['id'] ?? '');
            $newId = 'field_' . ($index + 1);
            if ($oldId !== '') {
                $map[$oldId] = $newId;
            }
        }

        foreach ($fields as $index => &$field) {
            $field['id'] = 'field_' . ($index + 1);
            $field['conditional'] = self::remapConditionalFieldIds(
                is_array($field['conditional'] ?? null) ? $field['conditional'] : [],
                $map
            );
        }
        unset($field);

        if ($idMap !== null) {
            $idMap = $map;
        }

        return $fields;
    }

    /**
     * @param array<string, string> $idMap
     */
    public static function remapConditionalFieldIds(array $conditional, array $idMap): array
    {
        if ($idMap === []) {
            return $conditional;
        }

        $fieldId = (string) ($conditional['field_id'] ?? '');
        if ($fieldId !== '' && isset($idMap[$fieldId])) {
            $conditional['field_id'] = $idMap[$fieldId];
        }

        if (is_array($conditional['logic'] ?? null)) {
            $conditional['logic'] = self::remapLogicFieldIds($conditional['logic'], $idMap);
            self::syncConditionalLegacyFields($conditional);
        }

        return $conditional;
    }

    /**
     * @param array<string, string> $idMap
     */
    public static function remapLogicFieldIds(array $group, array $idMap): array
    {
        if ($idMap === [] || !is_array($group['items'] ?? null)) {
            return $group;
        }

        $items = [];
        foreach ($group['items'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (($item['type'] ?? '') === 'group') {
                $items[] = self::remapLogicFieldIds($item, $idMap);
                continue;
            }
            if (($item['type'] ?? '') === 'rule') {
                $fieldId = (string) ($item['field_id'] ?? '');
                if ($fieldId !== '' && isset($idMap[$fieldId])) {
                    $item['field_id'] = $idMap[$fieldId];
                }
            }
            $items[] = $item;
        }

        $group['items'] = $items;

        return $group;
    }

    /**
     * @param array<string, string> $idMap
     */
    public static function remapAutomationsFieldIds(array $automations, array $idMap): array
    {
        if ($idMap === [] || !is_array($automations['items'] ?? null)) {
            return $automations;
        }

        foreach ($automations['items'] as &$item) {
            if (!is_array($item['conditional'] ?? null)) {
                continue;
            }
            $item['conditional'] = self::remapConditionalFieldIds($item['conditional'], $idMap);
        }
        unset($item);

        return $automations;
    }

    public static function normalizeFieldConfig(array $field): array
    {
        $type = (string) ($field['type'] ?? '');
        $config = is_array($field['config'] ?? null) ? $field['config'] : [];

        if ($type !== 'glpi_list') {
            return $config;
        }

        $itemtype = (string) ($config['itemtype'] ?? Group::class);
        if ($itemtype === '' || !class_exists($itemtype)) {
            $itemtype = Group::class;
        }

        $source = (string) ($config['value_source'] ?? 'none');
        if (!in_array($source, ['none', 'fixed', 'location_group'], true)) {
            $source = 'none';
        }
        if ($source === 'location_group' && $itemtype !== Group::class) {
            $source = 'none';
        }

        return [
            'itemtype'      => $itemtype,
            'multiple'      => !empty($config['multiple']),
            'value_source'  => $source,
            'default_value' => trim((string) ($config['default_value'] ?? '')),
            'editable'      => !array_key_exists('editable', $config) || !empty($config['editable']),
        ];
    }

    public static function glpiListSelectedId(mixed $value): int
    {
        if (is_array($value)) {
            return max(0, (int) ($value['id'] ?? 0));
        }

        return max(0, (int) $value);
    }

    public static function glpiListSelectedIds(mixed $value): array
    {
        if (!is_array($value)) {
            $id = self::glpiListSelectedId($value);
            return $id > 0 ? [$id] : [];
        }

        if (array_key_exists('id', $value)) {
            $id = self::glpiListSelectedId($value);
            return $id > 0 ? [$id] : [];
        }

        $ids = [];
        foreach ($value as $item) {
            $id = self::glpiListSelectedId($item);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public static function resolveGlpiListDefaultValue(array $field, int $ticketsId, mixed $current = null): mixed
    {
        $config = is_array($field['config'] ?? null) ? $field['config'] : [];
        $multiple = !empty($config['multiple']);

        if ($multiple) {
            $ids = self::glpiListSelectedIds($current);
            return $ids !== [] ? $ids : [];
        }

        if ($current !== null && $current !== '' && $current !== []) {
            $existing = self::glpiListSelectedId($current);
            if ($existing > 0) {
                return $existing;
            }
        }

        $source = (string) ($config['value_source'] ?? 'none');
        if ($source === 'location_group' && $ticketsId > 0) {
            $ticket = new Ticket();
            if ($ticket->getFromDB($ticketsId)) {
                $groupId = PluginTarefasLocation::getGroupIdForTicket($ticket);
                return $groupId > 0 ? $groupId : null;
            }
        }
        if ($source === 'fixed') {
            $id = (int) ($config['default_value'] ?? 0);
            return $id > 0 ? $id : null;
        }

        return null;
    }

    public static function glpiListFieldLocked(array $field): bool
    {
        $config = is_array($field['config'] ?? null) ? $field['config'] : [];
        $source = (string) ($config['value_source'] ?? 'none');

        return $source !== 'none' && empty($config['editable']);
    }

    public static function normalizeFieldDescription(string $description): string
    {
        $description = trim($description);
        if ($description === '') {
            return '';
        }
        if (function_exists('mb_substr')) {
            return mb_substr($description, 0, self::FIELD_DESCRIPTION_MAX_LENGTH);
        }

        return substr($description, 0, self::FIELD_DESCRIPTION_MAX_LENGTH);
    }

    public static function getActiveModels(): array
    {
        global $DB;

        $models = [];
        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['is_active' => 1],
            'ORDER' => ['name ASC'],
        ]);

        foreach ($iterator as $row) {
            $schema = self::decodeJson((string) $row['schema_json'], []);
            if (!empty($schema['fields'])) {
                $models[] = $row;
            }
        }

        return $models;
    }

    /**
     * Modelos ativos que o usuário logado pode usar para criar tarefa.
     */
    public static function getUsableModels(): array
    {
        $models = [];
        foreach (self::getActiveModels() as $row) {
            $model = new self();
            $model->fields = $row;
            if ($model->canCurrentUserUse()) {
                $models[] = $row;
            }
        }
        return $models;
    }

    public function canCurrentUserUse(): bool
    {
        if (empty($this->fields['is_active'])) {
            return false;
        }

        if (Session::haveRight('config', UPDATE)) {
            return true;
        }

        $mode = (string) ($this->fields['access_mode'] ?? 'all');
        if ($mode === '' || $mode === 'all') {
            return true;
        }

        $ids = self::decodeIdList((string) ($this->fields['access_ids'] ?? ''));
        if ($ids === []) {
            return false;
        }

        if ($mode === 'profiles') {
            $profileId = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
            return in_array($profileId, $ids, true);
        }

        if ($mode === 'groups') {
            $groupIds = array_map('intval', (array) ($_SESSION['glpigroups'] ?? []));
            return array_intersect($ids, $groupIds) !== [];
        }

        return false;
    }

    public static function decodeIdList(string $json): array
    {
        $value = self::decodeJson($json, []);
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter(array_map('intval', $value)));
    }

    public static function normalizeIdList(mixed $raw): array
    {
        if (!is_array($raw)) {
            $raw = $raw === null || $raw === '' ? [] : [$raw];
        }
        return array_values(array_unique(array_filter(array_map('intval', $raw))));
    }

    public static function normalizeConditional(array $conditional): array
    {
        $conditional = array_merge(
            ['enabled' => false, 'field_id' => '', 'operator' => 'or', 'value' => '', 'values' => [], 'logic' => []],
            $conditional
        );

        if (!is_array($conditional['logic'] ?? null) || ($conditional['logic']['type'] ?? '') !== 'group') {
            $conditional['logic'] = ['type' => 'group', 'items' => []];
        }

        if (!empty($conditional['enabled'])
            && empty($conditional['logic']['items'])
            && (string) ($conditional['field_id'] ?? '') !== '') {
            $values = is_array($conditional['values'] ?? null)
                ? array_values(array_filter(array_map('strval', $conditional['values']), 'strlen'))
                : [];
            if ($values === [] && (string) ($conditional['value'] ?? '') !== '') {
                $values = [(string) $conditional['value']];
            }
            $operator = strtolower((string) ($conditional['operator'] ?? 'or')) === 'and' ? 'and' : 'or';
            $conditional['logic']['items'][] = [
                'type'     => 'rule',
                'join'     => null,
                'field_id' => (string) $conditional['field_id'],
                'operator' => $operator,
                'values'   => $values,
                'value'    => $values[0] ?? '',
            ];
        }

        $conditional['logic'] = self::normalizeLogicGroup($conditional['logic']);
        self::syncConditionalLegacyFields($conditional);

        return $conditional;
    }

    public static function normalizeLogicGroup(array $group): array
    {
        $items = [];
        foreach (array_values($group['items'] ?? []) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            if (($item['type'] ?? '') === 'group') {
                $nested = self::normalizeLogicGroup($item);
                if ($nested['items'] === []) {
                    continue;
                }
                $items[] = [
                    'type'  => 'group',
                    'id'    => (string) ($item['id'] ?? ('group_' . ($index + 1))),
                    'join'  => $index === 0 ? null : self::normalizeJoin($item['join'] ?? 'or'),
                    'items' => $nested['items'],
                ];
                continue;
            }

            if (($item['type'] ?? '') !== 'rule') {
                continue;
            }

            $fieldId = (string) ($item['field_id'] ?? '');
            $values = is_array($item['values'] ?? null)
                ? array_values(array_filter(array_map('strval', $item['values']), 'strlen'))
                : [];
            if ($values === [] && (string) ($item['value'] ?? '') !== '') {
                $values = [(string) $item['value']];
            }
            if ($fieldId === '' || $values === []) {
                continue;
            }

            $operator = strtolower((string) ($item['operator'] ?? 'or')) === 'and' ? 'and' : 'or';
            $items[] = [
                'type'     => 'rule',
                'id'       => (string) ($item['id'] ?? ('rule_' . ($index + 1))),
                'join'     => $index === 0 ? null : self::normalizeJoin($item['join'] ?? 'or'),
                'field_id' => $fieldId,
                'operator' => $operator,
                'values'   => $values,
                'value'    => $values[0] ?? '',
            ];
        }

        return ['type' => 'group', 'items' => $items];
    }

    private static function normalizeJoin(mixed $join): string
    {
        return strtolower((string) $join) === 'and' ? 'and' : 'or';
    }

    private static function syncConditionalLegacyFields(array &$conditional): void
    {
        $firstRule = self::findFirstLogicRule($conditional['logic'] ?? []);
        if ($firstRule === null) {
            $conditional['field_id'] = '';
            $conditional['operator'] = 'or';
            $conditional['values'] = [];
            $conditional['value'] = '';
            return;
        }

        $conditional['field_id'] = (string) ($firstRule['field_id'] ?? '');
        $conditional['operator'] = (string) ($firstRule['operator'] ?? 'or');
        $conditional['values'] = is_array($firstRule['values'] ?? null) ? $firstRule['values'] : [];
        $conditional['value'] = $conditional['values'][0] ?? '';
    }

    public static function findFirstLogicRule(array $group): ?array
    {
        foreach ($group['items'] ?? [] as $item) {
            if (($item['type'] ?? '') === 'rule') {
                return $item;
            }
            if (($item['type'] ?? '') === 'group') {
                $found = self::findFirstLogicRule($item);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    public static function purgeFieldFromLogic(array $group, string $fieldId): array
    {
        $items = [];
        foreach ($group['items'] ?? [] as $item) {
            if (($item['type'] ?? '') === 'group') {
                $nested = self::purgeFieldFromLogic($item, $fieldId);
                if ($nested['items'] !== []) {
                    $items[] = array_merge($item, $nested);
                }
                continue;
            }
            if (($item['field_id'] ?? '') !== $fieldId) {
                $items[] = $item;
            }
        }

        foreach ($items as $index => &$item) {
            $item['join'] = $index === 0 ? null : self::normalizeJoin($item['join'] ?? 'or');
        }
        unset($item);

        return self::normalizeLogicGroup(['type' => 'group', 'items' => $items]);
    }

    public static function logicHasRules(array $group): bool
    {
        foreach ($group['items'] ?? [] as $item) {
            if (($item['type'] ?? '') === 'rule') {
                return true;
            }
            if (($item['type'] ?? '') === 'group' && self::logicHasRules($item)) {
                return true;
            }
        }
        return false;
    }

    public static function conditionMatches(array $field, array $fields, array $values): bool
    {
        $condition = self::normalizeConditional(is_array($field['conditional'] ?? null) ? $field['conditional'] : []);
        if (empty($condition['enabled'])) {
            return true;
        }

        if (!self::logicHasRules($condition['logic'])) {
            return true;
        }

        return self::evaluateLogicGroup($condition['logic'], $fields, $values);
    }

    public static function evaluateLogicGroup(
        array $group,
        array $fields,
        array $values,
        bool $missingFieldFails = false
    ): bool {
        $result = null;
        foreach ($group['items'] ?? [] as $item) {
            $current = ($item['type'] ?? '') === 'group'
                ? self::evaluateLogicGroup($item, $fields, $values, $missingFieldFails)
                : self::evaluateLogicRule($item, $fields, $values, $missingFieldFails);

            if ($result === null) {
                $result = $current;
                continue;
            }

            if (self::normalizeJoin($item['join'] ?? 'or') === 'and') {
                $result = $result && $current;
            } else {
                $result = $result || $current;
            }
        }

        return $result ?? true;
    }

    public static function evaluateLogicRule(
        array $rule,
        array $fields,
        array $values,
        bool $missingFieldFails = false
    ): bool {
        $fieldId = (string) ($rule['field_id'] ?? '');
        if ($fieldId === '') {
            return !$missingFieldFails;
        }

        $source = null;
        foreach ($fields as $candidate) {
            if (($candidate['id'] ?? '') === $fieldId || ($candidate['name'] ?? '') === $fieldId) {
                $source = $candidate;
                break;
            }
        }
        if ($source === null) {
            return !$missingFieldFails;
        }

        $expected = is_array($rule['values'] ?? null)
            ? array_values(array_filter(array_map('strval', $rule['values']), 'strlen'))
            : [];
        if ($expected === [] && (string) ($rule['value'] ?? '') !== '') {
            $expected = [(string) $rule['value']];
        }
        $expected = array_values(array_filter(array_map(
            static fn(string $token) => self::normalizeConditionToken($source, $token),
            $expected
        ), 'strlen'));
        if ($expected === []) {
            return true;
        }

        $operator = strtolower((string) ($rule['operator'] ?? 'or')) === 'and' ? 'and' : 'or';
        $actual = self::conditionActualValues($source, $values[$source['name']] ?? null);
        if ($operator === 'and') {
            return array_diff($expected, $actual) === [];
        }
        return array_intersect($expected, $actual) !== [];
    }

    public static function normalizeConditionToken(array $source, string $value): string
    {
        $token = trim($value);
        if ($token === '') {
            return '';
        }
        if (($source['type'] ?? '') === 'yes_no') {
            $lower = strtolower($token);
            if (in_array($lower, ['yes', 'sim', '1', 'true'], true)) {
                return 'yes';
            }
            if (in_array($lower, ['nao', 'não', 'no', '0', 'false'], true)) {
                return 'no';
            }
        }
        return $token;
    }

    public static function conditionActualValues(array $source, mixed $value): array
    {
        $type = (string) ($source['type'] ?? '');
        if ($type === 'multiple_choice') {
            return is_array($value) ? array_map('strval', $value) : [];
        }
        if ($type === 'glpi_list') {
            $items = !empty($source['config']['multiple']) ? (array) $value : [$value];
            $ids = [];
            foreach ($items as $item) {
                if (is_array($item)) {
                    $id = (int) ($item['id'] ?? 0);
                } else {
                    $id = (int) $item;
                }
                if ($id > 0) {
                    $ids[] = (string) $id;
                }
            }
            return $ids;
        }
        if ($value === null || $value === '') {
            return [];
        }
        return [self::normalizeConditionToken($source, (string) $value)];
    }

    public static function categoryName(int $categoryId): string
    {
        if ($categoryId <= 0) {
            return '';
        }
        $category = new TaskCategory();
        return $category->getFromDB($categoryId) ? $category->getName() : '';
    }

    public static function groupName(int $groupId): string
    {
        if ($groupId <= 0) {
            return '';
        }
        $group = new Group();
        return $group->getFromDB($groupId) ? $group->getName() : '';
    }

    public static function defaultTicketGroupId(Ticket $ticket): int
    {
        $fromLocation = PluginTarefasLocation::getGroupIdForTicket($ticket);
        if ($fromLocation > 0) {
            return $fromLocation;
        }

        $groups = $ticket->getGroups(CommonITILActor::ASSIGN);
        if (!is_array($groups) || $groups === []) {
            return 0;
        }
        $first = reset($groups);
        if (is_array($first)) {
            return (int) ($first['groups_id'] ?? $first['id'] ?? 0);
        }
        return (int) $first;
    }

    public static function usageCount(int $modelId): int
    {
        global $DB;

        return countElementsInTable(
            PluginTarefasTask::getTable(),
            ['plugin_tarefas_models_id' => $modelId]
        );
    }

    public static function decodeJson(string $json, mixed $default = []): mixed
    {
        if ($json === '') {
            return $default;
        }
        $value = json_decode($json, true);
        return json_last_error() === JSON_ERROR_NONE ? $value : $default;
    }

    public static function encodeJson(mixed $value): string
    {
        return (string) json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    public static function slug(string $value): string
    {
        $value = strtolower(strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if (class_exists(Transliterator::class)) {
            $transliterator = Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
            $value = $transliterator ? $transliterator->transliterate($value) : $value;
        }
        $value = preg_replace('/[^a-z0-9]+/', '_', $value);
        $value = trim((string) $value, '_');
        return $value !== '' ? substr($value, 0, 80) : 'field';
    }
}
