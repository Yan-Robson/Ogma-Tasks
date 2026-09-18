<?php

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);

header('Content-Type: application/json; charset=UTF-8');

global $DB;

$allowed = [
    Group::class,
    User::class,
    Profile::class,
    ITILCategory::class,
    Entity::class,
    Location::class,
];
$itemtype = (string) ($_GET['itemtype'] ?? '');
if (!in_array($itemtype, $allowed, true) || !class_exists($itemtype)) {
    echo PluginTarefasModel::encodeJson(['items' => []]);
    return;
}

$item = new $itemtype();
if (!$item instanceof CommonDBTM) {
    echo PluginTarefasModel::encodeJson(['items' => []]);
    return;
}

$term = trim((string) ($_GET['term'] ?? $_GET['q'] ?? ''));
$selected = (int) ($_GET['selected'] ?? $_GET['id'] ?? 0);
$isUser = $itemtype === User::class;
// Usuários: só por busca (ou o já selecionado). Demais cadastros: lista inicial limitada.
$limit = $isUser || $term !== '' ? 50 : 400;

$table = $item->getTable();
$isTree = $item instanceof CommonTreeDropdown;
$where = [];

if ($item->isEntityAssign() && !empty($_SESSION['glpiactiveentities'])) {
    $where = array_merge($where, getEntitiesRestrictCriteria($table));
}
if ($item->maybeDeleted()) {
    $where['is_deleted'] = 0;
}
if ($isUser) {
    $where['is_active'] = 1;
}

$formatRow = static function (array $row) use ($itemtype, $isTree): ?array {
    $id = (int) ($row['id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    if ($itemtype === User::class) {
        $name = formatUserName(
            $id,
            (string) ($row['name'] ?? ''),
            (string) ($row['realname'] ?? ''),
            (string) ($row['firstname'] ?? '')
        );
    } else {
        $name = (string) ($isTree
            ? ($row['completename'] ?? $row['name'] ?? $id)
            : ($row['name'] ?? $id));
    }
    return [
        'id'   => $id,
        'name' => $name !== '' ? $name : (string) $id,
    ];
};

$fetchById = static function (int $id) use ($DB, $table, $item, $isUser, $formatRow): ?array {
    if ($id <= 0) {
        return null;
    }
    $criteria = [
        'FROM'  => $table,
        'WHERE' => ['id' => $id],
        'LIMIT' => 1,
    ];
    if ($item->maybeDeleted()) {
        $criteria['WHERE']['is_deleted'] = 0;
    }
    if ($isUser) {
        $criteria['WHERE']['is_active'] = 1;
    }
    foreach ($DB->request($criteria) as $row) {
        return $formatRow($row);
    }
    return null;
};

// Usuário sem termo: não despejar milhares de linhas — só o selecionado, se houver.
if ($isUser && $term === '') {
    $items = [];
    if ($selected > 0) {
        $one = $fetchById($selected);
        if ($one !== null) {
            $items[] = $one;
        }
    }
    echo PluginTarefasModel::encodeJson(['items' => $items]);
    return;
}

if ($term !== '') {
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
    if ($isUser) {
        $where[] = [
            'OR' => [
                'name'      => ['LIKE', $like],
                'realname'  => ['LIKE', $like],
                'firstname' => ['LIKE', $like],
            ],
        ];
    } elseif ($isTree) {
        $where['completename'] = ['LIKE', $like];
    } else {
        $where['name'] = ['LIKE', $like];
    }
}

$criteria = [
    'FROM'  => $table,
    'WHERE' => $where,
    'LIMIT' => $limit,
];
if ($isUser) {
    $criteria['ORDER'] = ['realname ASC', 'firstname ASC', 'name ASC'];
} else {
    $criteria['ORDER'] = [$isTree ? 'completename ASC' : 'name ASC'];
}

$items = [];
$seen = [];
foreach ($DB->request($criteria) as $row) {
    $formatted = $formatRow($row);
    if ($formatted === null) {
        continue;
    }
    $items[] = $formatted;
    $seen[$formatted['id']] = true;
}

if ($selected > 0 && empty($seen[$selected])) {
    $one = $fetchById($selected);
    if ($one !== null) {
        array_unshift($items, $one);
    }
}

echo PluginTarefasModel::encodeJson(['items' => $items]);
