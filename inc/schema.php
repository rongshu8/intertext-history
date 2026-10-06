<?php
/**
 * 建表语句（MySQL）
 * ---------------------------------------------------------------------------
 * 数据模型核心思想：
 *
 *   dynasties  中国历史分期（夏商周 / 秦汉 / ... ）—— 时间主轴的骨架
 *      │
 *      ├── cn_events      中国历史节点（主轴上的「锚点」，页面的入口）
 *      │      │
 *      │      └── relations  中外联动（哪些世界事件和这个节点存在因果/呼应）
 *      │
 *      └── world_events   世界大事（战争/发明/出版/新闻…）—— 按 year 独立存储，
 *                         节点详情页按 ±SYNC_WINDOW 年窗口检索出来并列展示
 *
 * 年份统一用「带符号整数」：公元前 221 年记为 -221，公元 2026 年记为 2026。
 * 好处是可以直接做数值区间比较，不需要在 SQL 里处理公元/公元前两套逻辑。
 *
 * 版本兼容（MySQL 5.5 ~ 8.4）：
 *   - 时间列一律 DATETIME NULL，由应用层写 SQL_NOW。
 *     原因：DATETIME DEFAULT CURRENT_TIMESTAMP 是 5.6+ 才有；
 *     而 TIMESTAMP 自动初始化在 5.5 又只能有一列。两边统一用应用层写入。
 *   - 显式 COLLATE=utf8mb4_unicode_ci。8.0 的 utf8mb4 默认排序规则是
 *     utf8mb4_0900_ai_ci，5.5 是 utf8mb4_general_ci，两者中文排序结果不同，
 *     不锁定的话同一份数据在不同版本上 ORDER BY 顺序会变。
 *   - 显式 ENGINE=InnoDB。不写会落到服务器默认引擎（可能是 MyISAM，
 *     不支持事务）。
 */

require_once __DIR__ . '/../config.php';

final class Schema
{
    /** 事件分类（固定字典，后台可增删） */
    public static function categories(): array
    {
        return [
            ['slug' => 'politics',  'name' => '政治制度', 'color' => '#4c6ef5'],
            ['slug' => 'war',       'name' => '战争军事', 'color' => '#e03131'],
            ['slug' => 'science',   'name' => '科技发明', 'color' => '#0ca678'],
            ['slug' => 'literature','name' => '文学出版', 'color' => '#7048e8'],
            ['slug' => 'culture',   'name' => '艺术人文', 'color' => '#f76707'],
            ['slug' => 'music',     'name' => '音乐戏曲', 'color' => '#d6336c'],
            ['slug' => 'philosophy','name' => '思想哲学', 'color' => '#862e9c'],
            ['slug' => 'religion',  'name' => '宗教信仰', 'color' => '#c2255c'],
            ['slug' => 'explore',   'name' => '探索地理', 'color' => '#1098ad'],
            ['slug' => 'economy',   'name' => '经济金融', 'color' => '#2f9e44'],
            ['slug' => 'society',   'name' => '社会民生', 'color' => '#495057'],
            ['slug' => 'medicine',  'name' => '医药卫生', 'color' => '#20c997'],
            ['slug' => 'astronomy', 'name' => '天文历法', 'color' => '#4263eb'],
            ['slug' => 'education', 'name' => '教育制度', 'color' => '#f59f00'],
            ['slug' => 'architecture','name' => '建筑工程', 'color' => '#845ef7'],
            ['slug' => 'disaster',  'name' => '灾害疫情', 'color' => '#868e96'],
        ];
    }

    /** 世界区域（用于节点详情页的世界大事分组） */
    public static function regions(): array
    {
        return [
            ['slug' => 'east_asia',   'name' => '东亚',         'emoji' => '🏯'],
            ['slug' => 'southeast_asia','name' => '东南亚',     'emoji' => '🛕'],
            ['slug' => 'south_asia',  'name' => '南亚',         'emoji' => '🕉'],
            ['slug' => 'west_asia',   'name' => '中东与西亚',   'emoji' => '🕌'],
            ['slug' => 'central_asia','name' => '中亚与草原',   'emoji' => '🐎'],
            ['slug' => 'europe',      'name' => '欧洲',         'emoji' => '🏛'],
            ['slug' => 'north_america','name' => '北美',        'emoji' => '🗽'],
            ['slug' => 'latam',       'name' => '拉丁美洲',     'emoji' => '🌎'],
            ['slug' => 'africa',      'name' => '非洲',         'emoji' => '🌍'],
            ['slug' => 'oceania',     'name' => '大洋洲与极地', 'emoji' => '🐧'],
            ['slug' => 'global',      'name' => '全球',         'emoji' => '🌐'],
        ];
    }

