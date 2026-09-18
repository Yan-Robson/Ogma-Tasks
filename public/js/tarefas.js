// Sem depender do objeto global do Bootstrap, que nem sempre existe nas telas de plugin.
function modalController(id) {
    const element = document.getElementById(id);

    const cleanupOrphanBackdrops = () => {
        if (document.querySelector('.modal.show')) {
            return;
        }
        document.querySelectorAll('.modal-backdrop').forEach((node) => node.remove());
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
    };

    if (window.bootstrap && window.bootstrap.Modal) {
        return {
            show() {
                cleanupOrphanBackdrops();
                let instance = window.bootstrap.Modal.getInstance(element);
                const looksBroken = element.classList.contains('show')
                    && (element.style.display === 'none' || element.getAttribute('aria-hidden') === 'true');
                if (looksBroken && instance) {
                    try {
                        instance.dispose();
                    } catch (error) {
                        // ignore
                    }
                    instance = null;
                    element.classList.remove('show');
                    element.style.display = '';
                    element.removeAttribute('aria-hidden');
                    element.removeAttribute('inert');
                }
                if (!instance) {
                    instance = window.bootstrap.Modal.getOrCreateInstance(element);
                }
                if (element.classList.contains('show')) {
                    return;
                }
                instance.show();
            },
            hide() {
                const instance = window.bootstrap.Modal.getOrCreateInstance(element);
                instance.hide();
                element.addEventListener('hidden.bs.modal', cleanupOrphanBackdrops, {once: true});
            }
        };
    }

    let backdrop = null;
    const controller = {
        show() {
            cleanupOrphanBackdrops();
            if (element.classList.contains('show')) {
                return;
            }
            backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop fade show';
            document.body.appendChild(backdrop);
            document.body.classList.add('modal-open');
            element.classList.add('show');
            element.style.display = 'block';
            element.removeAttribute('aria-hidden');
        },
        hide() {
            element.classList.remove('show');
            element.style.display = 'none';
            element.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('modal-open');
            backdrop?.remove();
            backdrop = null;
            cleanupOrphanBackdrops();
        }
    };

    element.addEventListener('click', (event) => {
        if (event.target.closest('[data-bs-dismiss="modal"]') || event.target === element) {
            controller.hide();
        }
    });

    return controller;
}

function pluginTarefasConditionValues(condition) {
    if (Array.isArray(condition?.values) && condition.values.length) {
        return condition.values.map(String);
    }
    return condition?.value ? [String(condition.value)] : [];
}

function pluginTarefasLogicUid(prefix = 'logic') {
    return prefix + '_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 7);
}

function pluginTarefasBlankLogicGroup() {
    return {type: 'group', items: []};
}

function pluginTarefasBlankLogicRule() {
    return {
        type: 'rule',
        id: pluginTarefasLogicUid('rule'),
        join: null,
        field_id: '',
        operator: 'or',
        value: '',
        values: []
    };
}

function pluginTarefasBlankLogicNestedGroup() {
    return {
        type: 'group',
        id: pluginTarefasLogicUid('group'),
        join: 'or',
        items: [pluginTarefasBlankLogicRule()]
    };
}

function pluginTarefasNormalizeConditional(conditional) {
    conditional = conditional || {enabled: false, logic: pluginTarefasBlankLogicGroup()};
    if (!conditional.logic || conditional.logic.type !== 'group') {
        conditional.logic = pluginTarefasBlankLogicGroup();
    }
    if (conditional.enabled && conditional.field_id && !(conditional.logic.items || []).length) {
        conditional.logic.items = [{
            type: 'rule',
            id: pluginTarefasLogicUid('rule'),
            join: null,
            field_id: conditional.field_id,
            operator: conditional.operator === 'and' ? 'and' : 'or',
            value: conditional.value || '',
            values: pluginTarefasConditionValues(conditional)
        }];
    }
    conditional.logic.items = (conditional.logic.items || []).map((item, index) => {
        const normalized = {...item};
        normalized.join = index === 0 ? null : (normalized.join === 'and' ? 'and' : 'or');
        if (normalized.type === 'group') {
            normalized.items = (normalized.items || []).map((child, childIndex) => ({
                ...child,
                join: childIndex === 0 ? null : (child.join === 'and' ? 'and' : 'or')
            }));
        }
        if (!normalized.id) {
            normalized.id = pluginTarefasLogicUid(normalized.type || 'rule');
        }
        return normalized;
    });
    return conditional;
}

function pluginTarefasLogicHasRules(group) {
    return (group?.items || []).some(item =>
        item.type === 'rule' || (item.type === 'group' && pluginTarefasLogicHasRules(item))
    );
}

function pluginTarefasNormalizeConditionToken(source, value) {
    const token = String(value ?? '').trim();
    if (!token) {
        return '';
    }
    if (source?.type === 'yes_no') {
        const lower = token.toLowerCase();
        if (['yes', 'sim', '1', 'true'].includes(lower)) {
            return 'yes';
        }
        if (['no', 'nao', 'não', '0', 'false'].includes(lower)) {
            return 'no';
        }
    }
    return token;
}

function pluginTarefasConditionTokens(source, value) {
    return pluginTarefasConditionActual(source, value).map(item =>
        pluginTarefasNormalizeConditionToken(source, item)
    ).filter(Boolean);
}

function pluginTarefasEvaluateLogicRule(rule, schemaFields, values) {
    const source = schemaFields.find(item => item.id === rule.field_id);
    if (!source) {
        return true;
    }
    const expected = pluginTarefasConditionValues(rule)
        .map(value => pluginTarefasNormalizeConditionToken(source, value))
        .filter(Boolean);
    if (!expected.length) {
        return true;
    }
    const actual = pluginTarefasConditionTokens(source, values[source.name]);
    if (rule.operator === 'and') {
        return expected.every(value => actual.includes(value));
    }
    return expected.some(value => actual.includes(value));
}

function pluginTarefasEvaluateLogicGroup(group, schemaFields, values) {
    let result = null;
    (group?.items || []).forEach(item => {
        const current = item.type === 'group'
            ? pluginTarefasEvaluateLogicGroup(item, schemaFields, values)
            : pluginTarefasEvaluateLogicRule(item, schemaFields, values);
        if (result === null) {
            result = current;
            return;
        }
        result = item.join === 'and' ? (result && current) : (result || current);
    });
    return result ?? true;
}

function pluginTarefasConditionMatches(field, schemaFields, values) {
    const conditional = pluginTarefasNormalizeConditional(field.conditional || {});
    if (!conditional.enabled) {
        return true;
    }
    if (!pluginTarefasLogicHasRules(conditional.logic)) {
        return true;
    }
    return pluginTarefasEvaluateLogicGroup(conditional.logic, schemaFields, values);
}

function pluginTarefasFindLogicContainer(root, id, parent = null) {
    for (const item of root.items || []) {
        if (item.id === id) {
            return {container: parent || root, item};
        }
        if (item.type === 'group') {
            const found = pluginTarefasFindLogicContainer(item, id, item);
            if (found) {
                return found;
            }
        }
    }
    return null;
}

function pluginTarefasRemapLogicFieldIds(group, idMap) {
    if (!group || !Array.isArray(group.items) || !idMap || !Object.keys(idMap).length) {
        return group;
    }
    return {
        ...group,
        items: group.items.map((item) => {
            if (item.type === 'group') {
                return pluginTarefasRemapLogicFieldIds(item, idMap);
            }
            if (item.type === 'rule' && item.field_id && idMap[item.field_id]) {
                return {...item, field_id: idMap[item.field_id]};
            }
            return item;
        })
    };
}

function pluginTarefasRemapConditionalFieldIds(conditional, idMap) {
    if (!conditional || !idMap || !Object.keys(idMap).length) {
        return conditional;
    }
    const next = {...conditional};
    if (next.field_id && idMap[next.field_id]) {
        next.field_id = idMap[next.field_id];
    }
    if (next.logic) {
        next.logic = pluginTarefasRemapLogicFieldIds(next.logic, idMap);
    }
    return next;
}

function pluginTarefasBuildFieldIdMap(fields) {
    const idMap = {};
    (fields || []).forEach((field, index) => {
        const oldId = String(field?.id || '');
        if (oldId) {
            idMap[oldId] = `field_${index + 1}`;
        }
    });
    return idMap;
}

function pluginTarefasAssignSequentialFieldIds(targetSchema) {
    const fields = targetSchema?.fields || [];
    const idMap = pluginTarefasBuildFieldIdMap(fields);
    fields.forEach((field, index) => {
        field.id = `field_${index + 1}`;
        if (field.conditional) {
            field.conditional = pluginTarefasRemapConditionalFieldIds(field.conditional, idMap);
        }
    });
    return idMap;
}

function pluginTarefasPurgeFieldFromLogic(group, fieldId) {
    const items = [];
    (group.items || []).forEach(item => {
        if (item.type === 'group') {
            const nested = pluginTarefasPurgeFieldFromLogic(item, fieldId);
            if ((nested.items || []).length) {
                items.push({...item, ...nested});
            }
            return;
        }
        if (item.field_id !== fieldId) {
            items.push(item);
        }
    });
    items.forEach((item, index) => {
        item.join = index === 0 ? null : (item.join === 'and' ? 'and' : 'or');
    });
    return {type: 'group', items};
}

