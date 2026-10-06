<?php
/**
 * 数据包（Data Pack）—— 内容的可移植格式
 * ---------------------------------------------------------------------------
 * 目的：让「数据」从「PHP 数组」变成「语言无关的文件」，从而支持三件事：
 *   1. 开源仓库里内容以文件形式存在，可 diff、可 review、可由非 PHP 贡献者修改
 *   2. 后台一键导出成同样的文件
 *   3. 任意安装包之间互相导入
 *
 * 目录形态（data/seed/）：
 *   manifest.json        版本、导出时间、各表行数、每个文件的 sha256
 *   categories.json      字典表，纯 slug 键
 *   regions.json
 *   relation_types.json
 *   dynasties.json       朝代，主键是 slug
 *   cn_events.json       中国节点，朝代用 slug 引用
 *   world_events.json    世界大事
 *   relations.json       中外联动，两端用标题引用
 *
 * 三条硬规则（改动前务必想清楚）：
 *
 * 1. **不导出主键。** id 是自增的，两次安装完全不同。跨表引用一律用
 *    slug（字典与朝代）或 title（节点与大事）互引，装载时再解析成 id。
 *
 * 2. **不导出 created_at / 用户账号。** 时间戳属于「何时写进这个站」，
 *    不是内容本身；users.password 哈希更不能进任何可分发的包。
 *
 * 3. **null 字段一律省略不写。** 这样「无 → 有值」在 git diff 里表现为
 *    新增一行而不是改动一行，review 时能看清到底加了哪个字段。
 *
 * 每个 .json 的外层是 {"table":..., "natural_key":..., "rows":[...]}；
 * 装载时也接受裸数组，方便手工编辑与第三方工具生成。
 */

if (!defined('DATAPACK_FORMAT')) {
    define('DATAPACK_FORMAT', 'huwen-datapack');
    define('DATAPACK_VERSION', 1);
}

/**
 * 表定义：导出与导入共用的唯一事实来源。
 *
 * order      装载顺序（被引用的表必须在前）
 * columns    参与导出的列（不含 id / created_at 等环境相关列）
 * refs       跨表引用：源字段 => [表, 目标列, 用哪个字段匹配]
 * drop_null  写文件时是否省略 null 值（默认 true，见硬规则 3）
 */
function datapack_spec(): array
{
    return [
        'categories' => [
            'file' => 'categories.json', 'order' => 10, 'label' => '分类字典',
            'natural_key' => 'slug',
            'columns' => ['slug', 'name', 'color', 'sort'],
            'required'  => ['slug', 'name'],
        ],
        'regions' => [
            'file' => 'regions.json', 'order' => 20, 'label' => '区域字典',
            'natural_key' => 'slug',
            'columns' => ['slug', 'name', 'emoji', 'sort'],
            'required'  => ['slug', 'name'],
        ],
        'relation_types' => [
            'file' => 'relation_types.json', 'order' => 30, 'label' => '联动类型',
            'natural_key' => 'slug',
            'columns' => ['slug', 'name', 'descr', 'sort'],
            'required'  => ['slug', 'name'],
        ],
        'dynasties' => [
            'file' => 'dynasties.json', 'order' => 40, 'label' => '朝代',
            'natural_key' => 'slug',
            'columns' => ['name', 'slug', 'start_year', 'end_year', 'color', 'summary', 'sort'],
            'required'  => ['name', 'slug', 'start_year', 'end_year'],
        ],
        'cn_events' => [
            'file' => 'cn_events.json', 'order' => 50, 'label' => '中国节点',
            'natural_key' => 'title',
            'columns' => ['title', 'year', 'year_end', 'month', 'day',
                          'category', 'place', 'summary', 'detail', 'figures',
                          'importance', 'is_key'],
            'required'  => ['title', 'year', 'category'],
            // 种子里的 'dynasty' 存的是 slug，落库时要变成 dynasty_id
            'refs' => [
                'dynasty' => ['table' => 'dynasties', 'column' => 'dynasty_id', 'by' => 'slug'],
            ],
        ],
        'world_events' => [
            'file' => 'world_events.json', 'order' => 60, 'label' => '世界大事',
            'natural_key' => 'title',
            'columns' => ['title', 'year', 'year_end', 'month', 'day', 'region',
                          'category', 'place', 'summary', 'detail', 'figures',
                          'importance', 'source'],
            'required'  => ['title', 'year', 'region', 'category'],
        ],
        'relations' => [
            'file' => 'relations.json', 'order' => 70, 'label' => '中外联动',
            'natural_key' => null,
            // 一条联动由「两端」唯一确定，所以合成键 = cn_ref + 分隔符 + world_ref。
            // 没有它，merge 模式会无脑重复插入（实测 19 条变 38 条）。
            'natural_key_parts' => ['cn_ref', 'world_ref'],
            'merge_key_sql' =>
                'SELECT c.title AS k1, w.title AS k2, r.id AS id
                 FROM relations r
                 JOIN cn_events c    ON c.id = r.cn_event_id
                 JOIN world_events w ON w.id = r.world_event_id',
            'columns' => ['type', 'note'],
            // 包里叫 type（与种子一致），列名却叫 relation_type
            'map'    => ['type' => 'relation_type'],
            'required'  => [],
            // relation_type 是 VARCHAR(24)，存的就是 relation_types.slug 本身，
            // 不是指向 relation_types.id 的外键 —— 所以按「枚举」校验而不是按引用解析。
            'enums'  => ['type' => 'relation_types'],
            'refs' => [
                'cn_ref'    => ['table' => 'cn_events',    'column' => 'cn_event_id',    'by' => 'title'],
                'world_ref' => ['table' => 'world_events', 'column' => 'world_event_id', 'by' => 'title'],
            ],
        ],
    ];
}