    /**
     * 主键定义
     *
     * UNSIGNED：节点 id 不可能为负，unsigned 让索引更小更快。
     */
    private static function pk(): string
    {
        return 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
    }

    /**
     * 变长字符串列
     *
     * utf8mb4 下一个字符最多 4 字节，所以 VARCHAR(n) 实际占 4n 字节。
     * InnoDB 单个索引键上限 767 字节（large_prefix 关闭时），
     * 因此**只有需要加索引的列**才受 191 字符限制；普通列可以更长。
     */
    private static function str(int $len): string
    {
        return "VARCHAR($len)";
    }

    /**
     * 时间列
     *
     * 统一 DATETIME NULL + 应用层写入：
     *   - DATETIME DEFAULT CURRENT_TIMESTAMP 是 MySQL 5.6+ 才有
     *   - TIMESTAMP 自动初始化在 5.5 又只能有一列
     *   - 应用层统一写 SQL_NOW，两边行为完全一致
     * DATETIME 优于 TIMESTAMP：TIMESTAMP 有 2038 年上限且受时区转换影响。
     */
    private static function now(): string
    {
        return 'DATETIME NULL';
    }

    /**
     * 表级选项
     *
     * ENGINE 不写，MySQL 5.5/8.0 可能落到 MyISAM（不支持事务）；
     * COLLATE 必须写 —— 8.0 的 utf8mb4 默认是 utf8mb4_0900_ai_ci，
     * 5.5 是 utf8mb4_general_ci，两者中文排序结果不同，
     * 不锁定的话同一份数据在不同版本上 ORDER BY 顺序会变。
     * utf8mb4_unicode_ci 从 5.5 到 8.4 都存在，是唯一的安全交集。
     */
    private static function tableOpts(): string
    {
        return ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    /**
     * 全部建表语句
     * @return array<string,string>  表名 => DDL
     */
    public static function tables(): array
    {
        $t    = [];
        $pk   = self::pk();
        $now  = self::now();
        $opt  = self::tableOpts();

        // ---------- 朝代 / 历史分期 ----------
        $t['dynasties'] = "CREATE TABLE IF NOT EXISTS dynasties (
            {$pk},
            name       " . self::str(64) . " NOT NULL,
            slug       " . self::str(64) . " NOT NULL,
            start_year INT NOT NULL DEFAULT -2070,
            end_year   INT NOT NULL DEFAULT 1949,
            color      " . self::str(16) . " NOT NULL DEFAULT '#4c6ef5',
            summary    TEXT NULL,
            sort       INT NOT NULL DEFAULT 0,
            created_at {$now}
        )" . $opt;

        // ---------- 中国历史节点 ----------
        $t['cn_events'] = "CREATE TABLE IF NOT EXISTS cn_events (
            {$pk},
            dynasty_id INT UNSIGNED NOT NULL,
            title      " . self::str(160) . " NOT NULL,
            year       INT NOT NULL,
            year_end   INT NULL,
            month      INT NULL,
            day        INT NULL,
            category   " . self::str(24) . " NOT NULL DEFAULT 'politics',
            place      " . self::str(120) . " NULL,
            summary    " . self::str(500) . " NULL,
            detail     TEXT NULL,
            figures    " . self::str(255) . " NULL,
            importance INT NOT NULL DEFAULT 3,
            is_key     INT NOT NULL DEFAULT 0,
            created_at {$now}
        )" . $opt;