function pluginTarefasBoot() {
    'use strict';

    const builder = document.getElementById('plugin-tarefas-builder');
    if (!builder) {
        return;
    }

    const hasDefaultTime = document.getElementById('has_default_time');
    const defaultTimeRow = document.getElementById('plugin-tarefas-default-time');
    const syncDefaultTime = () => {
        if (!hasDefaultTime || !defaultTimeRow) {
            return;
        }
        const enabled = hasDefaultTime.checked;
        defaultTimeRow.classList.toggle('d-none', !enabled);
        defaultTimeRow.querySelectorAll('select').forEach((input) => {
            input.disabled = !enabled;
        });
    };
    hasDefaultTime?.addEventListener('change', syncDefaultTime);
    syncDefaultTime();

    const syncAccessMode = () => {
        const mode = document.getElementById('plugin-tarefas-access-mode')?.value
            || document.querySelector('input[name="access_mode"]:checked')?.value
            || 'all';
        document.getElementById('plugin-tarefas-access-groups')?.classList.toggle('d-none', mode !== 'groups');
        document.getElementById('plugin-tarefas-access-profiles')?.classList.toggle('d-none', mode !== 'profiles');
    };
    document.getElementById('plugin-tarefas-access-mode')?.addEventListener('change', syncAccessMode);
    document.querySelectorAll('input[name="access_mode"]').forEach((input) => {
        input.addEventListener('change', syncAccessMode);
    });
    syncAccessMode();

    const typeLabels = {
        short_text: 'Texto curto',
        long_text: 'Texto longo',
        information: 'Informação',
        integer: 'Número inteiro',
        date: 'Data',
        datetime: 'Data e hora',
        single_choice: 'Seleção simples',
        multiple_choice: 'Seleção múltipla',
        yes_no: 'Sim / Não',
        file: 'Anexo (arquivo)',
        glpi_list: 'Lista do GLPI'
    };
    const glpiTypes = {
        Group: 'Grupos',
        User: 'Usuários',
        Profile: 'Perfis',
        ITILCategory: 'Categorias de chamado',
        Entity: 'Entidades',
        Location: 'Localizações'
    };

    let schema = JSON.parse(document.getElementById('initial-schema').textContent || '{}');
    schema.fields = Array.isArray(schema.fields) ? schema.fields : [];
    let editingIndex = null;
    const fieldModal = modalController('field-modal');
    const previewModal = modalController('preview-modal');

    function destroyModalSelect2(root = document.getElementById('field-modal')) {
        if (!root || !window.jQuery?.fn?.select2) {
            return;
        }
        window.jQuery(root).find('select.select2-hidden-accessible').each(function destroyEach() {
            try {
                window.jQuery(this).select2('destroy');
            } catch (error) {
                // select já removido / inconsistente
            }
        });
        window.jQuery(root).find('.select2-container').remove();
    }

    function clearFieldModalError() {
        document.getElementById('plugin-tarefas-field-modal-error')?.remove();
    }

    /** Erro inline no modal — evita alert() nativo, que trava o Bootstrap Modal. */
    function showFieldModalError(message) {
        const body = document.getElementById('field-modal-body');
        if (!body) {
            return;
        }
        let box = document.getElementById('plugin-tarefas-field-modal-error');
        if (!box) {
            box = document.createElement('div');
            box.id = 'plugin-tarefas-field-modal-error';
            box.className = 'alert alert-danger mb-3';
            box.setAttribute('role', 'alert');
            body.prepend(box);
        }
        box.textContent = message;
        box.scrollIntoView({block: 'nearest', behavior: 'smooth'});
    }

    const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[char]);
    const slug = (value) => String(value || '')
        .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
        .toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '')
        .slice(0, 80) || 'field';
    function nextFieldId() {
        let index = schema.fields.length + 1;
        while (schema.fields.some((field) => field.id === `field_${index}`)) {
            index += 1;
        }
        return `field_${index}`;
    }

    function defaultConfig(type) {
        switch (type) {
            case 'short_text': return {placeholder: '', mask: 'none'};
            case 'long_text': return {placeholder: '', rows: 4};
            case 'information': return {text: ''};
            case 'date': return {range: false};
            case 'single_choice':
            case 'multiple_choice': return {options: []};
            case 'file': return {multiple: false, extensions: '', max_mb: 20};
            case 'glpi_list': return {
                itemtype: 'Group',
                multiple: false,
                value_source: 'none',
                default_value: '',
                editable: true
            };
            default: return {};
        }
    }

    function blankField(type) {
        return {
            id: nextFieldId(),
            name: '',
            type,
            label: '',
            description: '',
            required: false,
            order: schema.fields.length + 1,
            conditional: {
                enabled: false,
                field_id: '',
                operator: 'or',
                value: '',
                values: [],
                logic: pluginTarefasBlankLogicGroup()
            },
            config: defaultConfig(type)
        };
    }

    schema.fields.forEach((field) => {
        if (field.type === 'glpi_list') {
            field.config = {...defaultConfig('glpi_list'), ...(field.config || {})};
        }
    });

    let automations = {enabled: false, items: []};
    const statusesHolder = document.getElementById('ticket-statuses');
    const ticketStatuses = statusesHolder ? JSON.parse(statusesHolder.textContent || '[]') : [];
    const automationsHolder = document.getElementById('initial-automations');
    if (automationsHolder) {
        try { automations = JSON.parse(automationsHolder.textContent || '{}'); }
        catch (e) { automations = {enabled: false, items: []}; }
    }
    automations.items = Array.isArray(automations.items) ? automations.items : [];

    const initialFieldIdMap = pluginTarefasAssignSequentialFieldIds(schema);
    automations.items.forEach((item) => {
        if (item.conditional) {
            item.conditional = pluginTarefasRemapConditionalFieldIds(item.conditional, initialFieldIdMap);
        }
    });

    function remapAutomationsFieldIds(idMap) {
        automations.items.forEach((item) => {
            if (item.conditional) {
                item.conditional = pluginTarefasRemapConditionalFieldIds(item.conditional, idMap);
            }
        });
    }

    function syncSchema() {
        schema.title = document.getElementById('model_name').value.trim();
        const idMap = pluginTarefasAssignSequentialFieldIds(schema);
        remapAutomationsFieldIds(idMap);
        schema.fields.forEach((field, index) => field.order = index + 1);
        document.getElementById('schema_json').value = JSON.stringify(schema);
        syncAutomations();
    }

    let groupItemsCache = null;

    function automationKindLabel(kind) {
        if (kind === 'external') return 'Externa';
        if (kind === 'status') return 'GLPI · Status';
        return 'GLPI · Grupo';
    }

    function sourceFields() {
        return schema.fields.filter(field =>
            ['yes_no', 'single_choice', 'multiple_choice', 'glpi_list', 'integer', 'short_text'].includes(field.type)
        );
    }

    function blankAutomation(kind) {
        return {
            id: 'auto_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6),
            kind,
            webhook_url: '',
            source: 'fixed',
            value: '',
            field_name: '',
            editable: false,
            when: 'always',
            conditional: pluginTarefasNormalizeConditional({enabled: false})
        };
    }

    function automationConditionSources() {
        return schema.fields.filter(field =>
            ['yes_no', 'single_choice', 'multiple_choice', 'glpi_list'].includes(field.type)
        );
    }

    function ensureAutomationLogic(item) {
        item.when = item.when === 'fields' ? 'fields' : 'always';
        item.conditional = pluginTarefasNormalizeConditional(item.conditional || {});
        item.conditional.enabled = item.when === 'fields';
        ensureLogicGroupIds(item.conditional.logic);
        if (!item.conditional.logic.id || item.conditional.logic.id === 'root') {
            item.conditional.logic.id = 'logic_' + item.id;
        }
        return item;
    }

    function syncAutomations() {
        const enabledBox = document.getElementById('automations_enabled');
        const hidden = document.getElementById('automations_json');
        if (!hidden) {
            return;
        }
        readAutomationsFromDom();
        automations.enabled = !!enabledBox?.checked && automations.items.length > 0;
        hidden.value = JSON.stringify(automations);
        const wrap = document.getElementById('plugin-tarefas-automations-wrap');
        wrap?.classList.toggle('d-none', !enabledBox?.checked);
    }

    function optionList(items, selected, placeholder) {
        return `<option value="">${esc(placeholder)}</option>`
            + items.map(item =>
                `<option value="${esc(item.id)}" ${String(selected) === String(item.id) ? 'selected' : ''}>${esc(item.name)}</option>`
            ).join('');
    }

    function automationRowHtml(item, groups) {
        ensureAutomationLogic(item);
        const fields = sourceFields().map(field => ({id: field.name, name: `${field.label} (${typeLabels[field.type]})`}));
        let body = '';
        if (item.kind === 'external') {
            body = `<input class="form-control form-control-sm" type="url" data-auto-url="${esc(item.id)}"
                placeholder="https://..." value="${esc(item.webhook_url || '')}">`;
        } else {
            const source = item.source === 'task_field'
                ? 'task_field'
                : (item.source === 'location_group' ? 'location_group' : 'fixed');
            body = `<select class="form-select form-select-sm" data-auto-source="${esc(item.id)}" style="max-width:14rem">
                    <option value="fixed" ${source === 'fixed' ? 'selected' : ''}>Valor fixo</option>
                    <option value="task_field" ${source === 'task_field' ? 'selected' : ''}>Campo da tarefa</option>
                    ${item.kind === 'group' ? `<option value="location_group" ${source === 'location_group' ? 'selected' : ''}>Grupo da localização do chamado</option>` : ''}
                </select>`;
            if (source === 'task_field') {
                body += `<select class="form-select form-select-sm" data-auto-field="${esc(item.id)}">
                    ${optionList(fields, item.field_name, 'Campo')}
                </select>`;
            } else if (source === 'location_group') {
                body += `<span class="form-hint mb-0">Usa o “Grupo responsável” cadastrado na localização do chamado.</span>`;
            } else if (item.kind === 'status') {
                body += `<select class="form-select form-select-sm" data-auto-value="${esc(item.id)}">
                    ${optionList(ticketStatuses, item.value, 'Status')}
                </select>`;
            } else {
                body += `<select class="form-select form-select-sm" data-auto-value="${esc(item.id)}">
                    ${optionList(groups || [], item.value, groups ? 'Grupo' : 'Carregando...')}
                </select>`;
            }
            if (source === 'fixed') {
                body += `<label class="form-check mb-0">
                    <input class="form-check-input" type="checkbox" data-auto-editable="${esc(item.id)}" ${item.editable ? 'checked' : ''}>
                    <span class="form-check-label">Editável na tarefa</span>
                </label>`;
            }
        }
        const when = item.when === 'fields' ? 'fields' : 'always';
        const whenHint = item.kind === 'external'
            ? '<div class="form-hint mb-0">No Finalizar: dispara se as regras abaixo forem verdadeiras (ou sempre, se você não condicionar).</div>'
            : '';
        return `<div class="plugin-tarefas-auto-item" data-auto-id="${esc(item.id)}">
            <div class="plugin-tarefas-auto-item__top">
                <span class="badge bg-blue-lt">${esc(automationKindLabel(item.kind))}</span>
                <div class="plugin-tarefas-auto-item__body">${body}</div>
                <button type="button" class="btn btn-sm btn-ghost-danger" data-auto-remove="${esc(item.id)}" title="Remover">×</button>
            </div>
            ${whenHint}
            <select class="form-select form-select-sm" data-auto-when="${esc(item.id)}" style="max-width:22rem">
                <option value="always" ${when === 'always' ? 'selected' : ''}>Sempre (ao finalizar / usar esta tarefa)</option>
                <option value="fields" ${when === 'fields' ? 'selected' : ''}>Só se os campos...</option>
            </select>
            <div class="plugin-tarefas-auto-logic${when === 'fields' ? '' : ' d-none'}" data-auto-logic="${esc(item.id)}"></div>
        </div>`;
    }

    function autoLogicRoot(item) {
        return document.querySelector(`[data-auto-logic="${CSS.escape(item.id)}"]`);
    }

    function syncAutoLogicFromDom(item) {
        ensureAutomationLogic(item);
        const root = autoLogicRoot(item);
        if (root && root.querySelector('[data-logic-group]')) {
            syncLogicFromDom(item.conditional.logic, root);
        }
        item.conditional.enabled = item.when === 'fields';
    }

    function mountAutoLogic(item) {
        ensureAutomationLogic(item);
        const root = autoLogicRoot(item);
        if (!root || item.when !== 'fields') {
            return;
        }
        const sources = automationConditionSources();
        root.innerHTML = sources.length
            ? renderLogicGroup(item.conditional.logic, sources)
            : '<div class="text-muted small">Cadastre campos Sim/Não, seleção ou lista do GLPI para montar a regra.</div>';
        const walkLogicRules = (group, callback) => {
            (group.items || []).forEach(child => {
                if (child.type === 'group') {
                    walkLogicRules(child, callback);
                    return;
                }
                callback(child);
            });
        };
        walkLogicRules(item.conditional.logic, (rule) => {
            if (!rule.field_id) {
                return;
            }
            const source = sources.find(entry => entry.id === rule.field_id);
            if (source?.type === 'glpi_list') {
                loadGlpiConditionItemsForRule(source, rule, root);
            }
        });
    }

    function mountAllAutoLogics() {
        automations.items.forEach(item => {
            if (item.when === 'fields') {
                mountAutoLogic(item);
            }
        });
    }

    function autoRow(id) {
        return document.querySelector(`[data-auto-id="${CSS.escape(id)}"]`);
    }

    function readDomValue(row, selector) {
        return row?.querySelector(selector) ?? null;
    }

    function readFixedSelectValue(row, item) {
        const el = readDomValue(row, `[data-auto-value="${CSS.escape(item.id)}"]`);
        if (!el) {
            return;
        }
        const next = String(el.value || '').trim();
        if (next !== '') {
            item.value = next;
            return;
        }
        const hasChoices = Array.from(el.options || []).some(option => option.value !== '');
        if (hasChoices) {
            item.value = '';
        }
    }

    function readAutomationsFromDom() {
        automations.items.forEach(item => {
            const row = autoRow(item.id);
            const url = readDomValue(row, `[data-auto-url="${CSS.escape(item.id)}"]`);
            if (url) {
                item.webhook_url = url.value.trim();
            }
            const source = readDomValue(row, `[data-auto-source="${CSS.escape(item.id)}"]`);
            if (source) {
                if (source.value === 'task_field') {
                    item.source = 'task_field';
                } else if (source.value === 'location_group' && item.kind === 'group') {
                    item.source = 'location_group';
                } else {
                    item.source = 'fixed';
                }
            }
            const field = readDomValue(row, `[data-auto-field="${CSS.escape(item.id)}"]`);
            if (field) {
                item.field_name = field.value;
            }
            readFixedSelectValue(row, item);
            const editable = readDomValue(row, `[data-auto-editable="${CSS.escape(item.id)}"]`);
            item.editable = !!editable?.checked;
            const when = readDomValue(row, `[data-auto-when="${CSS.escape(item.id)}"]`);
            if (when) {
                item.when = when.value === 'fields' ? 'fields' : 'always';
            }
            syncAutoLogicFromDom(item);
        });
    }

    function renderAutomations(groups) {
        const list = document.getElementById('plugin-tarefas-automations-list');
        if (!list) {
            return;
        }
        list.innerHTML = automations.items.length
            ? automations.items.map(item => automationRowHtml(item, groups)).join('')
            : '<div class="text-muted small">Nenhuma automação. Escolha externa ou interna e clique em Adicionar.</div>';
        mountAllAutoLogics();
        syncAutomations();
    }

    function loadGroupsThenRender() {
        const needsGroups = automations.items.some(item =>
            item.kind === 'group' && item.source !== 'task_field' && item.source !== 'location_group'
        );
        if (!needsGroups) {
            renderAutomations([]);
            return;
        }
        if (groupItemsCache) {
            renderAutomations(groupItemsCache);
            return;
        }
        const url = document.getElementById('plugin-tarefas-builder')?.dataset.conditionItemsUrl;
        if (!url) {
            renderAutomations([]);
            return;
        }
        renderAutomations([]);
        fetch(`${url}?itemtype=Group`, {credentials: 'same-origin'})
            .then(response => response.json())
            .then(data => {
                groupItemsCache = Array.isArray(data.items) ? data.items : [];
                renderAutomations(groupItemsCache);
            })
            .catch(() => {
                renderAutomations([]);
            });
    }

    function bindAutomations() {
        const enabledBox = document.getElementById('automations_enabled');
        if (!enabledBox) {
            return;
        }
        enabledBox.addEventListener('change', () => {
            syncAutomations();
            if (enabledBox.checked) {
                loadGroupsThenRender();
            }
        });
        const scopeSelect = document.getElementById('plugin-tarefas-auto-scope');
        const internalSelect = document.getElementById('plugin-tarefas-auto-internal');
        const addButton = document.getElementById('plugin-tarefas-add-automation');
        const syncPicker = () => {
            const internal = scopeSelect?.value === 'internal';
            internalSelect?.classList.toggle('d-none', !internal);
            if (!internal && internalSelect) {
                internalSelect.value = '';
            }
        };
        scopeSelect?.addEventListener('change', syncPicker);
        addButton?.addEventListener('click', () => {
            const scope = scopeSelect?.value || '';
            const kind = scope === 'external'
                ? 'external'
                : (internalSelect?.value || '');
            if (scope === '') {
                alert('Selecione Automação externa ou Automação interna.');
                return;
            }
            if (scope === 'internal' && !['status', 'group'].includes(kind)) {
                alert('Na automação interna, escolha Status ou Grupo.');
                return;
            }
            enabledBox.checked = true;
            readAutomationsFromDom();
            automations.items.push(blankAutomation(kind));
            loadGroupsThenRender();
        });
        syncPicker();
        document.getElementById('plugin-tarefas-automations-list')?.addEventListener('click', event => {
            const remove = event.target.closest('[data-auto-remove]');
            if (remove) {
                readAutomationsFromDom();
                automations.items = automations.items.filter(item => item.id !== remove.dataset.autoRemove);
                loadGroupsThenRender();
                return;
            }

            const autoItem = event.target.closest('[data-auto-id]');
            const item = automations.items.find(entry => entry.id === autoItem?.dataset.autoId);
            if (!item || item.when !== 'fields') {
                return;
            }

            const joinBtn = event.target.closest('[data-join]');
            const joinWrap = joinBtn?.closest('[data-logic-join]');
            if (joinWrap && joinBtn) {
                const join = joinBtn.dataset.join === 'and' ? 'and' : 'or';
                joinWrap.dataset.joinValue = join;
                joinWrap.querySelectorAll('[data-join]').forEach((button) => {
                    const active = button.dataset.join === join;
                    button.classList.toggle('btn-primary', active);
                    button.classList.toggle('btn-outline-secondary', !active);
                });
                const found = pluginTarefasFindLogicContainer(item.conditional.logic, joinWrap.dataset.logicJoin);
                if (found) {
                    found.item.join = join;
                }
                event.preventDefault();
                return;
            }

            const addRuleBtn = event.target.closest('.plugin-tarefas-logic-add-rule');
            const addGroupBtn = event.target.closest('.plugin-tarefas-logic-add-group');
            const removeLogicBtn = event.target.closest('.plugin-tarefas-logic-remove');
            if (!addRuleBtn && !addGroupBtn && !removeLogicBtn) {
                return;
            }
            syncAutoLogicFromDom(item);

            if (addRuleBtn) {
                const group = findLogicGroupById(item.conditional.logic, addRuleBtn.dataset.logicGroup || item.conditional.logic.id);
                if (!group) {
                    return;
                }
                const rule = pluginTarefasBlankLogicRule();
                if (group.items.length) {
                    rule.join = 'or';
                }
                group.items.push(rule);
                mountAutoLogic(item);
                return;
            }
            if (addGroupBtn) {
                const group = findLogicGroupById(item.conditional.logic, addGroupBtn.dataset.logicGroup || item.conditional.logic.id);
                if (!group) {
                    return;
                }
                const nested = pluginTarefasBlankLogicNestedGroup();
                if (group.items.length) {
                    nested.join = 'or';
                }
                group.items.push(nested);
                mountAutoLogic(item);
                return;
            }
            const target = pluginTarefasFindLogicContainer(item.conditional.logic, removeLogicBtn.dataset.logicRemove);
            if (!target) {
                return;
            }
            target.container.items = target.container.items.filter(entry => entry.id !== target.item.id);
            target.container.items.forEach((entry, index) => {
                entry.join = index === 0 ? null : (entry.join === 'and' ? 'and' : 'or');
            });
            mountAutoLogic(item);
        });
        document.getElementById('plugin-tarefas-automations-list')?.addEventListener('change', event => {
            const whenSelect = event.target.closest('[data-auto-when]');
            if (whenSelect) {
                readAutomationsFromDom();
                const item = automations.items.find(entry => entry.id === whenSelect.dataset.autoWhen);
                const box = item ? autoLogicRoot(item) : null;
                box?.classList.toggle('d-none', item.when !== 'fields');
                if (item?.when === 'fields') {
                    mountAutoLogic(item);
                }
                syncAutomations();
                return;
            }
            const fieldSelect = event.target.closest('[data-logic-field]');
            if (fieldSelect) {
                const autoId = fieldSelect.closest('[data-auto-id]')?.dataset.autoId;
                const item = automations.items.find(entry => entry.id === autoId);
                if (!item) {
                    return;
                }
                syncAutoLogicFromDom(item);
                const found = pluginTarefasFindLogicContainer(item.conditional.logic, fieldSelect.dataset.logicField);
                if (found && found.item.type === 'rule') {
                    found.item.field_id = fieldSelect.value;
                    found.item.values = [];
                    found.item.value = '';
                    found.item.operator = 'or';
                }
                mountAutoLogic(item);
                syncAutomations();
                return;
            }
            const operatorSelect = event.target.closest('[data-logic-rule-operator]');
            if (operatorSelect) {
                const autoId = operatorSelect.closest('[data-auto-id]')?.dataset.autoId;
                const item = automations.items.find(entry => entry.id === autoId);
                if (!item) {
                    return;
                }
                const root = autoLogicRoot(item);
                syncAutoLogicFromDom(item);
                const found = pluginTarefasFindLogicContainer(
                    item.conditional.logic,
                    operatorSelect.dataset.logicRuleOperator
                );
                if (found && found.item.type === 'rule') {
                    const source = schema.fields.find(field => field.id === found.item.field_id);
                    if (operatorSelect.value === 'and') {
                        applyTodasOperatorSelection(found.item, source, root || document);
                    } else {
                        found.item.operator = 'or';
                    }
                }
                syncAutomations();
                return;
            }
            if (!event.target.closest('[data-auto-source]')) {
                readAutomationsFromDom();
                syncAutomations();
                return;
            }
            readAutomationsFromDom();
            loadGroupsThenRender();
        });
        loadGroupsThenRender();
    }

    bindAutomations();

    function formatLogicRuleText(rule) {
        const source = schema.fields.find(item => item.id === rule.field_id);
        if (!source) {
            return '';
        }
        const selected = pluginTarefasConditionValues(rule);
        const joiner = rule.operator === 'and' ? ' e ' : ' ou ';
        const values = selected.map(value => displayOption(source, value)).join(joiner);
        return `"${source.label}" = ${values}`;
    }

    function formatLogicGroupText(group, wrap = false) {
        const parts = [];
        (group.items || []).forEach((item, index) => {
            let text = '';
            if (item.type === 'group') {
                const inner = formatLogicGroupText(item, true);
                if (inner) {
                    text = inner;
                }
            } else {
                text = formatLogicRuleText(item);
            }
            if (!text) {
                return;
            }
            if (index > 0) {
                parts.push(item.join === 'and' ? ' e ' : ' ou ');
            }
            parts.push(text);
        });
        const rendered = parts.join('');
        if (!rendered) {
            return '';
        }
        return wrap ? `(${rendered})` : rendered;
    }

    function conditionalText(field) {
        const conditional = pluginTarefasNormalizeConditional(field.conditional || {});
        if (!conditional.enabled || !pluginTarefasLogicHasRules(conditional.logic)) {
            return '';
        }
        const summary = formatLogicGroupText(conditional.logic);
        return summary ? ` · aparece se ${summary}` : '';
    }

    function displayOption(source, value) {
        if (source?.type === 'yes_no') return value === 'yes' ? 'Sim' : 'Não';
        if (source?.type === 'glpi_list') return `#${value}`;
        return value;
    }

    function renderList() {
        const list = document.getElementById('field-list');
        if (!schema.fields.length) {
            list.innerHTML = '<div class="text-center text-muted py-5">Nenhum campo. Clique em <strong>Adicionar campo</strong>.</div>';
            syncSchema();
            return;
        }
        list.innerHTML = schema.fields.map((field, index) => `
            <div class="plugin-tarefas-field">
                <span class="plugin-tarefas-field__order">${index + 1}</span>
                <div class="plugin-tarefas-field__main">
                    <strong>${esc(field.label)}${field.required ? ' <span class="text-danger">*</span>' : ''}</strong>
                    <div class="text-muted small">
                        ${esc(typeLabels[field.type])} · alias: <code>${esc(field.name)}</code>${esc(conditionalText(field))}
                    </div>
                </div>
                <div class="btn-list flex-nowrap">
                    <button type="button" class="btn btn-sm btn-outline-secondary move-up" data-index="${index}" ${index === 0 ? 'disabled' : ''}>↑</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary move-down" data-index="${index}" ${index === schema.fields.length - 1 ? 'disabled' : ''}>↓</button>
                    <button type="button" class="btn btn-sm btn-outline-primary edit-field" data-index="${index}">Editar</button>
                    <button type="button" class="btn btn-sm btn-outline-danger remove-field" data-index="${index}">Remover</button>
                </div>
            </div>`).join('');
        syncSchema();
        mountAllAutoLogics();
    }

    function eligibleSources(currentId) {
        const currentIndex = schema.fields.findIndex(field => field.id === currentId);
        return schema.fields.filter((field, index) =>
            field.id !== currentId
            && (currentIndex < 0 || index < currentIndex)
            && ['yes_no', 'single_choice', 'multiple_choice', 'glpi_list'].includes(field.type)
        );
    }

    function isMultipleConditionSource(source) {
        return source?.type === 'multiple_choice'
            || (source?.type === 'glpi_list' && !!source.config?.multiple);
    }

    /** Valores possíveis do campo de origem (DOM já carregado ou opções do schema). */
    function allSourceConditionValues(source, rule, root = document) {
        if (!source) {
            return [];
        }
        if (source.type === 'multiple_choice') {
            return (source.config?.options || []).map(String).filter(Boolean);
        }
        if (source.type === 'glpi_list') {
            const holder = root.querySelector(`[data-logic-values="${CSS.escape(rule.id)}"]`);
            if (!holder || holder.tagName !== 'SELECT') {
                return [];
            }
            return Array.from(holder.options).map(option => option.value).filter(Boolean);
        }
        return [];
    }

    /** Marca todas as opções da regra (checkboxes ou multi-select). */
    function selectAllLogicRuleValues(ruleId, source, root = document) {
        const holder = root.querySelector(`[data-logic-values="${CSS.escape(ruleId)}"]`);
        if (!holder || !source) {
            return;
        }
        if (source.type === 'multiple_choice') {
            holder.querySelectorAll('input[type="checkbox"]').forEach((input) => {
                input.checked = true;
            });
            return;
        }
        if (source.type === 'glpi_list' && holder.tagName === 'SELECT') {
            Array.from(holder.options).forEach((option) => {
                option.selected = !!option.value;
            });
            if (window.jQuery?.fn?.select2 && window.jQuery(holder).hasClass('select2-hidden-accessible')) {
                window.jQuery(holder).trigger('change');
            }
        }
    }

    function applyTodasOperatorSelection(rule, source, root = document) {
        if (!rule || !isMultipleConditionSource(source)) {
            return;
        }
        rule.operator = 'and';
        selectAllLogicRuleValues(rule.id, source, root);
        const values = readRuleValuesFromDom(rule, source, root);
        rule.values = values.length ? values : allSourceConditionValues(source, rule, root);
        rule.value = rule.values[0] || '';
    }

    function conditionOptions(source, selected = []) {
        if (!source) return '<option value="">Selecione o campo de origem</option>';
        const selectedValues = Array.isArray(selected) ? selected.map(String) : [String(selected)];
        const options = source.type === 'yes_no'
            ? [['yes', 'Sim'], ['no', 'Não']]
            : (source.config.options || []).map(value => [value, value]);
        return '<option value="">-----</option>' + options.map(([value, label]) =>
            `<option value="${esc(value)}" ${selectedValues.includes(String(value)) ? 'selected' : ''}>${esc(label)}</option>`
        ).join('');
    }

    function conditionValueControl(source, selected = []) {
        if (!source) {
            return `<select class="form-select" id="f_cond_value">${conditionOptions(null)}</select>`;
        }
        if (source.type === 'yes_no') {
            return `<select class="form-select" id="f_cond_value">${conditionOptions(source, selected)}</select>`;
        }
        if (source.type === 'glpi_list') {
            return `<select class="form-select" id="f_cond_values" multiple>
                <option value="">Carregando...</option>
            </select>`;
        }

        const selectedValues = Array.isArray(selected) ? selected.map(String) : [String(selected)];
        return `<div class="plugin-tarefas-options" id="f_cond_values">
            ${(source.config.options || []).map(option => `
                <label class="form-check">
                    <input class="form-check-input" type="checkbox" value="${esc(option)}"
                        ${selectedValues.includes(String(option)) ? 'checked' : ''}>
                    <span class="form-check-label">${esc(option)}</span>
                </label>`).join('')}
        </div>`;
    }

    function ruleValueControl(source, rule, ruleId) {
        const selected = pluginTarefasConditionValues(rule);
        if (!source) {
            return `<select class="form-select form-select-sm" data-logic-values="${esc(ruleId)}" disabled>
                <option value="">Selecione o campo</option></select>`;
        }
        if (source.type === 'yes_no' || source.type === 'single_choice') {
            return `<select class="form-select form-select-sm" data-logic-values="${esc(ruleId)}">
                ${conditionOptions(source, selected)}</select>`;
        }
        if (source.type === 'glpi_list') {
            return `<select class="form-select form-select-sm" data-logic-values="${esc(ruleId)}" multiple>
                <option value="">Carregando...</option></select>`;
        }
        const selectedValues = selected.map(String);
        return `<div class="plugin-tarefas-options plugin-tarefas-options--compact" data-logic-values="${esc(ruleId)}">
            ${(source.config.options || []).map(option => `
                <label class="form-check form-check-inline m-0">
                    <input class="form-check-input" type="checkbox" value="${esc(option)}"
                        ${selectedValues.includes(String(option)) ? 'checked' : ''}>
                    <span class="form-check-label">${esc(option)}</span>
                </label>`).join('')}
        </div>`;
    }

    function ruleOperatorControl(source, rule, ruleId) {
        const multiple = isMultipleConditionSource(source);
        const operator = rule.operator === 'and' && multiple ? 'and' : 'or';
        if (!multiple) {
            return '';
        }
        return `<select class="form-select form-select-sm plugin-tarefas-logic-rule-operator" data-logic-rule-operator="${esc(ruleId)}">
            <option value="or" ${operator === 'or' ? 'selected' : ''}>qualquer</option>
            <option value="and" ${operator === 'and' ? 'selected' : ''}>todas</option>
        </select>`;
    }

    function renderJoinControl(item, isFirst) {
        if (isFirst) {
            return '<span class="plugin-tarefas-logic-join plugin-tarefas-logic-join--base" title="A primeira regra do grupo não usa E/OU">-----</span>';
        }
        const join = item.join === 'and' ? 'and' : 'or';
        return `<div class="plugin-tarefas-logic-join-btns" data-logic-join="${esc(item.id)}" data-join-value="${join}">
            <button type="button" class="btn btn-sm ${join === 'and' ? 'btn-primary' : 'btn-outline-secondary'}" data-join="and">E</button>
            <button type="button" class="btn btn-sm ${join === 'or' ? 'btn-primary' : 'btn-outline-secondary'}" data-join="or">OU</button>
        </div>`;
    }

    function renderLogicItem(item, sources, isFirst) {
        const joinHtml = renderJoinControl(item, isFirst);

        if (item.type === 'group') {
            return `<div class="plugin-tarefas-logic-item plugin-tarefas-logic-item--group" data-logic-id="${esc(item.id)}">
                ${joinHtml}
                <div class="plugin-tarefas-logic-group-box">
                    ${renderLogicGroup(item, sources)}
                    <button type="button" class="btn btn-sm btn-ghost-danger plugin-tarefas-logic-remove" data-logic-remove="${esc(item.id)}" title="Remover grupo">×</button>
                </div>
            </div>`;
        }

        const source = sources.find(entry => entry.id === item.field_id);
        const fieldOptions = [['', '-----'], ...sources.map(entry => [entry.id, `${entry.label} (${typeLabels[entry.type]})`])];
        return `<div class="plugin-tarefas-logic-item plugin-tarefas-logic-item--rule" data-logic-id="${esc(item.id)}">
            ${joinHtml}
            <select class="form-select form-select-sm plugin-tarefas-logic-field" data-logic-field="${esc(item.id)}">
                ${fieldOptions.map(([value, label]) =>
                    `<option value="${esc(value)}" ${String(item.field_id) === String(value) ? 'selected' : ''}>${esc(label)}</option>`
                ).join('')}
            </select>
            ${ruleOperatorControl(source, item, item.id)}
            <div class="plugin-tarefas-logic-values">${ruleValueControl(source, item, item.id)}</div>
            <button type="button" class="btn btn-sm btn-ghost-danger plugin-tarefas-logic-remove" data-logic-remove="${esc(item.id)}" title="Remover regra">×</button>
        </div>`;
    }

    function renderLogicGroup(group, sources) {
        const items = group.items || [];
        return `<div class="plugin-tarefas-logic-group" data-logic-group="${esc(group.id || 'root')}">
            ${items.length
                ? items.map((item, index) => renderLogicItem(item, sources, index === 0)).join('')
                : '<div class="text-muted small py-2">Nenhuma regra. Adicione uma regra ou um grupo.</div>'}
            <div class="plugin-tarefas-logic-actions">
                <button type="button" class="btn btn-sm btn-ghost-secondary plugin-tarefas-logic-add-rule" data-logic-group="${esc(group.id || 'root')}">+ regra</button>
                <button type="button" class="btn btn-sm btn-ghost-secondary plugin-tarefas-logic-add-group" data-logic-group="${esc(group.id || 'root')}">+ grupo</button>
            </div>
        </div>`;
    }

    function ensureLogicGroupIds(group, isRoot = true) {
        if (isRoot) {
            group.id = group.id || 'root';
        } else if (!group.id) {
            group.id = pluginTarefasLogicUid('group');
        }
        (group.items || []).forEach(item => {
            if (!item.id) {
                item.id = pluginTarefasLogicUid(item.type || 'rule');
            }
            if (item.type === 'group') {
                ensureLogicGroupIds(item, false);
            }
        });
        return group;
    }

    function readRuleValuesFromDom(rule, source, root = document) {
        if (!source) {
            return [];
        }
        const holder = root.querySelector(`[data-logic-values="${CSS.escape(rule.id)}"]`);
        if (!holder) {
            return pluginTarefasConditionValues(rule);
        }
        if (source.type === 'yes_no' || source.type === 'single_choice') {
            return holder.value ? [holder.value] : [];
        }
        if (source.type === 'glpi_list') {
            return Array.from(holder.selectedOptions).map(option => option.value).filter(Boolean);
        }
        return Array.from(holder.querySelectorAll('input:checked')).map(input => input.value);
    }

    function syncLogicFromDom(logic, root = document) {
        (logic.items || []).forEach((item, index) => {
            item.join = index === 0
                ? null
                : (root.querySelector(`[data-logic-join="${CSS.escape(item.id)}"]`)?.dataset.joinValue === 'and' ? 'and' : 'or');
            if (item.type === 'group') {
                syncLogicFromDom(item, root);
                return;
            }
            item.field_id = root.querySelector(`[data-logic-field="${CSS.escape(item.id)}"]`)?.value || '';
            const source = schema.fields.find(field => field.id === item.field_id);
            item.operator = root.querySelector(`[data-logic-rule-operator="${CSS.escape(item.id)}"]`)?.value === 'and'
                && isMultipleConditionSource(source) ? 'and' : 'or';
            item.values = readRuleValuesFromDom(item, source, root);
            // Operador "todas" sem caixas marcadas: preenche todas as opções do campo.
            if (item.operator === 'and' && isMultipleConditionSource(source) && !item.values.length) {
                selectAllLogicRuleValues(item.id, source, root);
                item.values = readRuleValuesFromDom(item, source, root);
                if (!item.values.length) {
                    item.values = allSourceConditionValues(source, item, root);
                }
            }
            item.value = item.values[0] || '';
        });
        return logic;
    }

    function mountConditionalBuilder(field, sources) {
        const conditional = pluginTarefasNormalizeConditional(field.conditional || {});
        field.conditional = conditional;
        ensureLogicGroupIds(conditional.logic);
        window.pluginTarefasEditingLogic = conditional.logic;

        const builder = document.getElementById('conditional-builder');
        if (!builder) {
            return;
        }
        builder.innerHTML = renderLogicGroup(conditional.logic, sources);

        const walkLogicRules = (group, callback) => {
            (group.items || []).forEach(item => {
                if (item.type === 'group') {
                    walkLogicRules(item, callback);
                    return;
                }
                callback(item);
            });
        };
        walkLogicRules(conditional.logic, (rule) => {
            if (!rule.field_id) {
                return;
            }
            const source = sources.find(entry => entry.id === rule.field_id);
            if (source?.type === 'glpi_list') {
                loadGlpiConditionItemsForRule(source, rule, builder);
            }
        });
    }

    function loadGlpiConditionItemsForRule(source, rule, root = document) {
        const holder = root.querySelector(`[data-logic-values="${CSS.escape(rule.id)}"]`);
        if (!holder || source?.type !== 'glpi_list') {
            return;
        }
        const url = conditionItemsUrl();
        const itemtype = source.config?.itemtype || 'Group';
        if (!url) {
            holder.innerHTML = '<option value="">Não foi possível carregar os itens</option>';
            return;
        }
        const selectedValues = pluginTarefasConditionValues(rule).map(String).filter(Boolean);
        const isUser = itemtype === 'User';
        const preferAll = rule.operator === 'and'
            && isMultipleConditionSource(source)
            && !selectedValues.length
            && !isUser;

        const mountSelect2 = (preloaded, forceValues = null) => {
            holder.innerHTML = '';
            (preloaded || []).forEach((item) => {
                holder.appendChild(new Option(item.name, String(item.id), true, true));
            });
            if (!window.jQuery?.fn?.select2) {
                return;
            }
            const $holder = window.jQuery(holder);
            if ($holder.hasClass('select2-hidden-accessible')) {
                try {
                    $holder.select2('destroy');
                } catch (error) {
                    // ignore
                }
            }
            const modal = holder.closest('.modal-content');
            const dropdownParent = modal
                ? window.jQuery(modal)
                : window.jQuery(holder.closest('.plugin-tarefas-auto-item') || document.body);
            $holder.select2({
                width: '100%',
                dropdownParent,
                placeholder: isUser ? 'Digite para localizar usuários...' : 'Selecione um ou mais itens',
                closeOnSelect: false,
                allowClear: true,
                minimumInputLength: isUser ? 2 : 0,
                ajax: {
                    url,
                    dataType: 'json',
                    delay: 250,
                    data: (params) => ({
                        itemtype,
                        term: params.term || '',
                        selected: selectedValues[0] || ''
                    }),
                    processResults: (data) => ({
                        results: (Array.isArray(data.items) ? data.items : []).map((item) => ({
                            id: String(item.id),
                            text: item.name
                        }))
                    }),
                    cache: true
                }
            });
            const values = forceValues || selectedValues;
            if (values.length) {
                $holder.val(holder.multiple ? values : values[0]).trigger('change');
            }
        };

        if (preferAll) {
            fetch(`${url}?itemtype=${encodeURIComponent(itemtype)}`, {credentials: 'same-origin'})
                .then((response) => response.json())
                .then((data) => {
                    const items = Array.isArray(data.items) ? data.items : [];
                    const allIds = items.map((item) => String(item.id));
                    if (allIds.length) {
                        rule.values = allIds;
                        rule.value = allIds[0] || '';
                    }
                    mountSelect2(items, allIds);
                })
                .catch(() => {
                    holder.innerHTML = '<option value="">Falha ao consultar o cadastro do GLPI</option>';
                });
            return;
        }

        Promise.all(selectedValues.map((id) =>
            fetchGlpiItemLabel(itemtype, id).then((name) => (name ? {id, name} : {id, name: id}))
        ))
            .then((preloaded) => mountSelect2(preloaded))
            .catch(() => mountSelect2([]));
    }

    function findLogicGroupById(root, groupId) {
        if ((root.id || 'root') === groupId) {
            return root;
        }
        for (const item of root.items || []) {
            if (item.type === 'group') {
                const found = findLogicGroupById(item, groupId);
                if (found) {
                    return found;
                }
            }
        }
        return null;
    }

    function bindConditionalBuilder(field, sources) {
        const builder = document.getElementById('conditional-builder');
        if (!builder) {
            return;
        }

        builder.addEventListener('click', (event) => {
            const joinBtn = event.target.closest('[data-join]');
            const joinWrap = joinBtn?.closest('[data-logic-join]');
            if (joinWrap && joinBtn) {
                const join = joinBtn.dataset.join === 'and' ? 'and' : 'or';
                joinWrap.dataset.joinValue = join;
                joinWrap.querySelectorAll('[data-join]').forEach((button) => {
                    const active = button.dataset.join === join;
                    button.classList.toggle('btn-primary', active);
                    button.classList.toggle('btn-outline-secondary', !active);
                });
                const found = pluginTarefasFindLogicContainer(
                    window.pluginTarefasEditingLogic,
                    joinWrap.dataset.logicJoin
                );
                if (found) {
                    found.item.join = join;
                }
                event.preventDefault();
                return;
            }

            const addRuleBtn = event.target.closest('.plugin-tarefas-logic-add-rule');
            const addGroupBtn = event.target.closest('.plugin-tarefas-logic-add-group');
            const removeBtn = event.target.closest('.plugin-tarefas-logic-remove');
            const logic = window.pluginTarefasEditingLogic;
            if (!logic) {
                return;
            }

            if (addRuleBtn) {
                syncLogicFromDom(logic, builder);
                const group = findLogicGroupById(logic, addRuleBtn.dataset.logicGroup || 'root');
                if (!group) {
                    return;
                }
                const rule = pluginTarefasBlankLogicRule();
                if (group.items.length) {
                    rule.join = 'or';
                }
                group.items.push(rule);
                mountConditionalBuilder(field, sources);
                return;
            }

            if (addGroupBtn) {
                syncLogicFromDom(logic, builder);
                const group = findLogicGroupById(logic, addGroupBtn.dataset.logicGroup || 'root');
                if (!group) {
                    return;
                }
                const nested = pluginTarefasBlankLogicNestedGroup();
                if (group.items.length) {
                    nested.join = 'or';
                }
                group.items.push(nested);
                mountConditionalBuilder(field, sources);
                return;
            }

            if (removeBtn) {
                syncLogicFromDom(logic, builder);
                const target = pluginTarefasFindLogicContainer(logic, removeBtn.dataset.logicRemove);
                if (!target) {
                    return;
                }
                target.container.items = target.container.items.filter(item => item.id !== target.item.id);
                target.container.items.forEach((item, index) => {
                    item.join = index === 0 ? null : (item.join === 'and' ? 'and' : 'or');
                });
                mountConditionalBuilder(field, sources);
            }
        });

        builder.addEventListener('change', (event) => {
            const operatorSelect = event.target.closest('[data-logic-rule-operator]');
            const logic = window.pluginTarefasEditingLogic;
            if (operatorSelect && logic) {
                syncLogicFromDom(logic, builder);
                const found = pluginTarefasFindLogicContainer(logic, operatorSelect.dataset.logicRuleOperator);
                if (!found || found.item.type !== 'rule') {
                    return;
                }
                const source = schema.fields.find(field => field.id === found.item.field_id);
                if (operatorSelect.value === 'and') {
                    applyTodasOperatorSelection(found.item, source, builder);
                } else {
                    found.item.operator = 'or';
                }
                return;
            }

            const fieldSelect = event.target.closest('[data-logic-field]');
            if (!logic || !fieldSelect) {
                return;
            }
            syncLogicFromDom(logic, builder);
            const found = pluginTarefasFindLogicContainer(logic, fieldSelect.dataset.logicField);
            if (!found || found.item.type !== 'rule') {
                return;
            }
            found.item.field_id = fieldSelect.value;
            found.item.values = [];
            found.item.value = '';
            found.item.operator = 'or';
            mountConditionalBuilder(field, sources);
        });
    }

    function validateConditionalLogic(logic) {
        let valid = false;
        (logic.items || []).forEach(item => {
            if (item.type === 'group') {
                if (validateConditionalLogic(item)) {
                    valid = true;
                }
                return;
            }
            if (item.field_id && pluginTarefasConditionValues(item).length) {
                valid = true;
            }
        });
        return valid;
    }

    function loadGlpiConditionItems(source, selected = []) {
        const holder = document.getElementById('f_cond_values');
        if (!holder || source?.type !== 'glpi_list') {
            return;
        }
        const url = document.getElementById('plugin-tarefas-builder')?.dataset.conditionItemsUrl;
        if (!url) {
            holder.innerHTML = '<option value="">Não foi possível carregar os itens</option>';
            return;
        }
        const selectedValues = Array.isArray(selected) ? selected.map(String) : [String(selected)].filter(Boolean);
        fetch(`${url}?itemtype=${encodeURIComponent(source.config?.itemtype || 'Group')}`, {credentials: 'same-origin'})
            .then((response) => response.json())
            .then((data) => {
                const items = Array.isArray(data.items) ? data.items : [];
                if (!items.length) {
                    holder.innerHTML = '<option value="">Nenhum item encontrado</option>';
                    return;
                }
                holder.innerHTML = items.map((item) =>
                    `<option value="${esc(String(item.id))}" ${selectedValues.includes(String(item.id)) ? 'selected' : ''}>${esc(item.name)}</option>`
                ).join('');
                if (window.jQuery?.fn?.select2) {
                    const $holder = window.jQuery(holder);
                    if ($holder.hasClass('select2-hidden-accessible')) {
                        $holder.select2('destroy');
                    }
                    $holder.select2({
                        width: '100%',
                        dropdownParent: window.jQuery('#field-modal .modal-content'),
                        placeholder: 'Selecione um ou mais itens',
                        closeOnSelect: false
                    });
                }
            })
            .catch(() => {
                holder.innerHTML = '<option value="">Falha ao consultar o cadastro do GLPI</option>';
            });
    }

    const FIELD_DESC_MAX = 80;

    function descriptionRow(value = '') {
        const length = (value || '').length;
        return `<div class="mb-3">
            <label class="form-label" for="f_description">Descrição / ajuda</label>
            <textarea class="form-control" id="f_description" rows="2" maxlength="${FIELD_DESC_MAX}">${esc(value || '')}</textarea>
            <div class="form-hint d-flex justify-content-between gap-2">
                <span>Texto curto abaixo do campo no preenchimento (máx. ${FIELD_DESC_MAX} caracteres).</span>
                <span id="f_description_count">${length}/${FIELD_DESC_MAX}</span>
            </div>
        </div>`;
    }

    function bindDescriptionCounter() {
        const input = document.getElementById('f_description');
        const counter = document.getElementById('f_description_count');
        if (!input || !counter) {
            return;
        }
        const sync = () => {
            counter.textContent = `${input.value.length}/${FIELD_DESC_MAX}`;
        };
        input.addEventListener('input', sync);
        sync();
    }

    const glpiItemsCacheByType = {};

    function fetchGlpiListItems(itemtype) {
        const key = itemtype || 'Group';
        if (glpiItemsCacheByType[key]) {
            return Promise.resolve(glpiItemsCacheByType[key]);
        }
        const url = document.getElementById('plugin-tarefas-builder')?.dataset.conditionItemsUrl;
        if (!url) {
            return Promise.resolve([]);
        }
        return fetch(`${url}?itemtype=${encodeURIComponent(key)}`, {credentials: 'same-origin'})
            .then((response) => response.json())
            .then((data) => {
                const items = Array.isArray(data.items) ? data.items : [];
                glpiItemsCacheByType[key] = items;
                return items;
            })
            .catch(() => []);
    }

    function syncGlpiListConfigPanels() {
        const source = document.getElementById('f_value_source')?.value || 'none';
        document.getElementById('f_glpi_fixed_wrap')?.classList.toggle('d-none', source !== 'fixed');
        document.getElementById('f_glpi_preset_wrap')?.classList.toggle('d-none', source === 'none');
        const hint = document.getElementById('f_glpi_preset_hint');
        if (!hint) {
            return;
        }
        if (source === 'location_group') {
            hint.textContent = 'Usa o “Grupo responsável” da localização do chamado. Sem vínculo, o campo fica vazio.';
        } else if (source === 'fixed') {
            hint.textContent = 'Desmarque “Editável na tarefa” para impedir alteração pelo analista.';
        } else {
            hint.textContent = '';
        }
    }

    function syncGlpiListValueSourceOptions(isGroup) {
        const select = document.getElementById('f_value_source');
        if (!select) {
            return;
        }
        const current = select.value;
        const options = [
            ['none', 'Nenhum (analista escolhe)'],
            ['fixed', 'Valor fixo'],
        ];
        if (isGroup) {
            options.push(['location_group', 'Grupo da localização do chamado']);
        }
        select.innerHTML = options.map(([value, text]) =>
            `<option value="${esc(value)}" ${current === value ? 'selected' : ''}>${esc(text)}</option>`
        ).join('');
        if (!options.some(([value]) => value === current)) {
            select.value = 'none';
        }
        syncGlpiListConfigPanels();
    }

    function populateGlpiDefaultSelect(items, selected) {
        const select = document.getElementById('f_default_value');
        if (!select) {
            return;
        }
        select.innerHTML = optionList(items, selected, 'Selecione...');
    }

    function conditionItemsUrl() {
        return document.getElementById('plugin-tarefas-builder')?.dataset.conditionItemsUrl || '';
    }

    function fetchGlpiItemLabel(itemtype, id) {
        const url = conditionItemsUrl();
        if (!url || !id) {
            return Promise.resolve('');
        }
        return fetch(
            `${url}?itemtype=${encodeURIComponent(itemtype)}&selected=${encodeURIComponent(id)}`,
            {credentials: 'same-origin'}
        )
            .then((response) => response.json())
            .then((data) => {
                const items = Array.isArray(data.items) ? data.items : [];
                const match = items.find((item) => String(item.id) === String(id));
                return match?.name || '';
            })
            .catch(() => '');
    }

    /** Valor padrão pesquisável (Select2 + AJAX) — essencial para Usuários (~50k). */
    function initGlpiDefaultValueSelect(itemtype, selected) {
        const select = document.getElementById('f_default_value');
        const url = conditionItemsUrl();
        if (!select) {
            return;
        }

        const hint = document.getElementById('f_default_value_hint');
        if (hint) {
            hint.textContent = itemtype === 'User'
                ? 'Digite pelo menos 2 caracteres para localizar o usuário.'
                : 'Digite para filtrar o cadastro do GLPI.';
        }

        if (!url || !window.jQuery?.fn?.select2) {
            populateGlpiDefaultSelect([], selected);
            fetchGlpiListItems(itemtype).then((items) => populateGlpiDefaultSelect(items, selected));
            return;
        }

        const $select = window.jQuery(select);
        if ($select.hasClass('select2-hidden-accessible')) {
            try {
                $select.select2('destroy');
            } catch (error) {
                // ignore
            }
        }

        select.innerHTML = '<option value=""></option>';
        const selectedId = selected ? String(selected) : '';
        const prepare = selectedId
            ? fetchGlpiItemLabel(itemtype, selectedId).then((name) => {
                if (!name) {
                    return;
                }
                select.appendChild(new Option(name, selectedId, true, true));
            })
            : Promise.resolve();

        prepare.finally(() => {
            if (!document.getElementById('f_default_value')) {
                return;
            }
            const modal = select.closest('.modal-content');
            $select.select2({
                width: '100%',
                dropdownParent: modal ? window.jQuery(modal) : window.jQuery(document.body),
                placeholder: 'Digite para localizar...',
                allowClear: true,
                minimumInputLength: itemtype === 'User' ? 2 : 0,
                ajax: {
                    url,
                    dataType: 'json',
                    delay: 250,
                    data: (params) => ({
                        itemtype,
                        term: params.term || '',
                        selected: selectedId
                    }),
                    processResults: (data) => ({
                        results: (Array.isArray(data.items) ? data.items : []).map((item) => ({
                            id: String(item.id),
                            text: item.name
                        }))
                    }),
                    cache: true
                }
            });
            if (selectedId) {
                $select.val(selectedId).trigger('change');
            }
        });
    }

    function bindGlpiListFieldConfig(field) {
        const itemtypeSelect = document.getElementById('f_itemtype');
        const sourceSelect = document.getElementById('f_value_source');
        if (!itemtypeSelect || !sourceSelect) {
            return;
        }

        const loadFixedItems = () => {
            const itemtype = itemtypeSelect.value || 'Group';
            const selected = field.config?.default_value || document.getElementById('f_default_value')?.value || '';
            initGlpiDefaultValueSelect(itemtype, selected);
        };

        const onItemtypeChange = () => {
            const isGroup = (itemtypeSelect.value || 'Group') === 'Group';
            syncGlpiListValueSourceOptions(isGroup);
            // Troca de cadastro: limpa valor padrão antigo (outro itemtype).
            if (document.getElementById('f_default_value')) {
                field.config = field.config || {};
                if (field.config.itemtype && field.config.itemtype !== (itemtypeSelect.value || 'Group')) {
                    field.config.default_value = '';
                }
                field.config.itemtype = itemtypeSelect.value || 'Group';
            }
            if ((sourceSelect.value || 'none') === 'fixed') {
                loadFixedItems();
            }
        };

        itemtypeSelect.addEventListener('change', onItemtypeChange);
        sourceSelect.addEventListener('change', () => {
            syncGlpiListConfigPanels();
            if ((sourceSelect.value || 'none') === 'fixed') {
                loadFixedItems();
            }
        });

        onItemtypeChange();
        if ((sourceSelect.value || 'none') === 'fixed') {
            loadFixedItems();
        } else {
            syncGlpiListConfigPanels();
        }
    }

    function configHtml(field) {
        const config = field.config || {};
        if (field.type === 'short_text') return `
            ${selectRow('Máscara', 'f_mask', [
                ['none','Nenhuma'], ['cpf','CPF'], ['cnpj','CNPJ'],
                ['phone','Telefone'], ['process','Processo CNJ']
            ], config.mask)}
            ${inputRow('Texto de exemplo', 'f_placeholder', config.placeholder || '')}`;
        if (field.type === 'long_text') return `
            ${inputRow('Linhas exibidas', 'f_rows', config.rows || 4, 'number', 'min="2" max="15"')}
            ${inputRow('Texto de exemplo', 'f_placeholder', config.placeholder || '')}`;
        if (field.type === 'information') return textareaRow('Texto exibido *', 'f_info', config.text || '', 4);
        if (field.type === 'integer') return `
            <div class="alert alert-info py-2">
                Aceita somente números inteiros. Ao colar pontos, traços ou espaços, eles serão removidos automaticamente.
            </div>`;
        if (field.type === 'date') return selectRow('Formato', 'f_range', [
            ['0', 'Data única'], ['1', 'Intervalo de datas (início e fim)']
        ], config.range ? '1' : '0', 'O período não é somado como tempo de atendimento.');
        if (['single_choice', 'multiple_choice'].includes(field.type)) {
            return textareaRow('Opções *', 'f_options', (config.options || []).join('\n'), 5, 'Uma opção por linha.');
        }
        if (field.type === 'file') return `
            ${checkRow('Permitir vários arquivos', 'f_multiple', !!config.multiple)}
            ${inputRow('Extensões aceitas', 'f_extensions', config.extensions || '', 'text', 'placeholder=".pdf,.png,.jpg"')}
            ${inputRow('Tamanho máximo (MB)', 'f_max_mb', config.max_mb || 20, 'number', 'min="1"')}`;
        if (field.type === 'glpi_list') {
            const source = config.value_source || 'none';
            const isGroup = String(config.itemtype || 'Group') === 'Group';
            const valueSourceOptions = [
                ['none', 'Nenhum (analista escolhe)'],
                ['fixed', 'Valor fixo'],
                ...(isGroup ? [['location_group', 'Grupo da localização do chamado']] : [])
            ];
            return `
            ${selectRow('Cadastro do GLPI', 'f_itemtype', Object.entries(glpiTypes), config.itemtype || 'Group')}
            ${checkRow('Permitir selecionar vários itens', 'f_multiple', !!config.multiple)}
            ${selectRow('Origem do valor', 'f_value_source', valueSourceOptions, source)}
            <div id="f_glpi_fixed_wrap" class="${source === 'fixed' ? '' : 'd-none'}">
                <div class="mb-3">
                    <label class="form-label" for="f_default_value">Valor padrão</label>
                    <select class="form-select" id="f_default_value">
                        <option value="">Selecione...</option>
                    </select>
                    <div class="form-hint" id="f_default_value_hint">Digite para localizar no cadastro do GLPI.</div>
                </div>
            </div>
            <div id="f_glpi_preset_wrap" class="${source === 'none' ? 'd-none' : ''}">
                ${checkRow('Editável na tarefa', 'f_editable', config.editable !== false)}
                <div class="form-hint mb-3" id="f_glpi_preset_hint"></div>
            </div>
            <div class="form-hint mb-3">Os dados serão consultados diretamente no GLPI, respeitando a sessão e a entidade do usuário.</div>`;
        }
        return '';
    }

    function inputRow(label, id, value, type = 'text', attrs = '') {
        return `<div class="mb-3"><label class="form-label">${esc(label)}</label>
            <input class="form-control" id="${id}" type="${type}" value="${esc(value)}" ${attrs}></div>`;
    }
    function textareaRow(label, id, value, rows = 4, hint = '') {
        return `<div class="mb-3"><label class="form-label">${esc(label)}</label>
            <textarea class="form-control" id="${id}" rows="${rows}">${esc(value)}</textarea>
            ${hint ? `<div class="form-hint">${esc(hint)}</div>` : ''}</div>`;
    }
    function selectRow(label, id, options, selected, hint = '') {
        return `<div class="mb-3"><label class="form-label">${esc(label)}</label>
            <select class="form-select" id="${id}">${options.map(([value, text]) =>
                `<option value="${esc(value)}" ${String(selected) === String(value) ? 'selected' : ''}>${esc(text)}</option>`
            ).join('')}</select>${hint ? `<div class="form-hint">${esc(hint)}</div>` : ''}</div>`;
    }
    function checkRow(label, id, checked) {
        return `<label class="form-check form-switch mb-3">
            <input class="form-check-input" id="${id}" type="checkbox" ${checked ? 'checked' : ''}>
            <span class="form-check-label">${esc(label)}</span></label>`;
    }

    function openField(field, index) {
        editingIndex = index;
        destroyModalSelect2();
        clearFieldModalError();
        document.getElementById('save-field').classList.remove('d-none');
        const sources = eligibleSources(field.id);
        document.getElementById('field-modal-title').textContent =
            index === null ? `Novo campo · ${typeLabels[field.type]}` : `Editar campo · ${typeLabels[field.type]}`;
        document.getElementById('field-modal-body').innerHTML = `
            ${inputRow('Título do campo *', 'f_label', field.label || '')}
            ${inputRow('Alias interno', 'f_alias', field.name || '', 'text', 'readonly')}
            <div class="form-hint mb-3">O alias é usado no JSON e será gerado pelo título.</div>
            ${descriptionRow(field.description || '')}
            ${field.type !== 'information' ? checkRow('Campo obrigatório', 'f_required', !!field.required) : ''}
            ${configHtml(field)}
            <hr>
            <h4 class="mb-3">Visibilidade condicional</h4>
            ${sources.length ? `
                ${checkRow('Usar regras de visibilidade', 'f_cond_enabled', !!pluginTarefasNormalizeConditional(field.conditional).enabled)}
                <div id="conditional-builder-wrap" class="${pluginTarefasNormalizeConditional(field.conditional).enabled ? '' : 'd-none'}">
                    <div id="conditional-builder"></div>
                    <div class="form-hint mt-2">Combine regras com E/OU. Use <strong>+ grupo</strong> para parênteses, como na pesquisa de chamados do GLPI. Em campos de múltipla escolha, <strong>todas</strong> marca automaticamente todas as opções.</div>
                </div>` :
                '<div class="alert alert-info">Cadastre antes um campo Sim/Não, Seleção simples, Seleção múltipla ou Lista do GLPI para criar uma condição.</div>'}
        `;
        const label = document.getElementById('f_label');
        label.addEventListener('input', () => document.getElementById('f_alias').value = slug(label.value));
        document.getElementById('f_cond_enabled')?.addEventListener('change', (event) => {
            document.getElementById('conditional-builder-wrap')?.classList.toggle('d-none', !event.target.checked);
            if (event.target.checked && !pluginTarefasLogicHasRules(field.conditional?.logic)) {
                const logic = pluginTarefasNormalizeConditional(field.conditional || {}).logic;
                logic.items = [pluginTarefasBlankLogicRule()];
                field.conditional = pluginTarefasNormalizeConditional({enabled: true, logic});
                mountConditionalBuilder(field, sources);
            }
        });
        mountConditionalBuilder(field, sources);
        bindConditionalBuilder(field, sources);
        bindDescriptionCounter();
        if (field.type === 'glpi_list') {
            bindGlpiListFieldConfig(field);
        }
        fieldModal.show();
        window.pluginTarefasEditingField = field;
    }

    function readField() {
        const original = window.pluginTarefasEditingField;
        const label = document.getElementById('f_label').value.trim();
        if (!label) {
            showFieldModalError('Informe o título do campo.');
            return null;
        }
        const field = JSON.parse(JSON.stringify(original));
        field.label = label;
        field.name = slug(label);
        field.description = (document.getElementById('f_description')?.value || '').trim().slice(0, FIELD_DESC_MAX);
        field.required = document.getElementById('f_required')?.checked || false;
        const value = (id) => document.getElementById(id)?.value;
        const checked = (id) => document.getElementById(id)?.checked || false;

        if (field.type === 'short_text') field.config = {mask: value('f_mask'), placeholder: value('f_placeholder')};
        if (field.type === 'long_text') field.config = {rows: Number(value('f_rows') || 4), placeholder: value('f_placeholder')};
        if (field.type === 'information') field.config = {text: value('f_info')};
        if (field.type === 'date') field.config = {range: value('f_range') === '1'};
        if (['single_choice','multiple_choice'].includes(field.type)) {
            field.config = {options: value('f_options').split('\n').map(v => v.trim()).filter(Boolean)};
            if (!field.config.options.length) {
                showFieldModalError('Informe pelo menos uma opção.');
                return null;
            }
        }
        if (field.type === 'file') field.config = {
            multiple: checked('f_multiple'),
            extensions: value('f_extensions'),
            max_mb: Number(value('f_max_mb') || 20)
        };
        if (field.type === 'glpi_list') {
            const valueSource = value('f_value_source') || 'none';
            field.config = {
                itemtype: value('f_itemtype') || 'Group',
                multiple: checked('f_multiple'),
                value_source: valueSource,
                default_value: valueSource === 'fixed' ? (value('f_default_value') || '') : '',
                editable: valueSource === 'none' ? true : checked('f_editable')
            };
        }

        const conditionEnabled = checked('f_cond_enabled');
        if (conditionEnabled) {
            const builder = document.getElementById('conditional-builder');
            const logic = syncLogicFromDom(
                JSON.parse(JSON.stringify(window.pluginTarefasEditingLogic || pluginTarefasBlankLogicGroup())),
                builder || document
            );
            field.conditional = pluginTarefasNormalizeConditional({
                enabled: true,
                logic
            });
            if (!validateConditionalLogic(field.conditional.logic)) {
                showFieldModalError('Informe pelo menos uma regra completa: escolha o campo de origem e marque ao menos uma resposta. Em "todas", as opções são marcadas automaticamente.');
                return null;
            }
        } else {
            field.conditional = {
                enabled: false,
                field_id: '',
                operator: 'or',
                value: '',
                values: [],
                logic: pluginTarefasBlankLogicGroup()
            };
        }
        return field;
    }

    function renderPreviewField(field) {
        const condition = conditionalText(field);
        const badge = condition ? `<span class="badge bg-yellow-lt ms-2">${esc(condition.replace(/^ · /, ''))}</span>` : '';
        let control = '<input class="form-control" disabled>';
        if (field.type === 'long_text') control = '<textarea class="form-control" rows="3" disabled></textarea>';
        if (field.type === 'information') return `<div class="alert alert-info">${esc(field.config.text || '')}${badge}</div>`;
        if (field.type === 'integer') control = '<input class="form-control" inputmode="numeric" disabled>';
        if (field.type === 'date') control = field.config.range
            ? '<div class="d-flex gap-2"><input class="form-control" type="date" disabled><span>até</span><input class="form-control" type="date" disabled></div>'
            : '<input class="form-control" type="date" disabled>';
        if (field.type === 'datetime') control = '<input class="form-control" type="datetime-local" disabled>';
        if (['single_choice','yes_no'].includes(field.type)) control = '<select class="form-select" disabled><option>-----</option></select>';
        if (field.type === 'multiple_choice') control = (field.config.options || []).map(option =>
            `<label class="form-check"><input class="form-check-input" type="checkbox" disabled><span class="form-check-label">${esc(option)}</span></label>`
        ).join('');
        if (field.type === 'file') control = '<input class="form-control" type="file" disabled>';
        if (field.type === 'glpi_list') {
            const src = field.config?.value_source || 'none';
            let hint = 'Digite para localizar...';
            if (src === 'fixed') {
                hint = field.config?.editable === false ? 'Valor fixo do modelo' : 'Valor padrão (editável)';
            } else if (src === 'location_group') {
                hint = field.config?.editable === false
                    ? 'Grupo da localização (fixo)'
                    : 'Grupo da localização (editável)';
            }
            control = `<input class="form-control" placeholder="${esc(hint)}" disabled>`;
        }
        return `<div class="mb-3"><label class="form-label">${esc(field.label)}${field.required ? ' *' : ''}${badge}</label>${control}
            ${field.description ? `<div class="form-hint">${esc(field.description)}</div>` : ''}</div>`;
    }

    document.getElementById('add-field').addEventListener('click', () => {
        editingIndex = null;
        destroyModalSelect2();
        clearFieldModalError();
        document.getElementById('field-modal-title').textContent = 'Qual é o tipo do novo campo?';
        document.getElementById('save-field').classList.add('d-none');
        document.getElementById('field-modal-body').innerHTML = `
            <div class="row g-2">
                ${Object.entries(typeLabels).map(([value, text]) => `
                    <div class="col-md-6">
                        <button type="button" class="btn plugin-tarefas-type-button w-100 text-start p-3" data-new-field-type="${value}">
                            <strong>${esc(text)}</strong>
                        </button>
                    </div>`).join('')}
            </div>`;
        fieldModal.show();
    });
    document.getElementById('field-modal-body').addEventListener('click', (event) => {
        const button = event.target.closest('[data-new-field-type]');
        if (button) openField(blankField(button.dataset.newFieldType), null);
    });
    document.getElementById('save-field').addEventListener('click', () => {
        clearFieldModalError();
        const field = readField();
        if (!field) return;
        if (editingIndex === null) schema.fields.push(field);
        else schema.fields[editingIndex] = field;
        destroyModalSelect2();
        fieldModal.hide();
        renderList();
    });
    document.getElementById('field-list').addEventListener('click', (event) => {
        const button = event.target.closest('button[data-index]');
        if (!button) return;
        const index = Number(button.dataset.index);
        if (button.classList.contains('edit-field')) openField(schema.fields[index], index);
        if (button.classList.contains('remove-field') && confirm('Remover este campo?')) {
            const removedId = schema.fields[index].id;
            schema.fields.splice(index, 1);
            schema.fields.forEach(field => {
                const purgedLogic = pluginTarefasPurgeFieldFromLogic(
                    field.conditional?.logic || pluginTarefasBlankLogicGroup(),
                    removedId
                );
                field.conditional = pluginTarefasNormalizeConditional({
                    ...(field.conditional || {}),
                    enabled: !!field.conditional?.enabled && pluginTarefasLogicHasRules(purgedLogic),
                    logic: purgedLogic
                });
            });
            renderList();
        }
        if (button.classList.contains('move-up') && index > 0) {
            [schema.fields[index - 1], schema.fields[index]] = [schema.fields[index], schema.fields[index - 1]];
            renderList();
        }
        if (button.classList.contains('move-down') && index < schema.fields.length - 1) {
            [schema.fields[index + 1], schema.fields[index]] = [schema.fields[index], schema.fields[index + 1]];
            renderList();
        }
    });
    let lastExportJson = '';
    const copyJsonBtn = document.getElementById('copy-model-json');
    function setCopyJsonVisible(visible) {
        copyJsonBtn?.classList.toggle('d-none', !visible);
        if (copyJsonBtn) {
            copyJsonBtn.textContent = '';
            copyJsonBtn.innerHTML = '<i class="ti ti-copy"></i> Copiar JSON';
        }
    }
    document.getElementById('preview-form').addEventListener('click', () => {
        syncSchema();
        setCopyJsonVisible(false);
        document.getElementById('preview-title').textContent = 'Prévia: ' + (schema.title || 'Sem nome');
        document.getElementById('preview-body').innerHTML =
            '<div class="alert alert-info">Somente visualização. Campos condicionais mostram a regra em amarelo.</div>'
            + schema.fields.map(renderPreviewField).join('');
        previewModal.show();
    });
    document.getElementById('show-json').addEventListener('click', () => {
        syncSchema();
        const exported = {
            ...schema,
            automations: JSON.parse(JSON.stringify(automations)),
        };
        lastExportJson = JSON.stringify(exported, null, 2);
        setCopyJsonVisible(true);
        document.getElementById('preview-title').textContent = 'JSON do modelo';
        document.getElementById('preview-body').innerHTML =
            `<pre class="plugin-tarefas-json">${esc(lastExportJson)}</pre>`;
        previewModal.show();
    });
    copyJsonBtn?.addEventListener('click', async () => {
        if (!lastExportJson) {
            return;
        }
        try {
            await navigator.clipboard.writeText(lastExportJson);
        } catch (e) {
            const area = document.createElement('textarea');
            area.value = lastExportJson;
            document.body.appendChild(area);
            area.select();
            document.execCommand('copy');
            area.remove();
        }
        if (copyJsonBtn) {
            copyJsonBtn.innerHTML = '<i class="ti ti-check"></i> Copiado';
            window.setTimeout(() => {
                copyJsonBtn.innerHTML = '<i class="ti ti-copy"></i> Copiar JSON';
            }, 1600);
        }
    });
    document.getElementById('plugin-tarefas-model-form').addEventListener('submit', (event) => {
        syncSchema();
        if (!schema.fields.length) {
            event.preventDefault();
            alert('Adicione pelo menos um campo.');
            return;
        }
        const invalidAuto = automations.items.find(item =>
            item.when === 'fields' && !validateConditionalLogic(item.conditional?.logic)
        );
        if (invalidAuto) {
            event.preventDefault();
            alert('Em cada automação “Só se os campos...”, informe pelo menos uma regra completa.');
        }
    });
    renderList();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', pluginTarefasBoot);
} else {
    pluginTarefasBoot();
}