/** 按 order 排序的表名列表 */
function datapack_tables(): array
{
    $spec = datapack_spec();
    $keys = array_keys($spec);
    usort($keys, function ($a, $b) use ($spec) { return $spec[$a]['order'] <=> $spec[$b]['order']; });
    return $keys;
}

/**
 * 包字段名 → 数据库列名。
 * 大多数表两者同名；relations.type → relation_type 这类由 spec['map'] 指定。
 * 导出与导入都必须过这一个函数，否则两边对「字段叫什么」的假设会分叉。
 *
 * @return array 包字段 => 列名（已剔除库里不存在的列）
 */
function datapack_col_map(string $table, array $s): array
{
    $rename = $s['map'] ?? [];
    $out = [];
    foreach ($s['columns'] as $field) {
        $col = isset($rename[$field]) ? $rename[$field] : $field;
        if (self_dp_has_column($table, $col)) $out[$field] = $col;
    }
    return $out;
}

// ===========================================================================
// 导出：数据库 → 文件
// ===========================================================================

/**
 * 把当前库导出成数据文件。
 *
 * @param string $dir     输出目录（不存在会创建）
 * @param array  $opts    meta: 写入 manifest 的附加信息
 * @return array  {dir, files, counts, manifest}
 */
function datapack_write(string $dir, array $opts = []): array
{
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException('无法创建导出目录：' . $dir);
    }
    $spec = datapack_spec();
    $files = [];
    $counts = [];

    foreach (datapack_tables() as $t) {
        $s = $spec[$t];
        $rows = datapack_export_table($t, $s);
        $counts[$t] = count($rows);

        $doc = [
            'table'       => $t,
            'natural_key' => $s['natural_key'],
            'rows'        => $rows,
        ];
        $json = datapack_json($doc);
        $path = rtrim($dir, '/\\') . '/' . $s['file'];
        if (@file_put_contents($path, $json) === false) {
            throw new RuntimeException('写入失败：' . $path);
        }
        $files[$s['file']] = [
            'rows'   => count($rows),
            'bytes'  => strlen($json),
            'sha256' => hash('sha256', $json),
        ];
    }

    $manifest = [
        'format'       => DATAPACK_FORMAT,
        'version'      => DATAPACK_VERSION,
        'generated_at' => date('c'),
        'site'         => function_exists('c_site_name') ? c_site_name() : '',
        'counts'       => $counts,
        'files'        => $files,
    ];
    foreach ($opts as $k => $v) {
        $manifest['meta'][$k] = $v;
    }
    $mj = datapack_json($manifest);
    file_put_contents(rtrim($dir, '/\\') . '/manifest.json', $mj);

    return ['dir' => $dir, 'files' => $files, 'counts' => $counts, 'manifest' => $manifest];
}

