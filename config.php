<?php
/**
 * 全局配置
 * ---------------------------------------------------------------------------
 * 优先级：config.local.php 的返回值 > 本文件的默认值。
 * 本地配置不会被后续升级覆盖，敏感信息（数据库密码）必须只写在 config.local.php。
 *
 * 数据库：MySQL 5.5 ~ 8.4 / MariaDB 10.x（2026-10-05 起仅支持 MySQL）。
 * SQLite 支持已移除 —— 多用户投稿 + 管理员审核是并发写场景，
 * SQLite 的整库写锁会成为瓶颈。
 *
 * config.local.php 示例：
 *   <?php
 *   return [
 *       'db' => [
 *           'host' => '127.0.0.1',
 *           'port' => 3306,
 *           'name' => 'history_timeline',
 *           'user' => 'your_user',
 *           'pass' => 'your_password',
 *       ],
 *   ];
 */

// ---------------------------------------------------------------------------
// 默认配置
// ---------------------------------------------------------------------------
$defaults = [
    'site' => [
        'name'     => '互文 · 世界同期大事录',
        'tagline'  => '以中国历史节点为轴，看同一时刻世界正在发生什么',
        'timezone' => 'Asia/Shanghai',
        'debug'    => false,
    ],
    'db' => [
        'host'       => '127.0.0.1',
        'port'       => 3306,
        'name'       => 'history_timeline',
        'user'       => 'root',
        'pass'       => '',
        'charset'    => 'utf8mb4',
        // 持久连接：PHP-FPM 下复用 TCP 连接，高并发时省掉反复握手
        'persistent' => true,
    ],
    'sync' => [
        'window' => 5,    // 节点页默认时间窗口（±N 年）
        'limit'  => 120,  // 单个节点最多展示的世界大事条数
    ],
    'admin' => [
        'session' => 'ht_admin',
    ],
];

// ---------------------------------------------------------------------------
// 加载本地覆盖并递归合并
// ---------------------------------------------------------------------------
$localFile = __DIR__ . '/config.local.php';
$override  = is_file($localFile) ? (require $localFile) : [];
if (!is_array($override)) {
    $override = [];
}
unset($localFile);

$merge = function (array $base, array $over) use (&$merge): array {
    $out = $base;
    foreach ($over as $k => $v) {
        if (is_array($v) && isset($out[$k]) && is_array($out[$k])) {
            $out[$k] = $merge($out[$k], $v);
        } else {
            $out[$k] = $v;
        }
    }
    return $out;
};

$GLOBALS['__cfg_map'] = $merge($defaults, $override);
unset($defaults, $override, $merge);

// ---------------------------------------------------------------------------
// 访问器
// ---------------------------------------------------------------------------
if (!function_exists('cfg')) {
    /**
     * 读取配置项，支持点号访问：
     *   cfg('db.name')                顶层
     *   cfg('sync.window', 5)         取不到时返回默认值
     */
    function cfg(string $key, $default = null)
    {
        static $map = null;
        if ($map === null) {
            $map = $GLOBALS['__cfg_map'] ?? [];
        }
        $val = $map;
        foreach (explode('.', $key) as $seg) {
            if (!is_array($val) || !array_key_exists($seg, $val)) {
                return $default;
            }
            $val = $val[$seg];
        }
        return $val;
    }
}

function c_site_name(): string  { return (string) cfg('site.name'); }
function c_sync_window(): int  { return (int) cfg('sync.window'); }
function c_sync_limit(): int   { return (int) cfg('sync.limit'); }

// ---------------------------------------------------------------------------
// 运行时环境
// ---------------------------------------------------------------------------
date_default_timezone_set((string) cfg('site.timezone', 'Asia/Shanghai'));

// mbstring 在部分精简版 PHP 中不可用；降级保护，避免整站白屏
if (!function_exists('mb_internal_encoding')) {
    function mb_internal_encoding($enc = null) { return true; }
    function mb_strlen($s, $enc = null) { return strlen($s); }
    function mb_substr($s, $start, $len = null, $enc = null) {
        return $len === null ? substr($s, $start) : substr($s, $start, $len);
    }
    function mb_strpos($h, $n, $o = 0, $enc = null) { return strpos($h, $n, $o); }
    function mb_strtolower($s, $enc = null) { return strtolower($s); }
    function mb_convert_encoding($s, $to, $from = null) { return $s; }
    define('MB_OK', false);
} else {
    mb_internal_encoding('UTF-8');
    define('MB_OK', true);
}

if (cfg('site.debug')) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', '0');
}

// ---------------------------------------------------------------------------
// 时间戳 SQL 片段
// ---------------------------------------------------------------------------
// 定义在这里而不是 inc/db.php，因为 config.php 是最底层 —— 任何只加载
// config 而不建连的脚本（DDL 检查、自检）也需要这个常量。
//
// 业务代码里不要直接写 NOW()，统一用 SQL_NOW 或 DB::now()：
// DBA 常会通过全局配置改写它，集中一处便于排查。
if (!defined('SQL_NOW')) {
    define('SQL_NOW', 'NOW()');
}