// A timeline do chamado entra por AJAX depois do DOMContentLoaded.
// Select2 do GLPI também só dispara evento jQuery. Por isso a escuta fica no document.
(function pluginTarefasTaskLive() {
    if (window.__pluginTarefasTaskLive) {
        return;
    }
    window.__pluginTarefasTaskLive = true;

    function bindForm(form) {
        if (!form || form.dataset.tarefasBound === '1') {
            return;
        }
        form.dataset.tarefasBound = '1';
        form.addEventListener('input', (event) => {
            if (event.target.matches('[data-integer]')) {
                event.target.value = event.target.value.replace(/\D+/g, '');
            }
        });
        form.addEventListener('change', () => applyTaskConditions(form));
    }

    function pluginTarefasInitGlpiDropdowns(root) {
        if (!root || !window.jQuery) {
            return;
        }
        const $ = window.jQuery;
        const configs = window.select2_configs || {};

        root.querySelectorAll('select').forEach((select) => {
            const id = select.id;
            if (!id || !configs[id]) {
                return;
            }

            if ($(select).hasClass('select2-hidden-accessible')) {
                try {
                    $(select).select2('destroy');
                } catch (e) {
                    // ignore
                }
                $(select).next('.select2-container').remove();
            }

            const config = Object.assign({}, configs[id], {
                field_id: id,
                width: '100%',
            });
            window.select2_configs[id] = config;

            if (config.type === 'ajax' && typeof window.setupAjaxDropdown === 'function') {
                window.setupAjaxDropdown(config);
            } else if (typeof window.setupAdaptDropdown === 'function') {
                window.setupAdaptDropdown(config);
            }
        });

        $(root).find('.select2-container').css('width', '100%');
    }

    function afterFormHtmlReady(form) {
        pluginTarefasInitGlpiDropdowns(form);
        applyTaskConditions(form);
        // Select2 em painel colapsado/AJAX às vezes nasce com largura 0.
        window.setTimeout(() => pluginTarefasInitGlpiDropdowns(form), 50);
    }

    function loadFields(form, modelId) {
        const body = form.querySelector('#plugin-tarefas-form-body');
        if (!body) {
            return;
        }

        modelId = String(modelId ?? '');
        if (form.dataset.tarefasLoadedModel === modelId) {
            return;
        }
        form.dataset.tarefasLoadedModel = modelId;

        if (!modelId) {
            body.innerHTML = '';
            return;
        }

        const url = form.dataset.fieldsUrl
            + '?tickets_id=' + encodeURIComponent(form.dataset.ticketId)
            + '&models_id=' + encodeURIComponent(modelId);
        body.innerHTML = '<div class="text-center py-3">'
            + '<span class="spinner-border" role="status" aria-hidden="true"></span>'
            + '<div class="text-muted small mt-2">Carregando o formulário...</div></div>';

        const fail = () => {
            form.dataset.tarefasLoadedModel = '';
            body.innerHTML = '<div class="alert alert-danger">'
                + 'Não foi possível carregar o formulário do modelo.</div>';
        };

        // jQuery .load executa os scripts das listas do GLPI (select2 / select2_configs).
        if (window.jQuery) {
            window.jQuery(body).load(url, (response, status) => {
                if (status === 'error') {
                    fail();
                    return;
                }
                afterFormHtmlReady(form);
            });
            return;
        }

        fetch(url, {credentials: 'same-origin'})
            .then((response) => {
                if (!response.ok) {
                    throw new Error('fail');
                }
                return response.text();
            })
            .then((html) => {
                body.innerHTML = html;
                afterFormHtmlReady(form);
            })
            .catch(fail);
    }

    function onModelChange(select) {
        const form = select.closest('#plugin-tarefas-task-form');
        if (!form) {
            return;
        }
        bindForm(form);
        loadFields(form, select.value);
    }

    function syncForm() {
        const form = document.getElementById('plugin-tarefas-task-form');
        if (!form) {
            return;
        }
        bindForm(form);
        const select = form.querySelector('#plugin-tarefas-model-select');
        if (select && select.value) {
            loadFields(form, select.value);
            return;
        }
        if (form.querySelector('#task-schema')) {
            applyTaskConditions(form);
        }
    }

    document.addEventListener('change', (event) => {
        const select = event.target?.id === 'plugin-tarefas-model-select'
            ? event.target
            : event.target?.closest?.('#plugin-tarefas-model-select');
        if (select) {
            onModelChange(select);
        }
    });

    document.addEventListener('shown.bs.collapse', (event) => {
        if (event.target?.id === 'new-action-plugin-tarefas-block'
            || event.target?.querySelector?.('#plugin-tarefas-task-form')) {
            syncForm();
            const form = document.getElementById('plugin-tarefas-task-form');
            if (form) {
                pluginTarefasInitGlpiDropdowns(form);
            }
        }
    });

    if (window.jQuery) {
        const $ = window.jQuery;
        $(document).on('change select2:select select2:clear', '#plugin-tarefas-model-select', function () {
            onModelChange(this);
        });
        $(document).on('change select2:select select2:clear select2:unselect', '#plugin-tarefas-task-form select', function () {
            const form = this.closest('#plugin-tarefas-task-form');
            if (form) {
                applyTaskConditions(form);
            }
        });
        $(document).on('shown.bs.collapse', '#new-action-plugin-tarefas-block', syncForm);
    }

    const observer = new MutationObserver(() => {
        const form = document.getElementById('plugin-tarefas-task-form');
        if (form && form.dataset.tarefasBound !== '1') {
            syncForm();
        }
    });
    observer.observe(document.documentElement, {childList: true, subtree: true});

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', syncForm);
    } else {
        syncForm();
    }
})();