/** 单表：SELECT 出来 → 解析引用 → 去掉 null */
function datapack_export_table(string $table, array $s): array
{
    $all  = DB::fetchAll('SELECT * FROM `' . $table . '`');
    $refs = $s['refs'] ?? [];

    // 引用查表：把 id → 自然键 建映射
    //
    // ⚠️ 列存在性要检查「本地表的 ref 列」和「被引用表的 by 列」两处：
    //    写错成检查 self_dp_has_column($r['table'], $idCol) 会静默丢数据 ——
    //    relations.cn_ref 配的是 cn_events，而 cn_events 里并没有 cn_event_id 这一列，
    //    于是判断失败、映射不建、cn_ref 导出成 null。实测中这一错就抹掉了
    //    relations 的全部三端引用，而「导出 vs 导出」的逐行比对**看不出来**。
    $maps = [];
    foreach ($refs as $srcField => $r) {
        $idCol = $r['column'];
        if (!self_dp_has_column($table, $idCol)) {
            throw new RuntimeException(
                '表 ' . $table . ' 上找不到引用列 ' . $idCol . '（' . $srcField . '），请检查 datapack_spec()'
            );
        }
        if (!self_dp_has_column($r['table'], $r['by'])) {
            throw new RuntimeException(
                '被引用的表 ' . $r['table'] . ' 上找不到 ' . $r['by'] . ' 列（' . $srcField . '），请检查 datapack_spec()'
            );
        }
        // 本地表的 ref 列 JOIN 被引用表的自然键列。
        // 注意 ref 列（如 cn_events.dynasty_id）在**本地**表，
        // 自然键（如 dynasties.slug）在**被引用**表，必须 JOIN，不能单表 SELECT。
        $rows = DB::fetchAll(
            'SELECT l.`' . $idCol . '` AS id, r.`' . $r['by'] . '` AS k
             FROM `' . $table . '` l
             LEFT JOIN `' . $r['table'] . '` r ON r.id = l.`' . $idCol . '`'
        );
        $m = [];
        foreach ($rows as $row) {
            if ($row['k'] !== null) $m[(int) $row['id']] = (string) $row['k'];
        }
        $maps[$srcField] = $m;
    }

    $out = [];
    $colMap = datapack_col_map($table, $s);
    foreach ($all as $row) {
        $rec = [];
        // 普通列（包字段名可能与列名不同，如 type → relation_type）
        foreach ($colMap as $field => $col) {
            $rec[$field] = $row[$col];
        }
        // 引用列：用 id 查回自然键
        foreach ($refs as $srcField => $r) {
            $idCol = $r['column'];
            if (!self_dp_has_column($table, $idCol)) continue;
            $rid = isset($row[$idCol]) ? (int) $row[$idCol] : 0;
            $rec[$srcField] = isset($maps[$srcField][$rid]) ? $maps[$srcField][$rid] : null;
        }
        $out[] = self_dp_strip_null($rec);
    }
    return $out;
}

/** 列是否存在（轻量查库，装载前不会因列名写错而 500） */
function self_dp_has_column(string $table, string $col): bool
{
    static $cache = [];
    $k = $table . '.' . $col;
    if (isset($cache[$k])) return $cache[$k];
    try {
        $r = DB::fetchCol(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $col]
        );
        $cache[$k] = ((int) $r) > 0;
    } catch (Throwable $e) {
        $cache[$k] = false;
    }
    return $cache[$k];
}

function self_dp_strip_null(array $rec): array
{
    foreach ($rec as $k => $v) {
        if ($v === null) unset($rec[$k]);
    }
    return $rec;
}

/**
 * 合成自然键：用 \x1f（单元分隔符）连接各段。
 * 选它是因为标题里几乎不会出现控制字符，比 '|' 之类的可见字符更安全。
 * merge_key_sql 里各段按顺序别名为 k1、k2、k3…，与 natural_key_parts 顺序对应。
 */
