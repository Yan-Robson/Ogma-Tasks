<?php

/**
 * Renderização do formulário da tarefa, usada tanto na timeline do chamado
 * (carregada por ajax) quanto na tela de edição.
 */
class PluginTarefasForm
{
    public static function renderBody(array $params): void
    {
        $schema      = is_array($params['schema'] ?? null) ? $params['schema'] : [];
        $content     = is_array($params['content'] ?? null) ? $params['content'] : [];
        $cancelUrl   = (string) ($params['cancel_url'] ?? '');
        $minutes     = max(0, (int) ($params['minutes'] ?? 0));
        $isPrivate   = !empty($params['is_private']);
        $ticketsId   = (int) ($params['tickets_id'] ?? 0);
        $executorId  = (int) ($params['users_id_executor'] ?? Session::getLoginUserID());
        $groupId     = (int) ($params['groups_id'] ?? 0);
        $allowSave   = !empty($params['allow_save']);
        $automations = PluginTarefasAutomation::normalize($params['automations'] ?? []);
        $categoryName = trim((string) ($params['taskcategory_name'] ?? ''));
        $modelVersion = (int) ($params['model_version'] ?? ($schema['version'] ?? 0));
        $modelName    = (string) ($params['model_name'] ?? '');
        $categoryId   = (int) ($params['taskcategories_id'] ?? 0);
        $webhookUrl   = (string) ($params['webhook_url'] ?? '');
        if ($executorId <= 0) {
            $executorId = (int) Session::getLoginUserID();
        }

        $includeVisibility = !array_key_exists('include_visibility', $params)
            || !empty($params['include_visibility']);
        $showModelLabel = !empty($params['show_model_label']) && $modelName !== '';
        ?>
        <div class="plugin-tarefas-form-compact">
        <?php if ($categoryName !== ''): ?>
            <div class="plugin-tarefas-task-meta">
                <div class="text-muted small">
                    <i class="ti ti-tag me-1"></i><?= htmlescape($categoryName) ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($showModelLabel && $includeVisibility): ?>
            <?php self::renderModelVisibilityRow($modelName, $isPrivate); ?>
        <?php elseif ($showModelLabel): ?>
            <div class="plugin-tarefas-model-label-row mb-2">
                <span class="plugin-tarefas-model-label-row__title"><?= htmlescape(__('Modelo', 'tarefas')) ?></span>
                <span class="plugin-tarefas-model-label-row__name"><?= htmlescape($modelName) ?></span>
            </div>
        <?php endif; ?>

        <?php self::renderMetaToolbar($executorId, $groupId, $minutes); ?>

        <?php if ($includeVisibility && !$showModelLabel): ?>
            <div class="plugin-tarefas-meta-visibility-row mb-2">
                <?php self::renderVisibility($isPrivate); ?>
            </div>
        <?php endif; ?>

        <hr class="my-2">

        <?php
        $allFields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];
        self::renderFields($allFields, $content, $ticketsId);
        ?>

        <?php self::renderAutomationFields($automations, $content); ?>

        <div class="d-flex justify-content-end gap-2 mt-1">
            <?php if ($cancelUrl !== ''): ?>
                <a class="btn btn-outline-secondary" href="<?= htmlescape($cancelUrl) ?>">
                    <?= htmlescape(__('Cancelar')) ?>
                </a>
            <?php endif; ?>
            <?php if ($allowSave): ?>
                <button class="btn btn-outline-secondary" type="submit" name="save_mode" value="draft" formnovalidate>
                    <i class="ti ti-device-floppy"></i> <?= htmlescape(__('Salvar', 'tarefas')) ?>
                </button>
            <?php endif; ?>
            <button class="btn plugin-tarefas-add-btn" type="submit" name="save_mode" value="finished">
                <i class="ti ti-check"></i> <?= htmlescape(__('Finalizar', 'tarefas')) ?>
            </button>
        </div>