function pluginTarefasMountLocationGroupField() {
    const block = document.querySelector('.plugin-tarefas-location-group');
    if (!block || block.dataset.tarefasMounted === '1') {
        return;
    }

    const form = block.closest('form')
        || document.querySelector('form#asset_form, form[name="asset_form"], main form[method="post"]');
    if (!form) {
        return;
    }

    if (!form.contains(block)) {
        form.appendChild(block);
    }

    const lonInput = form.querySelector('[name="longitude"], #longitude, [id*="longitude" i]');
    const anchorRow = lonInput?.closest('.form-field, .field-container, .row, .mb-2, .mb-3');
    if (anchorRow?.parentNode) {
        anchorRow.parentNode.insertBefore(block, anchorRow.nextSibling);
    }

    block.dataset.tarefasMounted = '1';
}

(function pluginTarefasLocationFormLive() {
    if (window.__pluginTarefasLocationFormLive) {
        return;
    }
    window.__pluginTarefasLocationFormLive = true;

    const syncLocation = () => pluginTarefasMountLocationGroupField();
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', syncLocation);
    } else {
        syncLocation();
    }

    const observer = new MutationObserver(syncLocation);
    observer.observe(document.documentElement, {childList: true, subtree: true});
})();

function pluginTarefasFieldAnswerSelect(holder, fieldName) {
    const expectedNames = [`answers[${fieldName}]`, `answers[${fieldName}][]`];
    return Array.from(holder.querySelectorAll('select')).find(select =>
        expectedNames.includes(select.name)
    ) || holder.querySelector('select');
}

