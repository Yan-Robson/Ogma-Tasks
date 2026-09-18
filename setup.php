<?php

use Glpi\Plugin\Hooks;

define('PLUGIN_TAREFAS_VERSION', '0.4.1');

require_once __DIR__ . '/inc/config.class.php';

function plugin_init_tarefas(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['tarefas'] = true;

    Plugin::registerClass(PluginTarefasModel::class);
    Plugin::registerClass(PluginTarefasTask::class);
    Plugin::registerClass(PluginTarefasMenu::class);
    Plugin::registerClass(PluginTarefasTimeline::class);
    Plugin::registerClass(PluginTarefasLocation::class);
    Plugin::registerClass(PluginTarefasConfig::class);

    $PLUGIN_HOOKS['config_page']['tarefas'] = 'front/config.php';

    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['tarefas'] = [
        'helpdesk' => PluginTarefasMenu::class,
    ];

    $PLUGIN_HOOKS[Hooks::ADD_CSS]['tarefas'] = ['css/tarefas.css'];
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['tarefas'] = ['js/tarefas.js'];

    $PLUGIN_HOOKS[Hooks::TIMELINE_ANSWER_ACTIONS]['tarefas']
        = 'plugin_tarefas_answer_actions';
    $PLUGIN_HOOKS[Hooks::TIMELINE_ITEMS]['tarefas']
        = 'plugin_tarefas_timeline_items';

    $PLUGIN_HOOKS[Hooks::PRE_ITEM_FORM]['tarefas'] = 'plugin_tarefas_pre_item_form';
    $PLUGIN_HOOKS['item_add']['tarefas'] = [
        Location::class => 'plugin_tarefas_location_item_add',
    ];
    $PLUGIN_HOOKS['item_update']['tarefas'] = [
        Location::class => 'plugin_tarefas_location_item_update',
    ];
    $PLUGIN_HOOKS['pre_item_update']['tarefas'] = [
        Location::class => 'plugin_tarefas_location_pre_item_update',
    ];
    $PLUGIN_HOOKS['pre_item_purge']['tarefas'] = [
        Location::class => 'plugin_tarefas_location_pre_item_purge',
    ];
}

function plugin_tarefas_answer_actions(array $options): array
{
    return PluginTarefasTimeline::answerActions($options);
}

function plugin_tarefas_timeline_items(array $options): array
{
    return PluginTarefasTimeline::injectItems($options);
}

function plugin_tarefas_pre_item_form($params): void
{
    $item = is_array($params) ? ($params['item'] ?? null) : $params;
    if ($item instanceof Location) {
        PluginTarefasLocation::showFormField($item);
    }
}

function plugin_tarefas_location_item_add(Location $location): void
{
    PluginTarefasLocation::saveFromLocation($location);
}

function plugin_tarefas_location_item_update(Location $location): void
{
    PluginTarefasLocation::saveFromLocation($location);
}

function plugin_tarefas_location_pre_item_update(Location $location): void
{
    PluginTarefasLocation::saveFromLocation($location);
}

function plugin_tarefas_location_pre_item_purge(Location $location): void
{
    PluginTarefasLocation::deleteForLocation($location);
}

function plugin_version_tarefas(): array
{
    return [
        'name'         => 'Plugin Tarefas',
        'version'      => PLUGIN_TAREFAS_VERSION,
        'author'       => 'Robson Yan, DACS',
        'license'      => 'GPLv3+',
        'homepage'     => 'mailto:robson.yan@tjpa.jus.br',
        'requirements' => [
            'glpi' => [
                'min' => '11.0.0',
                'max' => '11.99.99',
            ],
        ],
    ];
}

function plugin_tarefas_check_prerequisites(): bool
{
    return version_compare(GLPI_VERSION, '11.0.0', '>=');
}

function plugin_tarefas_check_config(bool $verbose = false): bool
{
    global $DB;

    if (!$DB->tableExists(PluginTarefasConfig::getTable())) {
        if ($verbose) {
            echo __('Atualize o plugin para criar a tabela de configuração.', 'tarefas');
        }
        return false;
    }

    return true;
}