function self_dp_join_key(array $row, array $parts): string
{
    $seg = [];
    foreach ($parts as $i => $f) {
        if (array_key_exists($f, $row))      { $seg[] = (string) $row[$f]; }
        elseif (array_key_exists('k' . ($i + 1), $row)) { $seg[] = (string) $row['k' . ($i + 1)]; }
        else { $seg[] = ''; }
    }
    return implode("\x1f", $seg);
}

// ===========================================================================
// 读取与校验
// ===========================================================================

/**
 * 读一个数据目录（或上传的单个 json 文件）→ 归一化后的表数据。
 * 缺失的表直接跳过，交给 validate() 去报。
 *
 * @return array 表名 => 行数组
 */
function datapack_read(string $dir): array
{
    $spec = datapack_spec();
    $out = [];

    foreach ($spec as $t => $s) {
        $path = rtrim($dir, '/\\') . '/' . $s['file'];
        if (!is_file($path)) continue;
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') continue;
        $doc = json_decode($raw, true);
        if (!is_array($doc)) continue;
        // 兼容裸数组
        $rows = isset($doc['rows']) && is_array($doc['rows']) ? $doc['rows'] : $doc;
        $clean = [];
        foreach ($rows as $r) {
            if (is_array($r)) $clean[] = $r;
        }
        $out[$t] = $clean;
    }
    return $out;
}

/**
 * 装载前校验。只读不写，返回给用户看的报告。
 *
 * @return array ['errors'=>[], 'warnings'=>[], 'counts'=>[]]
 */
