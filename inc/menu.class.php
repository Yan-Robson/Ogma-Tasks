<?php

class PluginTarefasMenu extends CommonGLPI
{
    public static $rightname = 'ticket';

    public static function getMenuName(): string
    {
        return __('Plugin Tarefas', 'tarefas');
    }

    public static function getMenuContent(): array
    {
        $content = [
            'title' => self::getMenuName(),
            'page'  => '/plugins/tarefas/front/model.php',
            'icon'  => 'ti ti-list-check',
        ];

        if (PluginTarefasModel::canCreate()) {
            $content['links'] = [
                'search' => '/plugins/tarefas/front/model.php',
                'add'    => '/plugins/tarefas/front/model.form.php',
            ];
        }

        return $content;
    }

    public static function canView(): bool
    {
        return Session::haveRight('ticket', READ);
    }
}
