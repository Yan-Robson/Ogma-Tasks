<?php

/**
 * Configuração global do plugin (perfis com acesso ao botão na timeline do chamado).
 */
class PluginTarefasConfig
{
    public const MODE_ALL = 'all';
    public const MODE_SELECTED = 'selected';

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_tarefas_config';
    }

    public static function get(): array
    {
        global $DB;

        $defaults = [
            'timeline_profiles_mode' => self::MODE_ALL,
            'timeline_profiles_ids'  => [],
        ];

        if (!$DB->tableExists(self::getTable())) {
            return $defaults;
        }

        foreach ($DB->request(['FROM' => self::getTable(), 'LIMIT' => 1]) as $row) {
            $mode = (string) ($row['timeline_profiles_mode'] ?? self::MODE_ALL);
            if (!in_array($mode, [self::MODE_ALL, self::MODE_SELECTED], true)) {
                $mode = self::MODE_ALL;
            }

            $ids = json_decode((string) ($row['timeline_profiles_ids'] ?? '[]'), true);
            if (!is_array($ids)) {
                $ids = [];
            }

            return [
                'timeline_profiles_mode' => $mode,
                'timeline_profiles_ids'  => array_values(array_unique(array_filter(array_map('intval', $ids)))),
            ];
        }

        return $defaults;
    }

    public static function save(array $input): bool
    {
        global $DB;

        if (!$DB->tableExists(self::getTable())) {
            return false;
        }

        $mode = (string) ($input['timeline_profiles_mode'] ?? self::MODE_ALL);
        if (!in_array($mode, [self::MODE_ALL, self::MODE_SELECTED], true)) {
            $mode = self::MODE_ALL;
        }

        $ids = [];
        if ($mode === self::MODE_SELECTED) {
            $raw = $input['timeline_profiles_ids'] ?? [];
            if (!is_array($raw)) {
                $raw = $raw === null || $raw === '' ? [] : [$raw];
            }
            $ids = array_values(array_unique(array_filter(array_map('intval', $raw))));
        }

        $payload = [
            'timeline_profiles_mode' => $mode,
            'timeline_profiles_ids'  => self::encodeJson($ids),
        ];

        $existing = null;
        foreach ($DB->request(['FROM' => self::getTable(), 'LIMIT' => 1]) as $row) {
            $existing = $row;
            break;
        }

        if ($existing === null) {
            return (bool) $DB->insert(self::getTable(), $payload);
        }

        return (bool) $DB->update(self::getTable(), $payload, ['id' => (int) $existing['id']]);
    }

    private static function encodeJson(mixed $value): string
    {
        return (string) json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    public static function currentProfileCanSeeTimelineButton(): bool
    {
        if (Session::getLoginUserID() === false) {
            return false;
        }

        global $DB;
        if (!$DB->tableExists(self::getTable())) {
            return true;
        }

        $config = self::get();
        if (($config['timeline_profiles_mode'] ?? self::MODE_ALL) === self::MODE_ALL) {
            return true;
        }

        $profileId = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        if ($profileId <= 0) {
            return false;
        }

        return in_array($profileId, $config['timeline_profiles_ids'] ?? [], true);
    }
}