        // ---------- 世界大事 ----------
        $t['world_events'] = "CREATE TABLE IF NOT EXISTS world_events (
            {$pk},
            title      " . self::str(200) . " NOT NULL,
            year       INT NOT NULL,
            year_end   INT NULL,
            month      INT NULL,
            day        INT NULL,
            region     " . self::str(24) . " NOT NULL DEFAULT 'global',
            category   " . self::str(24) . " NOT NULL DEFAULT 'politics',
            place      " . self::str(120) . " NULL,
            summary    " . self::str(500) . " NULL,
            detail     TEXT NULL,
            figures    " . self::str(255) . " NULL,
            importance INT NOT NULL DEFAULT 3,
            source     " . self::str(160) . " NULL,
            created_at {$now}
        )" . $opt;

        // ---------- 中外联动 ----------
        $t['relations'] = "CREATE TABLE IF NOT EXISTS relations (
            {$pk},
            cn_event_id    INT UNSIGNED NOT NULL,
            world_event_id INT UNSIGNED NOT NULL,
            relation_type  " . self::str(24) . " NOT NULL DEFAULT 'echo',
            note           " . self::str(500) . " NULL,
            created_at {$now}
        )" . $opt;

        // ---------- 分类字典 ----------
        $t['categories'] = "CREATE TABLE IF NOT EXISTS categories (
            {$pk},
            slug  " . self::str(24) . " NOT NULL,
            name  " . self::str(48) . " NOT NULL,
            color " . self::str(16) . " NOT NULL DEFAULT '#4c6ef5',
            sort  INT NOT NULL DEFAULT 0
        )" . $opt;

        // ---------- 区域字典 ----------
        $t['regions'] = "CREATE TABLE IF NOT EXISTS regions (
            {$pk},
            slug  " . self::str(24) . " NOT NULL,
            name  " . self::str(48) . " NOT NULL,
            emoji " . self::str(8) . " NULL,
            sort  INT NOT NULL DEFAULT 0
        )" . $opt;

        // ---------- 联动关系类型字典 ----------
        $t['relation_types'] = "CREATE TABLE IF NOT EXISTS relation_types (
            {$pk},
            slug  " . self::str(24) . " NOT NULL,
            name  " . self::str(48) . " NOT NULL,
            descr " . self::str(160) . " NULL,
            sort  INT NOT NULL DEFAULT 0
        )" . $opt;

        // ---------- 用户（管理员 + 注册用户）----------
        // role:  admin=管理员（可进后台、可审核） / user=注册用户（只能投稿）
        // email:  注册时填，用于联系与找回
        $t['users'] = "CREATE TABLE IF NOT EXISTS users (
            {$pk},
            username     " . self::str(48) . " NOT NULL,
            password     " . self::str(255) . " NOT NULL,
            display_name " . self::str(48) . " NULL,
            email        " . self::str(160) . " NULL,
            role         " . self::str(16) . " NOT NULL DEFAULT 'user',
            status       " . self::str(16) . " NOT NULL DEFAULT 'active',
            last_login   DATETIME NULL,
            created_at   {$now}
        )" . $opt;

        // ---------- 用户投稿（待审核内容）----------
        // 设计要点：投稿内容**独立存放**，不直接写进 cn_events / world_events。
        // 审核通过时才由 admin/review.php 搬运进正式表，
        // 这样未审核内容绝不会出现在前台，也无需在前台查询里到处加 status 过滤。
        //
        // kind:   cn_node=中国节点 / world_event=世界大事 / relation=中外联动
        // status: pending=待审 / approved=已通过 / rejected=已驳回 / withdrawn=用户撤回
        // payload:JSON 字符串，结构随 kind 不同（见 user/submit.php 的表单字段）
        $t['submissions'] = "CREATE TABLE IF NOT EXISTS submissions (
            {$pk},
            user_id       INT UNSIGNED NOT NULL,
            kind          " . self::str(20) . " NOT NULL,
            payload       TEXT NOT NULL,
            status        " . self::str(16) . " NOT NULL DEFAULT 'pending',
            review_note   " . self::str(500) . " NULL,
            reviewer_id   INT UNSIGNED NULL,
            reviewed_at   DATETIME NULL,
            target_id     INT UNSIGNED NULL,
            created_at    {$now},
            updated_at    {$now}
        )" . $opt;
        // 注：DDL 字符串里不要写注释（// 或 #）——
        // 整段会被原样送进数据库执行，MySQL 会报语法错误。
        // 字段含义写在 PHP 注释里，不要混进 SQL。

        return $t;
    }

    /**
     * 索引定义清单。
     *
     * 第三个元素只放**纯列定义**（如 "(year)"、"(dynasty_id, year)"），
     * 不带 ON、不带表名 —— 由 createIndex() 统一拼装完整语句，
     * 避免调用方各自拼串导致 UNIQUE 位置或表名重复出错。
     *
     * 语法要点：`CREATE [UNIQUE] INDEX name [IF NOT EXISTS] ON table (cols)`，
     * UNIQUE 在 INDEX 之前，IF NOT EXISTS 在索引名之后。
     *
     * @return array<int, array{0:string,1:string,2:string}> [表名, 索引名, 列定义]
     */
    public static function indexes(): array
    {
        return [
            // 普通索引
            ['cn_events',    'idx_cn_year',    '(year)'],
            ['cn_events',    'idx_cn_dynasty', '(dynasty_id, year)'],
            ['cn_events',    'idx_cn_cat',     '(category)'],
            ['world_events', 'idx_we_year',    '(year)'],
            ['world_events', 'idx_we_region',  '(region, year)'],
            ['world_events', 'idx_we_cat',     '(category, year)'],
            ['relations',    'idx_rel_cn',     '(cn_event_id)'],
            ['relations',    'idx_rel_we',     '(world_event_id)'],
            // 投稿审核
            ['submissions',  'idx_sub_status', '(status, kind)'],
            ['submissions',  'idx_sub_user',   '(user_id, created_at)'],
            // 用户名唯一：注册时防重名（并发下由数据库兜底，不只靠应用层 SELECT）
            ['users',        'uq_user_name',  '(username)', true],
            // 唯一索引（字典表用 slug 去重）
            ['dynasties',      'uq_dyn_slug', '(slug)', true],
            ['categories',     'uq_cat_slug', '(slug)', true],
            ['regions',        'uq_reg_slug', '(slug)', true],
            ['relation_types', 'uq_rt_slug',  '(slug)', true],
        ];
    }

    /**
     * 建索引（自动判存）
     *
     * MySQL 不支持 `CREATE INDEX IF NOT EXISTS`（那是 SQLite 语法），
     * 所以先查 information_schema 判存再决定是否执行。
     *
     * @param string $table  表名
     * @param string $name   索引名
     * @param string $cols   纯列定义，如 "(year)"
     * @param bool   $unique 是否唯一索引
     */
    public static function createIndex(string $table, string $name, string $cols, bool $unique = false): void
    {
        // 规整列定义：容忍 "UNIQUE (col)" / "table (col)" 等写法
        $s = trim($cols);
        if (stripos($s, 'UNIQUE') === 0) {
            $unique = true;
            $s = trim(substr($s, 6));
        }
        if (stripos($s, 'ON ') === 0) {
            $s = trim(substr($s, 3));
        }
        if (!preg_match('/^\s*\(/', $s)) {
            $s = preg_match('/(\(.*\))$/s', $s, $m) ? $m[1] : '(id)';
        }

        // 索引键长检查：utf8mb4 下每字符最多 4 字节，InnoDB 单键上限 767 字节
        //（large_prefix 关闭时，5.5/5.6/5.7 默认）。超限会在建索引时报 1071，
        // 不如提前拦下并给出可操作的提示。
        if ($unique) {
            $limit = 767;
            try {
                // large_prefix 开启时上限提升到 3072 字节（MySQL 5.7+ / 8.x）
                $lp = DB::fetchCol("SELECT @@SESSION.innodb_large_prefix");
                if ($lp !== null && (int) $lp === 1) {
                    $limit = 3072;
                }
            } catch (Throwable $e) {
                // 变量不存在（MySQL 8.0 已移除该变量），按 767 保守处理
            }

            $colList = trim($s, '()');
            $colArr  = array_map('trim', explode(',', $colList));
            $total   = 0;
            foreach ($colArr as $col) {
                if (!preg_match('/^([a-z_][a-z0-9_]*)/i', $col, $m)) {
                    continue;
                }
                // 从 DDL 反查该列的实际类型长度；查不到就按 191 字符保守算
                $width = 191 * 4;
                try {
                    $ddl = self::tables()[$table] ?? '';
                    if (preg_match('/\b' . preg_quote($m[1], '/') . '\s+(VAR)?CHAR\((\d+)\)/i', $ddl, $dm)) {
                        $width = (int) $dm[2] * 4;
                    }
                } catch (Throwable $e) {
                    // DDL 取不到就用保守值
                }
                $total += $width;
            }
            if ($total > $limit) {
                throw new RuntimeException(sprintf(
                    '唯一索引 %s(%s) 的键长约 %d 字节，超过 InnoDB 上限 %d 字节。'
                    . '请缩短列宽，或改用普通索引并由应用层保证唯一。',
                    $name, $colList, $total, $limit
                ));
            }
        }

        $exists = DB::fetchCol(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
            [$table, $name]
        );
        if ($exists) {
            return;
        }
        $uniqKw = $unique ? 'UNIQUE ' : '';
        DB::exec("CREATE {$uniqKw}INDEX {$name} ON {$table} {$s}");
    }
}