function pluginTarefasReadFieldAnswer(holder, field) {
    if (!holder) {
        return null;
    }
    if (field.type === 'glpi_list') {
        const select = pluginTarefasFieldAnswerSelect(holder, field.name);
        if (!select) {
            return null;
        }
        if (select.multiple) {
            const selected = Array.from(select.selectedOptions)
                .map(option => option.value)
                .filter(value => value && value !== '0');
            return selected.length ? selected : null;
        }
        const value = select.value || '';
        return value && value !== '0' ? value : null;
    }
    if (field.type === 'multiple_choice') {
        const checked = Array.from(holder.querySelectorAll('input[type="checkbox"]:checked'))
            .map(input => input.value);
        return checked.length ? checked : null;
    }
    if (field.type === 'yes_no' || field.type === 'single_choice') {
        const select = pluginTarefasFieldAnswerSelect(holder, field.name);
        if (select) {
            const value = select.value || '';
            return value || null;
        }
    }
    const input = holder.querySelector('textarea, input:not([type="checkbox"]):not([type="radio"]):not([type="file"])');
    const value = input?.value ?? '';
    return value || null;
}

function pluginTarefasClearFieldAnswer(holder) {
    if (!holder) {
        return;
    }
    holder.querySelectorAll('select').forEach(select => {
        if (select.multiple) {
            Array.from(select.options).forEach(option => {
                option.selected = false;
            });
        } else {
            select.value = '';
        }
        if (window.jQuery?.fn?.select2 && window.jQuery(select).hasClass('select2-hidden-accessible')) {
            window.jQuery(select).val(select.multiple ? [] : null).trigger('change.select2');
        }
    });
    holder.querySelectorAll('input[type="checkbox"], input[type="radio"]').forEach(input => {
        input.checked = false;
    });
    holder.querySelectorAll('textarea, input:not([type="checkbox"]):not([type="radio"]):not([type="file"]):not([type="hidden"])').forEach(input => {
        input.value = '';
    });
}

