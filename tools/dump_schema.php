<?php
/**
 * 开发期工具：把 Schema 的 DDL 与索引定义导出为 JSON，
 * 供 tools/verify_index.py 做真实执行验证。
 *
 * 用法：php tools/dump_schema.php > tools/_schema.json
 */
require __DIR__ . '/../config.php';
require __DIR__ . '/../inc/schema.php';

$out = [
    'tables'  => Schema::tables(),
    'indexes' => [],
    'driver'  => c_db_driver(),
];

foreach (Schema::indexes() as $idx) {
    $out['indexes'][] = [
        'table'  => $idx[0],
        'name'   => $idx[1],
        'cols'   => $idx[2],
        'unique' => (bool) ($idx[3] ?? false),
    ];
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
