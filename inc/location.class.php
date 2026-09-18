<?php

/**
 * Vínculo Localização GLPI → Grupo responsável (plugin Tarefas).
 */
class PluginTarefasLocation extends CommonDBTM
{
    public static $rightname = 'config';

    public static function getTypeName($nb = 0): string
    {
        return _n('Grupo responsável da localização', 'Grupos responsáveis das localizações', $nb, 'tarefas');
    }

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_tarefas_locations';
    }

    public static function getGroupIdForLocation(int $locationsId): int
    {
        if ($locationsId <= 0) {
            return 0;
        }

        global $DB;

        foreach ($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['locations_id' => $locationsId],
            'LIMIT' => 1,
        ]) as $row) {
            return max(0, (int) ($row['groups_id'] ?? 0));
        }

        return 0;
    }

    public static function getGroupIdForTicket(Ticket $ticket): int
    {
        $locationsId = (int) ($ticket->fields['locations_id'] ?? 0);
        return self::getGroupIdForLocation($locationsId);
    }

    public static function showFormField(Location $location): void
    {
        if (!$location instanceof Location) {
            return;
        }

        $locationsId = (int) $location->getID();
        $groupId = $locationsId > 0 ? self::getGroupIdForLocation($locationsId) : 0;
        $canEdit = Session::haveRight('dropdown', UPDATE)
            || Session::haveRight('config', UPDATE);

        echo '<div class="form-field row mb-2 plugin-tarefas-location-group">';
        echo '<label class="col-form-label col-xxl-4 text-xxl-end" for="plugin_tarefas_groups_id">';
        echo htmlescape(__('Grupo responsável', 'tarefas'));
        echo '</label>';
        echo '<div class="col-xxl-8 field-container">';
        if ($canEdit) {
            Dropdown::show(Group::class, [
                'name'     => 'plugin_tarefas_groups_id',
                'value'    => $groupId,
                'width'    => '100%',
                'display'  => true,
                'comments' => false,
                'entity'   => $location->fields['entities_id'] ?? $_SESSION['glpiactive_entity'] ?? 0,
            ]);
            echo '<div class="form-text text-muted">';
            echo htmlescape(__(
                'Grupo sugerido nas tarefas do plugin quando o chamado estiver nesta localização.',
                'tarefas'
            ));
            echo '</div>';
        } else {
            echo '<span class="form-control-plaintext">';
            echo $groupId > 0
                ? htmlescape(Dropdown::getDropdownName(Group::getTable(), $groupId))
                : htmlescape(__('-----'));
            echo '</span>';
        }
        echo '</div></div>';
    }

    public static function postedGroupId(Location $location): ?int
    {
        if (array_key_exists('plugin_tarefas_groups_id', $_POST)) {
            return max(0, (int) $_POST['plugin_tarefas_groups_id']);
        }

        $input = is_array($location->input ?? null) ? $location->input : [];
        if (array_key_exists('plugin_tarefas_groups_id', $input)) {
            return max(0, (int) $input['plugin_tarefas_groups_id']);
        }

        return null;
    }

    public static function saveFromLocation(Location $location): void
    {
        if (!$location instanceof Location || $location->getID() <= 0) {
            return;
        }

        if (!Session::haveRight('dropdown', UPDATE) && !Session::haveRight('config', UPDATE)) {
            return;
        }

        $groupId = self::postedGroupId($location);
        if ($groupId === null) {
            // Formulário sem o campo (outra aba / API): não apaga o vínculo.
            return;
        }

        global $DB;

        $locationsId = (int) $location->getID();
        $existingId = 0;

        foreach ($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['locations_id' => $locationsId],
            'LIMIT' => 1,
        ]) as $row) {
            $existingId = (int) ($row['id'] ?? 0);
            break;
        }

        if ($groupId <= 0) {
            if ($existingId > 0) {
                $DB->delete(self::getTable(), ['id' => $existingId]);
            }
            return;
        }

        if ($existingId > 0) {
            $DB->update(self::getTable(), [
                'groups_id' => $groupId,
            ], [
                'id' => $existingId,
            ]);
            return;
        }

        $DB->insert(self::getTable(), [
            'locations_id' => $locationsId,
            'groups_id'    => $groupId,
        ]);
    }

    public static function deleteForLocation(Location $location): void
    {
        if (!$location instanceof Location || $location->getID() <= 0) {
            return;
        }

        global $DB;
        $DB->delete(self::getTable(), [
            'locations_id' => (int) $location->getID(),
        ]);
    }
}
