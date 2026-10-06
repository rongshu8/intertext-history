<?php
/**
 * 数据库访问层（PDO + MySQL）
 * ---------------------------------------------------------------------------
 * 设计要点：
 *  1. 唯一连接入口 DB::pdo()，全局单例。
 *  2. 全部查询走 fetchAll() / fetchOne() / fetchCol() / exec()，预处理防注入。
 *  3. 写操作统一走 transaction() 包裹，避免半截数据。
 *  4. 版本差异（5.5 与 8.x）在建连时统一处理，不散落到业务代码。
 *
 * 为什么只有 MySQL
 *   多用户投稿 + 管理员审核是并发写场景，SQLite 的整库写锁会成为瓶颈。
 *   2026-10-05 起移除 SQLite 支持，代码路径收敛为单驱动。
 *
 * 支持的版本：MySQL 5.5 ~ 8.4 / MariaDB 10.x
 */

require_once __DIR__ . '/../config.php';

/**
 * 可选的连接工厂注入点（测试 / 特殊环境用）
 * ---------------------------------------------------------------------------
 * 正常情况留空，DB::pdo() 会按 config 建连。
 * 若在 require 本文件之前定义 $GLOBALS['__db_connector']，
 * 则由它返回一个 PDO 实例（或抛异常），便于将来接入连接池或读写分离。
 */
$GLOBALS['__db_connector'] = $GLOBALS['__db_connector'] ?? null;

final class DB
{
    private static ?PDO $pdo = null;

    /** 已建立的会话变量（避免持久连接下重复 SET） */
    private static bool $sessionReady = false;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        // 外部注入的连接工厂优先
        $connector = $GLOBALS['__db_connector'] ?? null;
        if (is_callable($connector)) {
            self::$pdo = $connector();
            return self::$pdo;
        }