function datapack_validate(array $data, array $opts = []): array
{
    $spec     = datapack_spec();
    $strictFk = !empty($opts['strict_fk']);
    $errors   = [];
    $warnings = [];
    $counts   = [];

    $dynSlugs = [];
    $cnTitles = [];
    $weTitles = [];
    $rtSlugs  = [];

    foreach (datapack_tables() as $t) {
        if (!isset($data[$t])) { $warnings[] = '缺少数据文件：' . $spec[$t]['file']; continue; }
        $s = $spec[$t];
        $rows = $data[$t];
        $counts[$t] = count($rows);
        $nk = $s['natural_key'];

        $seen = [];
        foreach ($rows as $i => $r) {
            $where = $s['file'] . ' 第 ' . ($i + 1) . ' 行';
            foreach (($s['required'] ?? []) as $c) {
                if (!array_key_exists($c, $r) || $r[$c] === '' || $r[$c] === null) {
                    $errors[] = $where . ' 缺必填字段 ' . $c;
                }
            }
            // 年份必须是整数且在合理范围
            foreach (['year', 'year_end', 'start_year', 'end_year'] as $yc) {
                if (array_key_exists($yc, $r) && $r[$yc] !== null && !is_int($r[$yc])) {
                    if (!is_numeric($r[$yc])) {
                        $errors[] = $where . ' 的 ' . $yc . ' 不是数字：' . var_export($r[$yc], true);
                    } else {
                        $errors[] = $where . ' 的 ' . $yc . ' 必须是整数（JSON 里不要写 1919.0 或带引号的字符串）';
                    }
                }
            }
            if (isset($r['year_end'], $r['year']) && is_int($r['year_end']) && is_int($r['year'])
                && $r['year_end'] < $r['year']) {
                $errors[] = $where . ' 的 year_end(' . $r['year_end'] . ') 早于 year(' . $r['year'] . ')';
            }
            // 自然键唯一
            if ($nk) {
                $k = isset($r[$nk]) ? (string) $r[$nk] : '';
                if ($k === '') {
                    $errors[] = $where . ' 缺自然键 ' . $nk;
                } elseif (isset($seen[$k])) {
                    // 注意：这里必须用拼接或 {$nk}，不能写成 "$nk「..."
                    // —— 全角括号会被 PHP 当成变量名的一部分（$nk「），
                    //    插值出来是空串。项目中文字符串里一律用 . 拼接。
                    $errors[] = $where . ' 的 ' . $nk . '「' . $k . '」与第 ' . ($seen[$k] + 1) . ' 行重复';
                } else {
                    $seen[$k] = $i;
                }
            }
        }

        // 收集引用目标
        if ($t === 'dynasties') {
            foreach ($rows as $r) if (isset($r['slug'])) $dynSlugs[(string) $r['slug']] = true;
        } elseif ($t === 'cn_events') {
            foreach ($rows as $r) if (isset($r['title'])) $cnTitles[(string) $r['title']] = true;
        } elseif ($t === 'world_events') {
            foreach ($rows as $r) if (isset($r['title'])) $weTitles[(string) $r['title']] = true;
        } elseif ($t === 'relation_types') {
            foreach ($rows as $r) if (isset($r['slug'])) $rtSlugs[(string) $r['slug']] = true;
        }
    }

    // 跨表引用完整性
    foreach (datapack_tables() as $t) {
        if (!isset($data[$t]) || empty($spec[$t]['refs'])) continue;
        $s = $spec[$t];
        foreach ($data[$t] as $i => $r) {
            foreach ($s['refs'] as $srcField => $ref) {
                if (!array_key_exists($srcField, $r)) {
                    // 引用键缺失不能静默跳过 —— 曾经正是这里放过了一次
                    // 「导出时映射没建成功 → cn_ref 变 null → 被剔除」的静默丢数据。
                    $msg = $s['file'] . ' 第 ' . ($i + 1) . ' 行缺引用字段 ' . $srcField;
                    if ($strictFk) $errors[] = $msg; else $warnings[] = $msg;
                    continue;
                }
                if ((string) $r[$srcField] === '') {
                    $msg = $s['file'] . ' 第 ' . ($i + 1) . ' 行的 ' . $srcField . ' 为空';
                    if ($strictFk) $errors[] = $msg; else $warnings[] = $msg;
                    continue;
                }
                $v = (string) $r[$srcField];
                $pool = $ref['table'] === 'dynasties' ? $dynSlugs
                      : ($ref['table'] === 'cn_events' ? $cnTitles
                      : ($ref['table'] === 'world_events' ? $weTitles : $rtSlugs));
                if (!isset($pool[$v])) {
                    $msg = $s['file'] . ' 第 ' . ($i + 1) . ' 行的 ' . $srcField
                         . '「' . $v . '」在 ' . $ref['table'] . ' 里找不到';
                    if ($strictFk) $errors[] = $msg; else $warnings[] = $msg;
                }
            }
        }
    }

    // 枚举校验：字段值必须是某字典表里已有的 slug。
    // 用于 relation_type 这类「存的就是 slug 本身、又不是外键」的列。
    foreach (datapack_tables() as $t) {
        if (!isset($data[$t]) || empty($spec[$t]['enums'])) continue;
        $s = $spec[$t];
        foreach ($s['enums'] as $field => $dictTable) {
            $pool = [];
            if (isset($data[$dictTable])) {
                foreach ($data[$dictTable] as $dr) {
                    if (isset($dr['slug'])) $pool[(string) $dr['slug']] = true;
                }
            }
            if (!$pool) {
                // 数据包里没有该字典文件时无法校验其取值，跳过而不是瞎猜
                $warnings[] = $s['file'] . ' 的 ' . $field . ' 未校验（缺少 ' . $dictTable . ' 数据）';
                continue;
            }
            foreach ($data[$t] as $i => $r) {
                if (!array_key_exists($field, $r)) continue;
                $v = (string) $r[$field];
                if ($v === '' || !isset($pool[$v])) {
                    $msg = $s['file'] . ' 第 ' . ($i + 1) . ' 行的 ' . $field . '「' . $v
                         . '」不在 ' . $dictTable . ' 里';
                    if ($strictFk) $errors[] = $msg; else $warnings[] = $msg;
                }
            }
        }
    }

    return ['errors' => $errors, 'warnings' => $warnings, 'counts' => $counts];
}

// ===========================================================================
// 导入：文件 → 数据库
// ===========================================================================

/**
 * 把数据包写进当前库。**全程一个事务**，任何一步失败整批回滚。
 *
 * mode = 'replace'  先清空内容表再写（适合全新安装 / 还原到某个快照）
 * mode = 'merge'    只补缺失的，按自然键去重，不动已有行（适合给现有站加点料）
 *
 * @return array 统计报告
 * @throws RuntimeException 校验不通过或写入失败（事务已回滚）
 */
