<?php

function plugin_tarefas_install(): bool
{
    global $DB;

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();

    $models = 'glpi_plugin_tarefas_models';
    if (!$DB->tableExists($models)) {
        $sql = "CREATE TABLE `$models` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL,
            `description` text DEFAULT NULL,
            `schema_json` longtext NOT NULL,
            `webhook_url` text DEFAULT NULL,
            `version` int unsigned NOT NULL DEFAULT 1,
            `is_active` tinyint NOT NULL DEFAULT 1,
            `default_time_minutes` int unsigned NOT NULL DEFAULT 0,
            `taskcategories_id` int unsigned NOT NULL DEFAULT 0,
            `access_mode` varchar(20) NOT NULL DEFAULT 'all',
            `access_ids` text DEFAULT NULL,
            `webhook_enabled` tinyint NOT NULL DEFAULT 1,
            `allow_save` tinyint NOT NULL DEFAULT 0,
            `automations_json` longtext DEFAULT NULL,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `name` (`name`),
            KEY `is_active` (`is_active`),
            KEY `taskcategories_id` (`taskcategories_id`),
            KEY `access_mode` (`access_mode`)
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation";
        if (!$DB->doQuery($sql)) {
            return false;
        }
    }

    if ($DB->tableExists($models) && !$DB->fieldExists($models, 'default_time_minutes')) {
        if (!$DB->doQuery(
            "ALTER TABLE `$models` ADD `default_time_minutes` int unsigned NOT NULL DEFAULT 0"
        )) {
            return false;
        }
    }

    if ($DB->tableExists($models) && !$DB->fieldExists($models, 'webhook_enabled')) {
        if (!$DB->doQuery(
            "ALTER TABLE `$models` ADD `webhook_enabled` tinyint NOT NULL DEFAULT 1"
        )) {
            return false;
        }
    }

    if ($DB->tableExists($models) && !$DB->fieldExists($models, 'taskcategories_id')) {
        if (!$DB->doQuery(
            "ALTER TABLE `$models`
                ADD `taskcategories_id` int unsigned NOT NULL DEFAULT 0,
                ADD `access_mode` varchar(20) NOT NULL DEFAULT 'all',
                ADD `access_ids` text DEFAULT NULL,
                ADD KEY `taskcategories_id` (`taskcategories_id`),
                ADD KEY `access_mode` (`access_mode`)"
        )) {
            return false;
        }
    }

    if ($DB->tableExists($models) && !$DB->fieldExists($models, 'allow_save')) {
        if (!$DB->doQuery(
            "ALTER TABLE `$models` ADD `allow_save` tinyint NOT NULL DEFAULT 0"
        )) {
            return false;
        }
    }

    if ($DB->tableExists($models) && !$DB->fieldExists($models, 'automations_json')) {
        if (!$DB->doQuery(
            "ALTER TABLE `$models` ADD `automations_json` longtext DEFAULT NULL"
        )) {
            return false;
        }
    }

    $tasks = 'glpi_plugin_tarefas_tasks';
    if (!$DB->tableExists($tasks)) {
        $sql = "CREATE TABLE `$tasks` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `tickets_id` int unsigned NOT NULL,
            `plugin_tarefas_models_id` int unsigned NOT NULL DEFAULT 0,
            `model_name` varchar(255) NOT NULL,
            `model_version` int unsigned NOT NULL,
            `schema_json` longtext NOT NULL,
            `content_json` longtext NOT NULL,
            `webhook_url` text DEFAULT NULL,
            `users_id` int unsigned NOT NULL,
            `user_name` varchar(255) NOT NULL,
            `users_id_executor` int unsigned NOT NULL DEFAULT 0,
            `executor_name` varchar(255) NOT NULL DEFAULT '',
            `groups_id` int unsigned NOT NULL DEFAULT 0,
            `group_name` varchar(255) NOT NULL DEFAULT '',
            `taskcategories_id` int unsigned NOT NULL DEFAULT 0,
            `time_minutes` int unsigned NOT NULL,
            `is_private` tinyint NOT NULL DEFAULT 0,
            `allow_save` tinyint NOT NULL DEFAULT 0,
            `automations_json` longtext DEFAULT NULL,
            `status` varchar(50) NOT NULL DEFAULT 'finished',
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `tickets_id` (`tickets_id`),
            KEY `models_id` (`plugin_tarefas_models_id`),
            KEY `users_id` (`users_id`),
            KEY `users_id_executor` (`users_id_executor`),
            KEY `groups_id` (`groups_id`),
            KEY `taskcategories_id` (`taskcategories_id`),
            KEY `is_private` (`is_private`),
            KEY `date_creation` (`date_creation`)
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation";
        if (!$DB->doQuery($sql)) {
            return false;
        }
    }

    if ($DB->tableExists($tasks) && !$DB->fieldExists($tasks, 'is_private')) {
        if (!$DB->doQuery(
            "ALTER TABLE `$tasks` ADD `is_private` tinyint NOT NULL DEFAULT 0, ADD KEY `is_private` (`is_private`)"
        )) {
            return false;
        }
    }

    if ($DB->tableExists($tasks) && !$DB->fieldExists($tasks, 'users_id_executor')) {
        if (!$DB->doQuery(
            "ALTER TABLE `$tasks`
                ADD `users_id_executor` int unsigned NOT NULL DEFAULT 0,
                ADD `executor_name` varchar(255) NOT NULL DEFAULT '',
                ADD `groups_id` int unsigned NOT NULL DEFAULT 0,
                ADD `group_name` varchar(255) NOT NULL DEFAULT '',
                ADD `taskcategories_id` int unsigned NOT NULL DEFAULT 0,
                ADD KEY `users_id_executor` (`users_id_executor`),
                ADD KEY `groups_id` (`groups_id`),
                ADD KEY `taskcategories_id` (`taskcategories_id`)"
        )) {
            return false;
        }
    }

    if ($DB->tableExists($tasks) && !$DB->fieldExists($tasks, 'allow_save')) {
        if (!$DB->doQuery(
            "ALTER TABLE `$tasks` ADD `allow_save` tinyint NOT NULL DEFAULT 0"
        )) {
            return false;
        }
    }

    if ($DB->tableExists($tasks) && !$DB->fieldExists($tasks, 'automations_json')) {
        if (!$DB->doQuery(
            "ALTER TABLE `$tasks` ADD `automations_json` longtext DEFAULT NULL"
        )) {
            return false;
        }
    }

    $locations = 'glpi_plugin_tarefas_locations';
    if (!$DB->tableExists($locations)) {
        $sql = "CREATE TABLE `$locations` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `locations_id` int unsigned NOT NULL,
            `groups_id` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `locations_id` (`locations_id`),
            KEY `groups_id` (`groups_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation";
        if (!$DB->doQuery($sql)) {
            return false;
        }
    }

    $config = 'glpi_plugin_tarefas_config';
    if (!$DB->tableExists($config)) {
        $sql = "CREATE TABLE `$config` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `timeline_profiles_mode` varchar(20) NOT NULL DEFAULT 'all',
            `timeline_profiles_ids` text DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation";
        if (!$DB->doQuery($sql)) {
            return false;
        }
        $DB->insert($config, [
            'timeline_profiles_mode' => 'all',
            'timeline_profiles_ids'  => '[]',
        ]);
    }

    if ($DB->tableExists($tasks)) {
        $DB->doQuery(
            "UPDATE `$tasks` SET `status` = 'finished' WHERE `status` IN ('filled', '')"
        );
    }

    $documents = 'glpi_plugin_tarefas_task_documents';
    if (!$DB->tableExists($documents)) {
        $sql = "CREATE TABLE `$documents` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `plugin_tarefas_tasks_id` int unsigned NOT NULL,
            `documents_id` int unsigned NOT NULL,
            `field_name` varchar(255) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `task_document_field` (`plugin_tarefas_tasks_id`, `documents_id`, `field_name`),
            KEY `documents_id` (`documents_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation";
        if (!$DB->doQuery($sql)) {
            return false;
        }
    }

    plugin_tarefas_migrate_automations();

    return true;
}

function plugin_tarefas_migrate_automations(): void
{
    global $DB;

    $models = 'glpi_plugin_tarefas_models';
    if ($DB->tableExists($models) && $DB->fieldExists($models, 'automations_json')) {
        $iterator = $DB->request(['FROM' => $models]);
        foreach ($iterator as $row) {
            $current = trim((string) ($row['automations_json'] ?? ''));
            if ($current !== '' && $current !== '[]' && $current !== '{}') {
                $decoded = json_decode($current, true);
                if (is_array($decoded) && !empty($decoded['items'])) {
                    continue;
                }
            }
            $url = trim((string) ($row['webhook_url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $automations = PluginTarefasAutomation::fromWebhook(
                $url,
                !array_key_exists('webhook_enabled', $row) || !empty($row['webhook_enabled'])
            );
            $DB->update($models, [
                'automations_json' => PluginTarefasModel::encodeJson($automations),
            ], ['id' => (int) $row['id']]);
        }
    }

    $tasks = 'glpi_plugin_tarefas_tasks';
    if ($DB->tableExists($tasks) && $DB->fieldExists($tasks, 'automations_json')) {
        $iterator = $DB->request(['FROM' => $tasks]);
        foreach ($iterator as $row) {
            $current = trim((string) ($row['automations_json'] ?? ''));
            if ($current !== '' && $current !== '[]' && $current !== '{}') {
                $decoded = json_decode($current, true);
                if (is_array($decoded) && !empty($decoded['items'])) {
                    continue;
                }
            }
            $url = trim((string) ($row['webhook_url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $automations = PluginTarefasAutomation::fromWebhook($url, true);
            $DB->update($tasks, [
                'automations_json' => PluginTarefasModel::encodeJson($automations),
            ], ['id' => (int) $row['id']]);
        }
    }
}

function plugin_tarefas_uninstall(): bool
{
    global $DB;

    foreach ([
        'glpi_plugin_tarefas_task_documents',
        'glpi_plugin_tarefas_tasks',
        'glpi_plugin_tarefas_locations',
        'glpi_plugin_tarefas_config',
        'glpi_plugin_tarefas_models',
    ] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }

    return true;
}

function plugin_tarefas_upgrade(string $old_version): bool
{
    return plugin_tarefas_install();
}