        $charset = self::pickCharset();
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            cfg('db.host'),
            (int) cfg('db.port'),
            cfg('db.name'),
            $charset
        );

        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // 关闭模拟预处理：真正的服务端预编译才能防注入，且类型转换更准
            PDO::ATTR_EMULATE_PREPARES   => false,
            // 持久连接：PHP-FPM 下每个 worker 复用一条 TCP 连接，
            // 高并发时省掉反复握手，是最有效的性能优化之一
            PDO::ATTR_PERSISTENT         => (bool) cfg('db.persistent', false),
        ];
        // PHP 8.1 起该常量标记为 deprecated（值仍有效，仅告警）。
        // 用 defined() 判断，避免在 8.1+ 产生 deprecation 噪音。
        if (defined('PDO::ATTR_STRINGIFY_FETCHES')) {
            $opts[PDO::ATTR_STRINGIFY_FETCHES] = false;
        }

        self::$pdo = new PDO($dsn, (string) cfg('db.user'), (string) cfg('db.pass'), $opts);
        self::initSession($charset);
        return self::$pdo;
    }

    /**
     * 会话级设置：逐条 try，任何一条失败都不阻断业务
     *
     * 注意「追加而非覆盖」—— 详见 initSession 内 sql_mode 处的说明。
     */
    private static function initSession(string $charset): void
    {
        if (self::$sessionReady) {
            return;
        }
        $pdo = self::$pdo;

        // ---- 字符集 ----
        // utf8mb4 在 MySQL 5.5.3+ 全版本可用，是本项目的下限要求。
        try {
            $pdo->exec("SET NAMES {$charset}");
        } catch (Throwable $e) {
            error_log('[db] SET NAMES failed: ' . $e->getMessage());
        }

        // ---- 排序规则 ----
        // 必须显式指定，否则用服务端默认值：
        //   MySQL 8.0 的 utf8mb4 默认是 utf8mb4_0900_ai_ci
        //   MySQL 5.5 的是 utf8mb4_general_ci
        // 两者对中文的排序与比较结果不同 —— 同一份数据在不同版本上
        // ORDER BY 出来的顺序会变。utf8mb4_unicode_ci 是 5.5~8.4 的安全交集。
        foreach (['utf8mb4_unicode_ci', 'utf8mb4_general_ci'] as $coll) {
            try {
                $pdo->exec("SET NAMES {$charset} COLLATE {$coll}");
                break;
            } catch (Throwable $e) {
                // 该 collation 在此版本不存在（MariaDB 常见），试下一个
            }
        }

        // ---- sql_mode：追加而非覆盖 ----
        //
        // 覆盖式写法（SET SESSION sql_mode = 'STRICT_TRANS_TABLES,...'）
        // 会把服务端原有的 NO_ZERO_DATE / ONLY_FULL_GROUP_BY 等全部丢掉。
        // 在 5.5 上影响有限，但在 8.0 上等于主动关闭了它精心设计的默认防护。
        //
        // 这里只加两件明确想要的：
        //   STRICT_TRANS_TABLES   —— 越界/截断直接报错而不是静默写坏数据
        //   NO_ENGINE_SUBSTITUTION —— 引擎不可用时报错而不是悄悄换成 MyISAM
        try {
            $pdo->exec(
                "SET SESSION sql_mode = CONCAT_WS(',', NULLIF(@@SESSION.sql_mode, ''),"
                . " 'STRICT_TRANS_TABLES', 'NO_ENGINE_SUBSTITUTION')"
            );
        } catch (Throwable $e) {
            error_log('[db] sql_mode append failed: ' . $e->getMessage());
        }

        // ---- 时区 ----
        // 影响 NOW() 的返回值与 TIMESTAMP 列的转换。
        // '+08:00' 是数字偏移，不需要 mysql.time_zone* 表，
        // 因此在未加载时区表的环境也能工作（5.5 常有这个问题）。
        try {
            $pdo->exec("SET SESSION time_zone = '+08:00'");
        } catch (Throwable $e) {
            error_log('[db] time_zone set failed: ' . $e->getMessage());
        }

        self::$sessionReady = true;
    }

    /**
     * 实际使用的字符集
     *
     * 只允许已知安全的名字，防止配置里写错导致 SET NAMES 失败。
     */
    private static function pickCharset(): string
    {
        $cs = strtolower((string) cfg('db.charset', 'utf8mb4'));
        $ok = ['utf8mb4', 'utf8', 'latin1'];
        return in_array($cs, $ok, true) ? $cs : 'utf8mb4';
    }

    /** 最近一次插入的自增 ID */
    public static function lastInsertId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * 执行写语句，返回受影响行数
     */
    public static function exec(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    /** 查询多行 */
    public static function fetchAll(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** 查询单行，无结果返回 null */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /** 查询第一行第一列标量值 */
    public static function fetchCol(string $sql, array $params = [], $default = 0)
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn(0);
        return $v === false ? $default : $v;
    }

    /**
     * 事务包裹：回调抛异常则自动回滚
     *
     * 嵌套调用（callback 内又调 transaction）直接执行内层回调，
     * 由最外层统一提交/回滚 —— MySQL 不支持真正的嵌套事务。
     */
    public static function transaction(callable $fn)
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** 表是否存在 */
    public static function tableExists(string $table): bool
    {
        try {
            return (bool) self::fetchCol(
                'SELECT table_name FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = ?',
                [$table]
            );
        } catch (Throwable $e) {
            return false;
        }
    }
}

function db(): PDO
{
    return DB::pdo();
}

/**
 * 当前时间的 SQL 片段
 *
 * 业务代码里**不要直接写 NOW()** —— 统一用这个常量或 DB::now()。
 * 之所以不内联 NOW()：DBA 常会通过全局 sql_mode 或配置改写它，
 * 集中一处便于排查，也让 DDL 生成逻辑（inc/schema.php）能引用同一份定义。
 */
if (!defined('SQL_NOW')) {
    define('SQL_NOW', 'NOW()');
}