function datapack_import(array $data, string $mode = 'replace'): array
{
    $v = datapack_validate($data, ['strict_fk' => true]);
    if ($v['errors']) {
        throw new RuntimeException("数据包校验未通过，已取消导入：\n - " . implode("\n - ", array_slice($v['errors'], 0, 20)));
    }

    $spec = datapack_spec();
    $rep  = ['mode' => $mode, 'inserted' => [], 'skipped' => [], 'warnings' => $v['warnings']];

    DB::transaction(function () use ($data, $mode, $spec, &$rep) {
        // replace：按依赖倒序清空（先清引用方，再清被引用方）
        if ($mode === 'replace') {
            $tables = array_reverse(datapack_tables());
            foreach ($tables as $t) {
                if (!self_dp_has_column($t, 'id')) continue;
                DB::exec('DELETE FROM `' . $t . '`');
            }
            // ⚠️ 自增归零**必须放在事务之外**。
            // MySQL 的 ALTER TABLE 会触发隐式提交，放在事务里会让
            // 「失败整体回滚」的承诺失效 —— 前面 DELETE 已落盘，
            // 后面插入再失败就只剩一个空库。宁可 id 不从 1 开始，
            // 也不能丢这个保证。id 本来就不导出，归零只是观感问题。
            $reset = [];
            foreach (datapack_tables() as $t) {
                if (self_dp_has_column($t, 'id')) $reset[] = $t;
            }
        }

        // 自然键 → 新 id 映射，装载下一张表时用来解析引用
        $keyToId = [];

        foreach (datapack_tables() as $t) {
            if (!isset($data[$t])) continue;
            $s = $spec[$t];
            $nk = $s['natural_key'];
            $ins = 0; $skip = 0;

            // merge 模式：先取出已存在的自然键
            $parts = $s['natural_key_parts'] ?? null;
            $existing = [];
            if ($mode === 'merge') {
                if ($nk) {
                    if (self_dp_has_column($t, $nk)) {
                        foreach (DB::fetchAll('SELECT `' . $nk . '` AS k, id FROM `' . $t . '`') as $row) {
                            $existing[(string) $row['k']] = (int) $row['id'];
                        }
                    }
                } elseif ($parts && !empty($s['merge_key_sql'])) {
                    // 合成键：查一次全量，在 PHP 里拼，避免逐行查库
                    foreach (DB::fetchAll($s['merge_key_sql']) as $row) {
                        $existing[self_dp_join_key($row, $parts)] = (int) $row['id'];
                    }
                }
            }

            // 列顺序 = 普通列（经 map 映射）+ 引用列
            $cols = array_values(datapack_col_map($t, $s));
            foreach (($s['refs'] ?? []) as $srcField => $r) {
                if (self_dp_has_column($t, $r['column'])) $cols[] = $r['column'];
            }
            if (!$cols) { $rep['inserted'][$t] = 0; $rep['skipped'][$t] = 0; continue; }

            $ph        = implode(',', array_fill(0, count($cols), '?'));
            $colList   = '`' . implode('`,`', $cols) . '`';
            $hasCreated = self_dp_has_column($t, 'created_at');
            $sqlIns = 'INSERT INTO `' . $t . '` (' . $colList . ($hasCreated ? ',`created_at`' : '')
                . ') VALUES (' . $ph . ($hasCreated ? ', ' . SQL_NOW : '') . ')';

            // 包字段 → $cols 里的下标，供填值时查
            $posOf = [];
            foreach (datapack_col_map($t, $s) as $field => $col) {
                $p = array_search($col, $cols, true);
                if ($p !== false) $posOf[$field] = $p;
            }
            $refCols = [];
            foreach (($s['refs'] ?? []) as $srcField => $r) {
                $p = array_search($r['column'], $cols, true);
                if ($p !== false) $refCols[$srcField] = $p;
            }

            foreach ($data[$t] as $r) {
                // merge 去重：单字段键或合成键任一命中即跳过
                if ($mode === 'merge' && $existing) {
                    $key = null;
                    if ($nk) {
                        $key = (string) ($r[$nk] ?? '');
                    } elseif ($parts) {
                        $key = self_dp_join_key($r, $parts);
                    }
                    if ($key !== null && isset($existing[$key])) {
                        // $keyToId 只在有 natural_key 的表上被读（供后续表解析引用），
                        // 合成键的表（relations）本身不被引用，所以不用回填。
                        if ($nk) $keyToId[$t][$key] = $existing[$key];
                        $skip++;
                        continue;
                    }
                }
                $vals = array_fill(0, count($cols), null);
                foreach ($posOf as $field => $p) {
                    if (isset($refCols[$field])) continue;   // 引用列下面单独填
                    $vals[$p] = array_key_exists($field, $r) ? $r[$field] : null;
                }
                // 引用列：由自然键查回 id
                foreach ($refCols as $srcField => $p) {
                    $ref = $s['refs'][$srcField];
                    $natKey = isset($r[$srcField]) ? (string) $r[$srcField] : '';
                    $vals[$p] = isset($keyToId[$ref['table']][$natKey])
                        ? $keyToId[$ref['table']][$natKey] : null;
                }
                DB::exec($sqlIns, $vals);
                $newId = (int) DB::lastInsertId();
                if ($nk && isset($r[$nk])) $keyToId[$t][(string) $r[$nk]] = $newId;
                $ins++;
            }
            $rep['inserted'][$t] = $ins;
            $rep['skipped'][$t]  = $skip;
        }
    });

    // 事务已提交，现在才做自增归零（见上面的隐式提交说明）
    if (!empty($reset)) {
        foreach ($reset as $t) {
            try { DB::exec('ALTER TABLE `' . $t . '` AUTO_INCREMENT = 1'); }
            catch (Throwable $e) { /* 权限不足等情况下跳过，不影响已导入的数据 */ }
        }
        $rep['autoincrement_reset'] = true;
    }

    return $rep;
}