        <input type="hidden" id="task-schema" name="snapshot_schema_json"
               value="<?= htmlescape(PluginTarefasModel::encodeJson($schema)) ?>">
        <input type="hidden" name="snapshot_automations_json"
               value="<?= htmlescape(PluginTarefasModel::encodeJson($automations)) ?>">
        <input type="hidden" name="snapshot_model_version" value="<?= $modelVersion ?>">
        <input type="hidden" name="snapshot_model_name" value="<?= htmlescape($modelName) ?>">
        <input type="hidden" name="snapshot_allow_save" value="<?= $allowSave ? 1 : 0 ?>">
        <input type="hidden" name="snapshot_taskcategories_id" value="<?= $categoryId ?>">
        <input type="hidden" name="snapshot_webhook_url" value="<?= htmlescape($webhookUrl) ?>">
        </div>
        <?php
    }

    public static function renderModelVisibilityRow(string $modelName, bool $isPrivate): void
    {
        ?>
        <div class="plugin-tarefas-head-row mb-2">
            <div class="plugin-tarefas-head-row__model">
                <span class="plugin-tarefas-meta-label"><?= htmlescape(__('Modelo', 'tarefas')) ?></span>
                <span class="plugin-tarefas-model-label-row__name"><?= htmlescape($modelName) ?></span>
            </div>
            <div class="plugin-tarefas-head-row__visibility">
                <?php self::renderVisibility($isPrivate); ?>
            </div>
        </div>
        <?php
    }

    public static function renderVisibility(bool $isPrivate): void
    {
        echo self::renderVisibilityHtml($isPrivate);
    }

    public static function renderVisibilityHtml(bool $isPrivate): string
    {
        ob_start();
        ?>
        <div class="plugin-tarefas-visibility-wrap">
            <span class="plugin-tarefas-meta-label">
                <?= htmlescape(__('Visibilidade', 'tarefas')) ?> <span class="text-danger">*</span>
            </span>
            <div class="plugin-tarefas-visibility plugin-tarefas-visibility--compact">
                <label class="plugin-tarefas-visibility__option">
                    <input type="radio" name="is_private" value="0" <?= $isPrivate ? '' : 'checked' ?>>
                    <span><?= htmlescape(__('Pública', 'tarefas')) ?></span>
                </label>
                <label class="plugin-tarefas-visibility__option">
                    <input type="radio" name="is_private" value="1" <?= $isPrivate ? 'checked' : '' ?>>
                    <span><?= htmlescape(__('Privada', 'tarefas')) ?></span>
                </label>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public static function renderMetaToolbar(int $executorId, int $groupId, int $minutes): void
    {
        ?>
        <div class="plugin-tarefas-meta-grid mb-2">
            <div class="plugin-tarefas-meta-cell">
                <label class="plugin-tarefas-meta-label" for="users_id_executor">
                    <?= htmlescape(__('Quem executou', 'tarefas')) ?> <span class="text-danger">*</span>
                </label>
                <?php Dropdown::show(User::class, [
                    'name'  => 'users_id_executor',
                    'value' => $executorId,
                    'right' => 'all',
                    'width' => '100%',
                ]); ?>
            </div>
            <div class="plugin-tarefas-meta-cell">
                <label class="plugin-tarefas-meta-label" for="groups_id">
                    <?= htmlescape(__('Grupo', 'tarefas')) ?>
                </label>
                <?php Dropdown::show(Group::class, [
                    'name'  => 'groups_id',
                    'value' => $groupId,
                    'width' => '100%',
                ]); ?>
            </div>
            <div class="plugin-tarefas-meta-cell">
                <label class="plugin-tarefas-meta-label">
                    <?= htmlescape(__('Tempo da tarefa', 'tarefas')) ?> <span class="text-danger">*</span>
                </label>
                <?php self::renderTimeInputs($minutes); ?>
            </div>
        </div>
        <?php
    }

    public static function renderAutomationFields(array $automations, array $content): void
    {
        if (empty($automations['enabled'])) {
            return;
        }

        $overrides = is_array($content['_automations'] ?? null) ? $content['_automations'] : [];
        $statuses = PluginTarefasAutomation::ticketStatuses();
        $shown = false;

        foreach ($automations['items'] ?? [] as $item) {
            if (empty($item['editable']) || ($item['source'] ?? '') !== 'fixed') {
                continue;
            }
            $kind = (string) ($item['kind'] ?? '');
            if (!in_array($kind, ['status', 'group'], true)) {
                continue;
            }
            if (!$shown) {
                echo '<hr class="my-2">';
                echo '<div class="text-muted small mb-2">'
                    . htmlescape(__('Valores das automações (podem ser alterados nesta tarefa)', 'tarefas'))
                    . '</div>';
                $shown = true;
            }

            $id = (string) ($item['id'] ?? '');
            $value = $overrides[$id] ?? ($item['value'] ?? '');
            $label = $kind === 'status'
                ? __('Status do chamado', 'tarefas')
                : __('Grupo atribuído', 'tarefas');
            ?>
            <div class="plugin-tarefas-field-row mb-2">
                <label class="plugin-tarefas-meta-label">
                    <?= htmlescape($label) ?>
                </label>
                <div class="plugin-tarefas-field-control">
                    <?php if ($kind === 'status'): ?>
                        <select class="form-select" name="automation_values[<?= htmlescape($id) ?>]">
                            <option value="">-----</option>
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?= htmlescape($status['id']) ?>"
                                    <?= (string) $value === (string) $status['id'] ? 'selected' : '' ?>>
                                    <?= htmlescape($status['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <?php Dropdown::show(Group::class, [
                            'name'  => 'automation_values[' . $id . ']',
                            'value' => (int) $value,
                            'width' => '100%',
                        ]); ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php
        }
    }

    public static function currentUserLabel(): string
    {
        return sprintf(
            __('Registrado por %s', 'tarefas'),
            (string) ($_SESSION['glpifriendlyname'] ?? $_SESSION['glpiname'] ?? '')
        );
    }

    public static function postedMinutes(string $name = 'actiontime'): int
    {
        $seconds = (int) ($_POST[$name] ?? 0);
        if ($seconds <= 0) {
            return 0;
        }
        return max(1, (int) floor($seconds / 60));
    }

    public static function renderTimeInputs(
        int $minutes,
        string $name = 'actiontime'
    ): void {
        $seconds = max(0, $minutes) * 60;
        Dropdown::showTimeStamp($name, [
            'value'            => $seconds,
            'min'              => 0,
            'max'              => 12 * HOUR_TIMESTAMP,
            'addfirstminutes'  => true,
            'inhours'          => true,
            'display_emptychoice' => false,
            'width'            => '100%',
        ]);
    }

    public static function isImageFile(array $file): bool
    {
        $mime = strtolower((string) ($file['mime'] ?? ''));
        $name = strtolower((string) ($file['name'] ?? ''));
        return str_starts_with($mime, 'image/')
            || preg_match('/\.(png|jpe?g|gif|webp|bmp|svg)$/i', $name) === 1;
    }

    public static function renderAttachment(array $file, ?int $ticketsId = 0): string
    {
        global $CFG_GLPI, $DB;

        $ticketsId = (int) ($ticketsId ?? 0);
        $documentId = (int) ($file['document_id'] ?? 0);
        if ($documentId <= 0) {
            return '';
        }

        $document = new Document();
        $documentName = '';
        if ($document->getFromDB($documentId)) {
            $documentName = (string) ($document->fields['name'] ?: $document->fields['filename'] ?: '');
            if (trim((string) ($file['name'] ?? '')) === '') {
                $file['name'] = $documentName !== '' ? $documentName : __('Arquivo');
            }
            if (trim((string) ($file['mime'] ?? '')) === '') {
                $file['mime'] = (string) ($document->fields['mime'] ?? '');
            }
            if ($ticketsId <= 0) {
                $ticketsId = (int) ($document->fields['tickets_id'] ?? 0);
            }
        }

        if ($ticketsId <= 0) {
            foreach (
                $DB->request([
                    'FROM'  => 'glpi_documents_items',
                    'WHERE' => [
                        'documents_id' => $documentId,
                        'itemtype'     => Ticket::class,
                    ],
                    'LIMIT' => 1,
                ]) as $row
            ) {
                $ticketsId = (int) ($row['items_id'] ?? 0);
            }
        }

        $nativeUrl = ($CFG_GLPI['root_doc'] ?? '') . '/front/document.send.php?docid=' . $documentId;
        if ($ticketsId > 0) {
            $nativeUrl .= '&itemtype=' . urlencode(Ticket::class) . '&items_id=' . $ticketsId;
        }
        $pluginUrl = Plugin::getWebDir('tarefas') . '/front/document.php?docid=' . $documentId;
        $name = (string) ($file['name'] ?: $documentName ?: __('Arquivo'));
        $name = basename(str_replace(['\\', '/'], '_', $name));
        if (self::isImageFile($file)) {
            return '<div class="plugin-tarefas-attachment">'
                . '<a href="' . htmlescape($pluginUrl . '&download=1') . '">'
                . '<img class="plugin-tarefas-attachment__img" src="' . htmlescape($pluginUrl) . '" alt="'
                . htmlescape($name) . '"></a>'
                . '<div class="small mt-1"><a href="' . htmlescape($pluginUrl . '&download=1') . '">'
                . '<i class="ti ti-download me-1"></i>' . htmlescape($name) . '</a></div></div>';
        }

        return '<a class="plugin-tarefas-attachment-file" href="' . htmlescape($nativeUrl)
            . '" target="_blank" rel="noopener">'
            . '<i class="ti ti-paperclip me-1"></i>' . htmlescape($name) . '</a>';
    }

    public static function fieldUsesFullWidth(array $field): bool
    {
        $type = (string) ($field['type'] ?? '');
        if ($type === 'information') {
            return true;
        }
        if (in_array($type, ['long_text', 'file', 'glpi_list', 'multiple_choice'], true)) {
            return true;
        }
        if ($type === 'date' && !empty($field['config']['range'])) {
            return true;
        }

        return false;
    }

    public static function fieldIsCompact(array $field): bool
    {
        return !self::fieldUsesFullWidth($field);
    }

    public static function renderFields(array $fields, array $content, int $ticketsId = 0): void
    {
        if ($fields === []) {
            return;
        }

        echo '<div class="plugin-tarefas-fields-stack">';
        $pending = [];

        $flushPair = static function () use (&$pending, $content, $ticketsId, $fields): void {
            if ($pending === []) {
                return;
            }

            if (count($pending) === 1) {
                self::renderField($pending[0], $content, $ticketsId, $fields, 'solo');
                $pending = [];
                return;
            }

            echo '<div class="plugin-tarefas-fields-row">';
            foreach ($pending as $field) {
                self::renderField($field, $content, $ticketsId, $fields, 'compact');
            }
            echo '</div>';
            $pending = [];
        };

        foreach ($fields as $field) {
            if (self::fieldUsesFullWidth($field)) {
                $flushPair();
                self::renderField($field, $content, $ticketsId, $fields, 'full');
                continue;
            }

            $pending[] = $field;
            if (count($pending) === 2) {
                $flushPair();
            }
        }

        $flushPair();
        echo '</div>';
    }

    public static function renderField(
        array $field,
        array $content,
        int $ticketsId = 0,
        array $allFields = [],
        string $layout = 'full'
    ): void {
        $name = (string) ($field['name'] ?? '');
        $id = 'ptf_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
        $value = $content[$name] ?? null;
        $required = !empty($field['required']);
        $fields = $allFields !== [] ? $allFields : [$field];
        $visible = PluginTarefasModel::conditionMatches($field, $fields, $content);
        $type = (string) ($field['type'] ?? '');

        $layoutClass = match ($layout) {
            'compact' => ' plugin-tarefas-field-row--compact',
            'solo'    => ' plugin-tarefas-field-row--solo',
            default   => ' plugin-tarefas-field-row--full',
        };
        if ($layout === 'compact' && in_array($type, ['yes_no', 'single_choice'], true)) {
            $layoutClass .= ' plugin-tarefas-field-row--narrow';
        }

        $rowClass = 'plugin-tarefas-field-row' . $layoutClass . ($visible ? '' : ' d-none');
        ?>
        <div class="<?= htmlescape(trim($rowClass)) ?>" data-field-row="<?= htmlescape((string) ($field['id'] ?? $name)) ?>"
             data-field-name="<?= htmlescape($name) ?>">
            <?php if ($type === 'information'): ?>
                <div class="alert alert-info mb-0">
                    <?= nl2br(htmlescape((string) ($field['config']['text'] ?? ''))) ?>
                </div>
            <?php else: ?>
                <label class="plugin-tarefas-meta-label" for="<?= htmlescape($id) ?>">
                    <?= htmlescape((string) ($field['label'] ?? $name)) ?><?= $required ? ' <span class="text-danger">*</span>' : '' ?>
                </label>
                <div class="plugin-tarefas-field-control">
                    <?php self::renderControl($field, $value, $id, $ticketsId, $visible); ?>
                    <?php self::renderFieldHint((string) ($field['description'] ?? '')); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function renderFieldHint(string $description): void
    {
        $text = PluginTarefasModel::normalizeFieldDescription($description);
        if ($text === '') {
            return;
        }

        $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        $sizeClass = $length > 56 ? ' plugin-tarefas-field-hint--long'
            : ($length > 34 ? ' plugin-tarefas-field-hint--medium' : '');

        echo '<div class="plugin-tarefas-field-hint' . htmlescape($sizeClass) . '" title="'
            . htmlescape($text) . '">' . htmlescape($text) . '</div>';
    }

    public static function renderControl(
        array $field,
        mixed $value,
        string $id,
        int $ticketsId = 0,
        bool $enabled = true
    ): void {
        $name = (string) $field['name'];
        $inputName = 'answers[' . $name . ']';
        $required = !empty($field['required']) && $enabled ? ' required' : '';
        $disabled = $enabled ? '' : ' disabled';
        $type = (string) $field['type'];
        $config = $field['config'] ?? [];

        if ($type === 'short_text') {
            echo '<input class="form-control" type="text" id="' . htmlescape($id)
                . '" name="' . htmlescape($inputName) . '" value="' . htmlescape((string) $value)
                . '" data-mask="' . htmlescape((string) ($config['mask'] ?? 'none')) . '"' . $required . $disabled . '>';
        } elseif ($type === 'long_text') {
            echo '<textarea class="form-control" id="' . htmlescape($id)
                . '" name="' . htmlescape($inputName) . '" rows="' . max(2, (int) ($config['rows'] ?? 4))
                . '"' . $required . $disabled . '>' . htmlescape((string) $value) . '</textarea>';
        } elseif ($type === 'integer') {
            echo '<input class="form-control" type="text" inputmode="numeric" data-integer id="'
                . htmlescape($id) . '" name="' . htmlescape($inputName) . '" value="'
                . htmlescape((string) $value) . '"' . $required . $disabled . '>';
        } elseif ($type === 'date' && !empty($config['range'])) {
            echo '<div class="plugin-tarefas-date-range">';
            echo '<input class="form-control" type="date" name="' . htmlescape($inputName . '[start]')
                . '" value="' . htmlescape((string) ($value['start'] ?? '')) . '"' . $required . $disabled . '>';
            echo '<span class="plugin-tarefas-date-range__sep">' . htmlescape(__('até', 'tarefas')) . '</span>';
            echo '<input class="form-control" type="date" name="' . htmlescape($inputName . '[end]')
                . '" value="' . htmlescape((string) ($value['end'] ?? '')) . '"' . $required . $disabled . '>';
            echo '</div><div class="form-hint">'
                . htmlescape(__('Este intervalo não altera o tempo de produtividade.', 'tarefas')) . '</div>';
        } elseif ($type === 'date') {
            echo '<input class="form-control" type="date" id="' . htmlescape($id)
                . '" name="' . htmlescape($inputName) . '" value="' . htmlescape((string) $value)
                . '"' . $required . $disabled . '>';
        } elseif ($type === 'datetime') {
            echo '<input class="form-control" type="datetime-local" id="' . htmlescape($id)
                . '" name="' . htmlescape($inputName) . '" value="' . htmlescape((string) $value)
                . '"' . $required . $disabled . '>';
        } elseif ($type === 'yes_no' || $type === 'single_choice') {
            $options = $type === 'yes_no'
                ? ['yes' => __('Sim'), 'no' => __('Não')]
                : array_combine($config['options'] ?? [], $config['options'] ?? []);
            echo '<select class="form-select" id="' . htmlescape($id) . '" name="'
                . htmlescape($inputName) . '"' . $required . $disabled . '><option value="">-----</option>';
            foreach ($options as $optionValue => $label) {
                echo '<option value="' . htmlescape((string) $optionValue) . '"'
                    . ((string) $value === (string) $optionValue ? ' selected' : '') . '>'
                    . htmlescape((string) $label) . '</option>';
            }
            echo '</select>';
        } elseif ($type === 'multiple_choice') {
            echo '<div class="plugin-tarefas-options">';
            foreach (($config['options'] ?? []) as $option) {
                $checked = in_array($option, (array) $value, true) ? ' checked' : '';
                echo '<label class="form-check"><input class="form-check-input" type="checkbox" name="'
                    . htmlescape($inputName . '[]') . '" value="' . htmlescape((string) $option) . '"' . $checked
                    . $disabled . '><span class="form-check-label">' . htmlescape((string) $option) . '</span></label>';
            }
            echo '</div>';
        } elseif ($type === 'glpi_list') {
            $config = is_array($config) ? $config : [];
            if ($value === null || $value === '' || $value === []) {
                $value = PluginTarefasModel::resolveGlpiListDefaultValue($field, $ticketsId, $value);
            }

            $multiple = !empty($config['multiple']);
            $selected = $multiple
                ? PluginTarefasModel::glpiListSelectedIds($value)
                : PluginTarefasModel::glpiListSelectedId($value);
            $locked = $enabled && PluginTarefasModel::glpiListFieldLocked($field);
            if ($locked) {
                $disabled = ' disabled';
            }

            if ($locked && !$multiple) {
                $selectedId = is_array($selected) ? 0 : (int) $selected;
                echo '<input type="hidden" name="' . htmlescape($inputName) . '" value="' . $selectedId . '">';
            } elseif ($locked && $multiple && is_array($selected)) {
                foreach ($selected as $selectedId) {
                    echo '<input type="hidden" name="' . htmlescape($inputName) . '[]" value="'
                        . (int) $selectedId . '">';
                }
            }

            // Sempre display=true: com display=false o HTML é retornado e descartado aqui,
            // e o campo some no formulário (ex.: lista GLPI com valor fixo editável via AJAX).
            $dropdownDisabled = !$enabled || $locked;
            Dropdown::show((string) ($config['itemtype'] ?? Group::class), [
                'name'          => $inputName . ($multiple ? '[]' : ''),
                'value'         => $selected,
                'multiple'      => $multiple,
                'width'         => '100%',
                'display'       => true,
                'comments'      => false,
                'specific_tags' => $dropdownDisabled ? ['disabled' => 'disabled'] : [],
            ]);
        } elseif ($type === 'file') {
            $accept = htmlescape((string) ($config['extensions'] ?? ''));
            echo '<input class="form-control" type="file" id="' . htmlescape($id) . '" name="files['
                . htmlescape($name) . '][]"' . (!empty($config['multiple']) ? ' multiple' : '')
                . ($accept !== '' ? ' accept="' . $accept . '"' : '') . $disabled . '>';
            foreach ((array) $value as $file) {
                if (is_array($file) && !empty($file['document_id'])) {
                    echo self::renderAttachment($file, $ticketsId);
                }
            }
        }
    }

    public static function rawAnswers(array $schema, array $existing = []): array
    {
        $answers = $existing;
        $posted = is_array($_POST['answers'] ?? null) ? $_POST['answers'] : [];

        foreach (($schema['fields'] ?? []) as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name === '' || ($field['type'] ?? '') === 'information') {
                continue;
            }
            if (($field['type'] ?? '') !== 'file') {
                $answers[$name] = $posted[$name] ?? null;
            }
        }

        foreach ($_FILES['files']['name'] ?? [] as $fieldName => $names) {
            $names = is_array($names) ? $names : [$names];
            if (array_filter($names, 'strlen') !== []) {
                $answers[$fieldName] = array_merge(
                    is_array($answers[$fieldName] ?? null) ? $answers[$fieldName] : [],
                    [['pending_upload' => true]]
                );
            }
        }

        return $answers;
    }

    public static function storeUploads(
        int $taskId,
        int $ticketId,
        array $schema,
        array $content
    ): array {
        global $DB;

        $taskRow = new PluginTarefasTask();
        $taskIsPrivate = $taskRow->getFromDB($taskId) && !empty($taskRow->fields['is_private']);

        $ticket = new Ticket();
        $ticket->getFromDB($ticketId);

        foreach (($schema['fields'] ?? []) as $field) {
            if (($field['type'] ?? '') !== 'file') {
                continue;
            }

            $fieldName = (string) $field['name'];
            $names = $_FILES['files']['name'][$fieldName] ?? [];
            $tmpNames = $_FILES['files']['tmp_name'][$fieldName] ?? [];
            $errors = $_FILES['files']['error'][$fieldName] ?? [];
            $sizes = $_FILES['files']['size'][$fieldName] ?? [];
            $types = $_FILES['files']['type'][$fieldName] ?? [];

            $names = is_array($names) ? $names : [$names];
            $tmpNames = is_array($tmpNames) ? $tmpNames : [$tmpNames];
            $errors = is_array($errors) ? $errors : [$errors];
            $sizes = is_array($sizes) ? $sizes : [$sizes];
            $types = is_array($types) ? $types : [$types];

            $files = array_values(array_filter(
                (array) ($content[$fieldName] ?? []),
                static fn($file) => is_array($file) && empty($file['pending_upload'])
            ));

            foreach ($names as $index => $originalName) {
                if ($originalName === '' || ($errors[$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    continue;
                }

                $maxBytes = max(1, (int) ($field['config']['max_mb'] ?? 20)) * 1024 * 1024;
                if ((int) ($sizes[$index] ?? 0) > $maxBytes) {
                    Session::addMessageAfterRedirect(
                        sprintf(__('O arquivo "%s" excede o limite do campo.', 'tarefas'), $originalName),
                        false,
                        ERROR
                    );
                    continue;
                }

                $extensionRules = array_filter(array_map(
                    static fn($ext) => strtolower(ltrim(trim($ext), '.')),
                    explode(',', (string) ($field['config']['extensions'] ?? ''))
                ));
                $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                if ($extensionRules !== [] && !in_array($extension, $extensionRules, true)) {
                    Session::addMessageAfterRedirect(
                        sprintf(__('O formato de "%s" não é permitido.', 'tarefas'), $originalName),
                        false,
                        ERROR
                    );
                    continue;
                }

                $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', basename($originalName)) ?: 'arquivo';
                $prefix = uniqid('', true);
                $temporaryName = $prefix . $safeName;
                $destination = GLPI_TMP_DIR . '/' . $temporaryName;
                if (!move_uploaded_file($tmpNames[$index], $destination)) {
                    Session::addMessageAfterRedirect(
                        sprintf(__('Não foi possível receber o arquivo "%s".', 'tarefas'), $originalName),
                        false,
                        ERROR
                    );
                    continue;
                }

                $documentInput = [
                    'name'                    => $originalName,
                    'entities_id'             => (int) ($ticket->fields['entities_id'] ?? 0),
                    'tickets_id'              => $ticketId,
                    '_filename'               => [$temporaryName],
                    '_prefix_filename'        => [$prefix],
                    'itemtype'                => Ticket::class,
                    'items_id'                => $ticketId,
                    '_only_if_upload_succeed' => true,
                ];
                if ($taskIsPrivate) {
                    $documentInput['_disablenotif'] = true;
                }
                $document = new Document();
                $documentId = $document->add($documentInput);
                if (!$documentId) {
                    @unlink($destination);
                    Session::addMessageAfterRedirect(
                        sprintf(__('Não foi possível gravar o arquivo "%s" no GLPI.', 'tarefas'), $originalName),
                        false,
                        ERROR
                    );
                    continue;
                }

                $document->getFromDB((int) $documentId);
                $mime = (string) ($document->fields['mime'] ?? $types[$index] ?? '');
                $storedName = (string) ($document->fields['filename'] ?: $originalName);

                $docItem = new Document_Item();
                $alreadyLinked = $docItem->find([
                    'documents_id' => (int) $documentId,
                    'itemtype'     => Ticket::class,
                    'items_id'     => $ticketId,
                ], [], 1);
                if ($alreadyLinked === []) {
                    $docItemInput = [
                        'documents_id' => (int) $documentId,
                        'itemtype'     => Ticket::class,
                        'items_id'     => $ticketId,
                    ];
                    if ($taskIsPrivate) {
                        $docItemInput['_disablenotif'] = true;
                    }
                    $docItem->add($docItemInput);
                }

                $DB->insert('glpi_plugin_tarefas_task_documents', [
                    'plugin_tarefas_tasks_id' => $taskId,
                    'documents_id'            => $documentId,
                    'field_name'              => $fieldName,
                ]);
                $files[] = [
                    'document_id' => (int) $documentId,
                    'name'        => $storedName,
                    'mime'        => $mime,
                    'size'        => (int) ($sizes[$index] ?? 0),
                ];

                if (empty($field['config']['multiple'])) {
                    break;
                }
            }

            $content[$fieldName] = $files;
        }

        return $content;
    }
}
