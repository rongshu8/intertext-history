<?php
/**
 * 运行环境自检（PHP 版本 / 扩展 / MySQL 版本 / 关键能力）
 * ---------------------------------------------------------------------------
 * 为什么需要这个文件
 *   本项目支持 MySQL 5.5 ~ 8.4，版本差异涉及 DDL 语法、索引键长上限、
 *   排序规则、sql_mode 默认值等多处。装到新环境时如果只看「页面能不能打开」
 *   是不够的 —— 有些失败要运行到一半才暴露（比如排序规则不兼容导致
 *   ORDER BY 结果变了）。
 *
 *   所以提供集中的探测入口：装完先跑这个，确认全部 ✓ 再装数据。
 *
 * 用法
 *   Web：install.php 会自动渲染自检结果（所有状态下都显示）
 *   CLI：php -r "require 'inc/bootstrap.php'; require 'inc/env_check.php';
 *                  print_r(env_check());"
 *
 * ⚠️ 这不是安全边界，只读不写，可以公开访问。
 */

if (!function_exists('env_check')) {

    /**
     * 一次跑完所有检查
     *
     * @return array{php:array, extensions:array<string,bool>, db:array<string,mixed>}
     */
    function env_check(): array
    {
        return [
            'php'        => env_check_php(),
            'extensions' => env_check_extensions(),
            'db'         => env_check_mysql(),
        ];
    }

    // ------------------------------------------------------------------
    // PHP 版本
    // ------------------------------------------------------------------

    /**
     * 支持范围：**7.4 ~ 8.x**
     *
     * 下限 7.4：代码用了箭头函数（7.4）、类型声明、`??=`（7.4）
     * 上限不限：未使用任何 8.x 移除的特性。8.x 上的 deprecated 项
     *          （如 PDO::ATTR_STRINGIFY_FETCHES）已在 db.php 里
     *          用 defined() 包起来，不产生告警。
     */
    function env_check_php(): array
    {
        $ver   = PHP_VERSION;
        $min   = '7.4.0';
        $ok    = version_compare($ver, $min, '>=');
        $major = PHP_MAJOR_VERSION;

        $note = '';
        if (!$ok) {
            $note = '低于 7.4，箭头函数与部分语法不可用';
        } elseif ($major >= 8) {
            $note = 'PHP ' . $major . '.x 已适配（未使用任何 8.x 移除的特性）';
        }

        return [
            'version' => $ver,
            'major'   => $major,
            'ok'      => $ok,
            'need'    => $min,
            'note'    => $note,
        ];
    }

    // ------------------------------------------------------------------
    // 扩展
    // ------------------------------------------------------------------

    /**
     * @return array<string,bool> 扩展名 => 是否加载
     */
    function env_check_extensions(): array
    {
        return [
            'pdo'        => extension_loaded('pdo'),
            'pdo_mysql'  => extension_loaded('pdo_mysql'),
            'mbstring'   => extension_loaded('mbstring'),
            'json'       => extension_loaded('json'),
            'session'    => extension_loaded('session'),
            // 以下为「有更好、没有也能跑」
            'openssl'    => extension_loaded('openssl'),
            'zlib'       => extension_loaded('zlib'),
        ];
    }

    // ------------------------------------------------------------------
    // 数据库（仅 MySQL）
    // ------------------------------------------------------------------

    /**
     * 探测 MySQL 版本与关键能力
     *
     * 关键设计：**优先复用运行时连接**（DB::pdo()）而不是新建独立连接。
     * 因为 sql_mode / time_zone / collation 这几项是应用在建连时设置的，
     * 新建连接读到的是服务端默认值 —— 那不是实际生效的配置，
     * 拿它自检会给出误导性结论（曾出现"显示 STRICT 未开，实际已开"）。
     *
     * 连接失败不抛异常，返回 error 字段 —— 让调用方决定怎么显示。
     *
     * @return array<string,mixed>
     */
    function env_check_mysql(): array
    {
        $out = [
            'driver'               => 'mysql',
            'connected'            => false,
            'error'                => '',
            'server_version'       => '',
            'major'                => 0,
            'is_mariadb'           => false,
            'datetime_default_ts'  => false,
            'connection_collation' => '',
            'default_collation'    => '',
            'sql_mode'             => '',
            'time_zone'            => '',
            'innodb_large_prefix'  => null,
            'innodb_file_format'   => '',
            'strict_enabled'       => false,
            'notes'                => [],
        ];

        try {
            $pdo = null;

            // 优先复用运行时连接
            if (class_exists('DB')) {
                try {
                    $pdo = DB::pdo();
                } catch (Throwable $e) {
                    $pdo = null;   // 运行时尚未建连，退回独立探测
                }
            }
            if ($pdo === null) {
                $pdo = env_check_pdo();
            }
            if (!$pdo) {
                $out['error'] = 'PDO 未加载或连接失败';
                return $out;
            }

            $out['connected']      = true;

            // 这三条原来是裸的（无 try）。环境检测是**只读体检**，
            // 任何一条查不到都不该让整页Fatal —— MariaDB / 受限托管
            // 常常拿不到其中某一项。逐条降级为「取不到就留空」。
            try {
                $out['server_version'] = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            } catch (Throwable $e) {
                $out['server_version'] = '';
                $out['notes'][] = '读不到 MySQL 版本：' . $e->getMessage();
            }
            $out['major']      = (int) strtok($out['server_version'], '.');
            $out['is_mariadb'] = stripos($out['server_version'], 'mariadb') !== false;
            try {
                $out['sql_mode'] = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
            } catch (Throwable $e) {
                $out['sql_mode']  = '';
                $out['notes'][] = '读不到 sql_mode：' . $e->getMessage();
            }
            try {
                $out['time_zone'] = (string) $pdo->query('SELECT @@SESSION.time_zone')->fetchColumn();
            } catch (Throwable $e) {
                $out['time_zone'] = '';
                $out['notes'][] = '读不到 time_zone：' . $e->getMessage();
            }

            // STRICT 是否真的生效（这是判断「越界会不会静默截断」的关键）
            $out['strict_enabled'] = (stripos($out['sql_mode'], 'STRICT_TRANS_TABLES') !== false)
                || (stripos($out['sql_mode'], 'STRICT_ALL_TABLES') !== false);

            // 当前连接实际使用的排序规则（比库默认值更重要）
            try {
                $out['connection_collation'] = (string) $pdo->query(
                    'SELECT @@SESSION.collation_connection'
                )->fetchColumn();
            } catch (Throwable $e) {
                $out['connection_collation'] = '';
            }

            // 库默认排序规则
            try {
                $out['default_collation'] = (string) $pdo->query(
                    "SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA
                     WHERE SCHEMA_NAME = DATABASE()"
                )->fetchColumn();
            } catch (Throwable $e) {
                $out['default_collation'] = '';
            }

            // DATETIME DEFAULT CURRENT_TIMESTAMP：5.6+ 才有
            $out['datetime_default_ts'] = $out['major'] >= 6 || $out['is_mariadb'];

            // InnoDB 索引前缀限制（影响唯一索引的列宽上限）
            try {
                $r = $pdo->query(
                    'SELECT @@SESSION.innodb_large_prefix AS lp, @@SESSION.innodb_file_format AS ff'
                )->fetch();
                $out['innodb_large_prefix'] = $r ? $r['lp'] : null;
                $out['innodb_file_format']  = $r ? (string) $r['ff'] : '';
            } catch (Throwable $e) {
                // MySQL 8.0 移除了这两个变量，取不到不影响功能（上限本就是 3072）
            }

            // ---- 备注（不阻断，只提示）----
            if (!$out['strict_enabled']) {
                $out['notes'][] = 'sql_mode 里没有 STRICT_*: 字段超长会被静默截断而不报错，'
                    . '可能写入坏数据。检查 inc/db.php 的 initSession 是否执行成功。';
            }
            if ($out['connection_collation'] !== ''
                && strpos($out['connection_collation'], 'utf8mb4') !== 0) {
                $out['notes'][] = '连接排序规则是 ' . $out['connection_collation']
                    . '，不是 utf8mb4*：中文排序与比较行为可能不符合预期。';
            }
            if ($out['major'] === 5 && !$out['is_mariadb'] && !$out['datetime_default_ts']) {
                $out['notes'][] = 'MySQL 5.5 不支持 DATETIME DEFAULT CURRENT_TIMESTAMP，'
                    . '本项目改用 DATETIME NULL + 应用层写入（已适配，无需处理）';
            }
            if ($out['innodb_large_prefix'] !== null && (int) $out['innodb_large_prefix'] === 0) {
                $out['notes'][] = 'innodb_large_prefix=OFF：索引键上限 767 字节，'
                    . 'utf8mb4 下唯一索引列不得超过 191 字符（Schema::createIndex() 会拦截并报错）';
            }
            if ($out['is_mariadb']) {
                $out['notes'][] = '检测到 MariaDB：核心功能已适配，但未在真机完整测试过；'
                    . '若遇问题优先检查 sql_mode 与排序规则';
            }
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
        }

        return $out;
    }

    /**
     * 建立一个独立连接用于探测（不复用 DB 单例，避免污染运行时状态）
     *
     * @return PDO|null
     */
    function env_check_pdo(): ?PDO
    {
        if (!function_exists('cfg')) {
            return null;
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            cfg('db.host'), (int) cfg('db.port'), cfg('db.name'), cfg('db.charset', 'utf8mb4')
        );
        return new PDO($dsn, (string) cfg('db.user'), (string) cfg('db.pass'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    // ------------------------------------------------------------------
    // 汇总判定
    // ------------------------------------------------------------------

    /**
     * 把自检结果归纳为「能不能装」
     *
     * @param array|null $env env_check() 的返回值，null 则自行调用
     * @return array{ok:bool, errors:string[], warns:string[]}
     */
    function env_check_summary(?array $env = null): array
    {
        if ($env === null) {
            $env = env_check();
        }

        $errors = [];
        $warns  = [];

        // ---- PHP ----
        if (!$env['php']['ok']) {
            $errors[] = sprintf('PHP %s 低于要求的 %s', $env['php']['version'], $env['php']['need']);
        }

        // ---- 必需扩展 ----
        $required = ['pdo' => '数据库访问', 'pdo_mysql' => 'MySQL 驱动'];
        foreach ($required as $ext => $why) {
            if (empty($env['extensions'][$ext])) {
                $errors[] = '缺少 ' . $ext . ' 扩展（' . $why . '必需）';
            }
        }

        // ---- 建议扩展 ----
        $optWhy = [
            'mbstring' => '中文长度与截断处理会不准',
            'json'     => '投稿数据序列化会失败',
            'session'  => '登录功能不可用',
        ];
        foreach ($optWhy as $ext => $why) {
            if (empty($env['extensions'][$ext])) {
                $warns[] = '未加载 ' . $ext . ' 扩展（' . $why . '）';
            }
        }

        // ---- 数据库 ----
        $db = $env['db'];
        if (!empty($db['error'])) {
            $errors[] = '数据库连接失败：' . $db['error'];
        }
        foreach (($db['notes'] ?? []) as $n) {
            $warns[] = $n;
        }

        return [
            'ok'     => empty($errors),
            'errors' => $errors,
            'warns'  => $warns,
        ];
    }

    // ------------------------------------------------------------------
    // 渲染
    // ------------------------------------------------------------------

    /**
     * 渲染成 HTML 表格（install.php 用）
     *
     * @param array|null $env env_check() 的返回值，null 则自行调用
     * @return string
     */
    function env_check_html(?array $env = null): string
    {
        if ($env === null) {
            $env = env_check();
        }
        $s    = env_check_summary($env);
        $mark = function ($ok) {
            return $ok
                ? '<span style="color:#059669;font-weight:600">✓</span>'
                : '<span style="color:#dc2626;font-weight:600">✗</span>';
        };

        $html = '<table class="env-tbl">';

        // PHP
        $php = $env['php'];
        $html .= '<tr><td>PHP 版本</td><td>'
            . $mark($php['ok']) . ' ' . h((string) $php['version'])
            . ' <span class="muted">（要求 ≥ ' . h((string) $php['need']) . '，支持到 8.x）</span>'
            . ($php['note'] !== '' ? '<br><span class="muted small">' . h($php['note']) . '</span>' : '')
            . '</td></tr>';

        // 扩展
        foreach ($env['extensions'] as $ext => $loaded) {
            $isRequired = in_array($ext, ['pdo', 'pdo_mysql'], true);
            $html .= '<tr><td>扩展 ' . h((string) $ext) . '</td><td>'
                . $mark($loaded)
                . ($isRequired ? '' : ' <span class="muted small">（建议）</span>')
                . '</td></tr>';
        }

        // 数据库
        $db = $env['db'];
        $html .= '<tr><td>数据库</td><td>' . $mark(!empty($db['connected'])) . ' MySQL</td></tr>';

        if (!empty($db['connected'])) {
            $html .= '<tr><td>数据库版本</td><td>' . h((string) $db['server_version']) . '</td></tr>';
            $html .= '<tr><td>连接排序规则</td><td>'
                . h((string) ($db['connection_collation'] ?: '—'))
                . ' <span class="muted small">（当前连接实际使用）</span></td></tr>';
            $html .= '<tr><td>库默认排序规则</td><td>'
                . h((string) $db['default_collation'])
                . ' <span class="muted small">（建表锁定 utf8mb4_unicode_ci）</span></td></tr>';
            $html .= '<tr><td>STRICT 模式</td><td>'
                . $mark(!empty($db['strict_enabled']))
                . ' <span class="muted small">（关闭时超长字段会被静默截断）</span></td></tr>';
            $html .= '<tr><td>sql_mode</td><td>'
                . '<span class="mono small">' . h((string) $db['sql_mode']) . '</span></td></tr>';
            $html .= '<tr><td>时区</td><td>' . h((string) $db['time_zone']) . '</td></tr>';
        }

        $html .= '</table>';

        // 提示 / 阻断
        if ($s['warns']) {
            $html .= '<div class="alert warn" style="margin-top:12px"><b>提示（不阻断安装）</b>'
                . '<ul style="margin:6px 0 0 18px;padding:0">';
            foreach ($s['warns'] as $w) {
                $html .= '<li>' . h($w) . '</li>';
            }
            $html .= '</ul></div>';
        }
        if ($s['errors']) {
            $html .= '<div class="alert err" style="margin-top:12px"><b>必须解决（否则无法正常使用）</b>'
                . '<ul style="margin:6px 0 0 18px;padding:0">';
            foreach ($s['errors'] as $e) {
                $html .= '<li>' . h($e) . '</li>';
            }
            $html .= '</ul></div>';
        }

        return $html;
    }
}