// ===========================================================================
// 工具
// ===========================================================================

/**
 * JSON 编码：中文不转义、斜杠不转义、缩进 2 空格、结尾换行。
 * 缩进是为了让 git diff 有行可对；不转义斜杠是为了 URL 不用满屏 \"。
 */
function datapack_json($doc): string
{
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;
    $s = json_encode($doc, $flags);
    if ($s === false) {
        throw new RuntimeException('JSON 编码失败：' . json_last_error_msg());
    }
    return $s . "\n";
}

/**
 * 兼容旧的 PHP 种子（data/seed_part*.php）。
 * 保留是为了让老包在装完 JSON 版之前仍能安装，也方便对照迁移。
 *
 * @return array 表名 => 行数组（未做引用解析，格式与 JSON 版一致）
 */
function datapack_read_legacy_php(): array
{
    $merged = ['categories' => [], 'regions' => [], 'relation_types' => [],
               'dynasties' => [], 'cn_events' => [], 'world_events' => [], 'relations' => []];
    $files = glob(__DIR__ . '/../data/seed_part*.php');
    sort($files);
    foreach ($files as $f) {
        $part = require $f;
        if (!is_array($part)) continue;
        foreach ($merged as $k => $_) {
            if (!empty($part[$k]) && is_array($part[$k])) {
                $merged[$k] = array_merge($merged[$k], $part[$k]);
            }
        }
    }
    // 字典表在旧种子里不在 data/ 里，从 Schema 取
    $merged['categories']     = class_exists('Schema') ? Schema::categories() : [];
    $merged['regions']        = class_exists('Schema') ? Schema::regions() : [];
    $merged['relation_types'] = [
        ['slug' => 'cause',  'name' => '因果', 'sort' => 10],
        ['slug' => 'effect', 'name' => '影响', 'sort' => 20],
        ['slug' => 'echo',   'name' => '对照', 'sort' => 30],
    ];
    foreach ($merged as $k => $rows) {
        $clean = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $c = self_dp_strip_null($r);
            unset($c['descr'], $c['emoji']);
            $clean[] = $c;
        }
        $merged[$k] = $clean;
    }
    return $merged;
}

/** data/seed/ 存在且有 manifest 就优先用它，否则回退旧 PHP 种子 */
function datapack_load_default(): array
{
    $dir = __DIR__ . '/../data/seed';
    if (is_file($dir . '/manifest.json')) {
        $d = datapack_read($dir);
        if ($d) return $d;
    }
    return datapack_read_legacy_php();
}