function applyTaskConditions(form) {
    if (form.dataset.tarefasApplying === '1') {
        return;
    }
    const schemaHolder = form.querySelector('#task-schema') || document.getElementById('task-schema');
    if (!schemaHolder) {
        return;
    }
    let schema;
    try { schema = JSON.parse(schemaHolder.value ?? schemaHolder.textContent); }
    catch (e) { return; }

    form.dataset.tarefasApplying = '1';
    try {
        const fields = schema.fields || [];
        for (let pass = 0; pass <= fields.length; pass++) {
            const values = {};
            fields.forEach(field => {
                const row = form.querySelector(`[data-field-row="${CSS.escape(field.id)}"]`);
                const holder = form.querySelector(`[data-field-name="${CSS.escape(field.name)}"]`);
                if (!holder || row?.classList.contains('d-none')) {
                    values[field.name] = null;
                    return;
                }
                values[field.name] = pluginTarefasReadFieldAnswer(holder, field);
            });

            let changed = false;
            fields.forEach(field => {
                const row = form.querySelector(`[data-field-row="${CSS.escape(field.id)}"]`);
                if (!row) {
                    return;
                }
                const visible = pluginTarefasConditionMatches(field, fields, values);
                const wasHidden = row.classList.contains('d-none');
                row.classList.toggle('d-none', !visible);
                row.querySelectorAll('input,select,textarea').forEach(input => {
                    input.disabled = !visible;
                });
                if (!visible && !wasHidden) {
                    pluginTarefasClearFieldAnswer(row);
                    changed = true;
                }
                if (visible === wasHidden) {
                    changed = true;
                }
            });
            if (!changed) {
                break;
            }
        }
        pluginTarefasSyncFieldRows(form);
    } finally {
        form.dataset.tarefasApplying = '';
    }
}

function pluginTarefasSyncFieldRows(form) {
    form.querySelectorAll('.plugin-tarefas-fields-row').forEach(row => {
        const visible = row.querySelectorAll('[data-field-row]:not(.d-none)');
        row.classList.toggle('plugin-tarefas-fields-row--solo', visible.length <= 1);
        row.classList.toggle('d-none', visible.length === 0);
    });
}

function pluginTarefasConditionActual(source, value) {
    if (Array.isArray(value)) {
        return value.map(item => String(item?.id ?? item)).filter(Boolean);
    }
    if (value && typeof value === 'object') {
        return value.id ? [String(value.id)] : [];
    }
    return value ? [String(value)] : [];
}
