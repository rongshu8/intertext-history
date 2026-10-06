<?php
/**
 * 通用工具函数
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';

// ===========================================================================
// 年份处理
// ===========================================================================

/**
 * 把带符号年份格式化成中文可读文本。
 * -221 => 公元前 221 年 ； 0 => 公元元年； 2026 => 2026 年
 */
function fmt_year(?int $year, bool $withYear = true): string
{
    if ($year === null) {
        return '';
    }
    $suffix = $withYear ? ' 年' : '';
    if ($year < 0) {
        return '公元前 ' . abs($year) . $suffix;
    }
    if ($year === 0) {
        return '公元元年';
    }
    return $year . $suffix;
}

/**
 * 节点 / 事件的年份区间文本，如「公元前 221 年」「1840—1860 年」
 */
function fmt_year_range(?int $start, ?int $end = null, bool $withYear = true): string
{
    if ($start === null) {
        return '';
    }
    if ($end === null || $end === $start) {
        return fmt_year($start, $withYear);
    }
    return fmt_year($start, false) . '—' . fmt_year($end, $withYear);
}

/** 事件排序用：区间事件的排序键取起始年 */
function event_sort_key(array $row): int
{
    return (int) ($row['year'] ?? 0);
}

/** 精确日期文本（有月日则显示） */
function fmt_date(array $row): string
{
    $y = fmt_year_range((int) $row['year'], isset($row['year_end']) ? (int) $row['year_end'] : null);
    if (!empty($row['month'])) {
        $m = (int) $row['month'];
        $d = !empty($row['day']) ? (int) $row['day'] : null;
        $md = $d ? $m . ' 月 ' . $d . ' 日' : $m . ' 月';
        // 公元前的日期用「公元前」表述更自然
        $y = (int) $row['year'] < 0 ? '公元前 ' . abs((int) $row['year']) . ' 年' : $y;
        return $y . $md;
    }
    return $y;
}

// ===========================================================================
// 输出安全
// ===========================================================================

/** HTML 转义，统一走这里避免漏掉某个 echo */
function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 多行文本转义（保留换行） */
function h_multiline(?string $s): string
{
    return nl2br(h($s), false);
}

// 注：URL 拼接函数（link_to / alink / asset）统一在 inc/bootstrap.php 定义，
// 依赖 site_base()。此处不再定义，避免两处实现漂移。

/** 当前请求的 GET 参数（已 trim） */
function q(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

/** 当前请求的 GET 整数参数 */
function qi(string $key, int $default = 0): int
{
    $v = $_GET[$key] ?? null;
    return is_numeric($v) ? (int) $v : $default;
}

/** POST 字符串参数 */
function p(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

/** POST 整数参数 */
function pint(string $key, int $default = 0): int
{
    $v = $_POST[$key] ?? null;
    return is_numeric($v) ? (int) $v : $default;
}

// ===========================================================================
// CSRF
// ===========================================================================

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check(): bool
{
    $sent = $_POST['_csrf'] ?? '';
    return is_string($sent) && $sent !== '' && hash_equals(csrf_token(), $sent);
}

// ===========================================================================
// 会话 / 鉴权
// ===========================================================================

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    security_headers();
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $https,
        'samesite' => 'Lax',
    ]);
    session_name((string) cfg('admin.session', 'ht_admin'));
    session_start();
}

/**
 * 安全响应头
 *
 * 由 start_session() 统一发出，保证每个入口页都带上：
 *   - X-Frame-Options: SAMEORIGIN  禁止被 iframe 嵌套，防点击劫持
 *   - X-Content-Type-Options: nosniff  禁止浏览器猜测 MIME
 *   - Referrer-Policy            跨站跳转时不泄露完整 URL
 *   - Permissions-Policy         关掉本站用不到的敏感设备能力
 *
 * CSP 未启用：页面大量使用内联样式与内联脚本（confiirm/时间轴交互），
 * 贸然上 strict CSP 会直接白屏，收益不抵风险。
 */
function security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
}

/**
 * 登录尝试限流
 *
 * 无此保护时，登录接口就是可无限爆破的口子 —— 尤其本项目
 * admin 账号密码曾公开在对话里，必须有暴力破解的兜底。
 *
 * 数据存在 Session 里（同一会话内计数）。攻击者每次换 cookie
 * 即可绕过，但配合下面的「账号维度」由调用方配合 IP 使用；
 * 更强的方案需要 Redis / 数据库计数。
 *
 * @return bool true=允许尝试；false=已被限流
 */
function login_rate_limit(string $bucket, int $maxAttempts = 5, int $windowSec = 300): bool
{
    start_session();
    $key = '_rl_' . $bucket;
    $now = time();
    $rec = $_SESSION[$key] ?? ['n' => 0, 't' => $now];

    if ($now - (int) $rec['t'] > $windowSec) {
        $rec = ['n' => 0, 't' => $now];   // 窗口已过，重置
    }
    $_SESSION[$key] = $rec;

    return (int) $rec['n'] < $maxAttempts;
}

/** 记录一次失败尝试 */
function login_rate_hit(string $bucket): void
{
    start_session();
    $key = '_rl_' . $bucket;
    if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key])) {
        $_SESSION[$key] = ['n' => 0, 't' => time()];
    }
    $_SESSION[$key]['n'] = (int) $_SESSION[$key]['n'] + 1;
}

/** 登录成功后清除计数 */
function login_rate_clear(string $bucket): void
{
    unset($_SESSION['_rl_' . $bucket]);
}

/** 剩余可尝试次数（用于提示） */
function login_rate_left(string $bucket, int $maxAttempts = 5): int
{
    start_session();
    $rec = $_SESSION['_rl_' . $bucket] ?? ['n' => 0, 't' => time()];
    if (time() - (int) $rec['t'] > 300) {
        return $maxAttempts;
    }
    return max(0, $maxAttempts - (int) $rec['n']);
}

function is_logged_in(): bool
{
    start_session();
    return !empty($_SESSION['uid']);
}

function current_user(): ?array
{
    if (!is_logged_in()) {
        return null;
    }
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = DB::fetchOne('SELECT * FROM users WHERE id = ?', [(int) $_SESSION['uid']]);
    if (!$cache) {
        unset($_SESSION['uid']);
        $cache = null;
    }
    return $cache;
}

/** 是否为管理员（可进后台、可审核投稿） */
function is_admin(): bool
{
    $u = current_user();
    return $u !== null && ($u['role'] ?? 'user') === 'admin';
}

/** 账号是否被禁用 */
function is_banned(): bool
{
    $u = current_user();
    return $u !== null && ($u['status'] ?? 'active') === 'banned';
}

/**
 * 需要登录。未登录时按来源分流：
 *   后台页面 → 回登录页
 *   前台页面 → 回登录页（带 return 便于跳回）
 */
function require_login(): void
{
    if (!is_logged_in()) {
        // 注意：这里必须用 base_path() 内联拼接，不能用 bootstrap 的 alink()
        // （helpers 被 bootstrap 加载，反向调用会顺序耦合）
        header('Location: ' . base_path() . '/admin/login.php');
        exit;
    }
    if (is_banned()) {
        // 账号被禁用：清会话并退回登录页
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        header('Location: ' . base_path() . '/admin/login.php?err=banned');
        exit;
    }
}

/**
 * 需要管理员身份。注册用户访问后台时跳回用户中心。
 */
function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        $_SESSION['_flash'][] = ['msg' => '该页面仅管理员可访问。', 'type' => 'warn'];
        header('Location: ' . base_path() . '/user/index.php');
        exit;
    }
}

/**
 * 站点根路径前缀（内联实现，不依赖 bootstrap.php，避免循环 require）
 *
 * 根目录部署 → ''；子目录 /history/ 部署 → '/history'
 */
function base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    // SCRIPT_NAME 形如 /admin/login.php、/user/submit.php、/history/node.php、/index.php
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $parts  = array_values(array_filter(explode('/', $script), 'strlen'));

    // 剥掉文件名本身（index.php / node.php / login.php …）
    if (!empty($parts)) {
        array_pop($parts);
    }

    // 再从后往前剥掉「应用内部目录」（admin、user 等），剩下的才是部署前缀。
    //
    // 不能直接用 dirname()：站点部署在根目录时，/admin/login.php 的 dirname
    // 是 /admin，会被误当成部署前缀，使 CSS 变成 /admin/assets/... 而 404。
    // 判据是「这段路径属于应用自身」——即已知的内部目录名。
    //
    // 用倒序索引而非 end()/array_pop()：end() 会移动内部指针，
    // 在多轮循环里行为不可靠。
    $internal = ['admin', 'user'];
    for ($i = count($parts) - 1; $i >= 0; $i--) {
        if (in_array(strtolower($parts[$i]), $internal, true)) {
            array_splice($parts, $i, 1);
        } else {
            break;
        }
    }

    $base = $parts ? '/' . implode('/', $parts) : '';
    return $base;
}

// ===========================================================================
// 字典缓存
// ===========================================================================

/** 分类字典：slug => ['name','color'] */
function dict_categories(): array
{
    static $d = null;
    if ($d === null) {
        $d = [];
        foreach (DB::fetchAll('SELECT * FROM categories ORDER BY sort, id') as $r) {
            $d[$r['slug']] = ['name' => $r['name'], 'color' => $r['color']];
        }
    }
    return $d;
}

/** 区域字典：slug => ['name','emoji'] */
function dict_regions(): array
{
    static $d = null;
    if ($d === null) {
        $d = [];
        foreach (DB::fetchAll('SELECT * FROM regions ORDER BY sort, id') as $r) {
            $d[$r['slug']] = ['name' => $r['name'], 'emoji' => $r['emoji']];
        }
    }
    return $d;
}

/** 联动类型字典：slug => ['name','descr'] */
function dict_relation_types(): array
{
    static $d = null;
    if ($d === null) {
        $d = [];
        foreach (DB::fetchAll('SELECT * FROM relation_types ORDER BY sort, id') as $r) {
            $d[$r['slug']] = ['name' => $r['name'], 'descr' => $r['descr']];
        }
    }
    return $d;
}

/** 朝代列表（按 sort 排序） */
function all_dynasties(): array
{
    static $d = null;
    if ($d === null) {
        $d = DB::fetchAll('SELECT * FROM dynasties ORDER BY sort, start_year');
    }
    return $d;
}

function dynasty_by_id(int $id): ?array
{
    foreach (all_dynasties() as $d) {
        if ((int) $d['id'] === $id) {
            return $d;
        }
    }
    return null;
}

/** 分类显示名 */
function cat_name(string $slug): string
{
    $d = dict_categories();
    return $d[$slug]['name'] ?? $slug;
}

function cat_color(string $slug): string
{
    $d = dict_categories();
    return $d[$slug]['color'] ?? '#868e96';
}

function region_name(string $slug): string
{
    $d = dict_regions();
    return $d[$slug]['name'] ?? $slug;
}

function region_emoji(string $slug): string
{
    $d = dict_regions();
    return $d[$slug]['emoji'] ?? '🌐';
}

// ===========================================================================
// 业务查询
// ===========================================================================

/**
 * 中国节点列表（JOIN 朝代）
 * @param array{dynasty?:int,category?:string,q?:string,key?:bool,limit?:int,offset?:int} $opt
 */
function list_cn_events(array $opt = []): array
{
    $where = ['1=1'];
    $args  = [];

    if (!empty($opt['dynasty'])) {
        $where[] = 'e.dynasty_id = ?';
        $args[]  = (int) $opt['dynasty'];
    }
    if (!empty($opt['category'])) {
        $where[] = 'e.category = ?';
        $args[]  = $opt['category'];
    }
    if (!empty($opt['key'])) {
        $where[] = 'e.is_key = 1';
    }
    if (!empty($opt['q'])) {
        $where[] = '(e.title LIKE ? OR e.summary LIKE ? OR e.figures LIKE ?)';
        $like   = '%' . $opt['q'] . '%';
        $args[] = $like;
        $args[] = $like;
        $args[] = $like;
    }
    // 允许手工指定年份区间
    if (isset($opt['from_year']) && isset($opt['to_year'])) {
        $where[] = 'e.year BETWEEN ? AND ?';
        $args[]  = (int) $opt['from_year'];
        $args[]  = (int) $opt['to_year'];
    }

    $sql = 'SELECT e.*, d.name AS dynasty_name, d.color AS dynasty_color, d.sort AS dynasty_sort
            FROM cn_events e
            LEFT JOIN dynasties d ON d.id = e.dynasty_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY e.year ASC, e.month ASC, e.id ASC';

    if (!empty($opt['limit'])) {
        $sql .= ' LIMIT ' . (int) $opt['limit'] . ' OFFSET ' . (int) ($opt['offset'] ?? 0);
    }
    return DB::fetchAll($sql, $args);
}

function count_cn_events(array $opt = []): int
{
    $where = ['1=1'];
    $args  = [];
    if (!empty($opt['dynasty'])) {
        $where[] = 'dynasty_id = ?';
        $args[]  = (int) $opt['dynasty'];
    }
    if (!empty($opt['q'])) {
        $where[] = '(title LIKE ? OR summary LIKE ?)';
        $like   = '%' . $opt['q'] . '%';
        $args[] = $like;
        $args[] = $like;
    }
    return (int) DB::fetchCol(
        'SELECT COUNT(*) FROM cn_events WHERE ' . implode(' AND ', $where),
        $args
    );
}

function get_cn_event(int $id): ?array
{
    return DB::fetchOne(
        'SELECT e.*, d.name AS dynasty_name, d.color AS dynasty_color
         FROM cn_events e LEFT JOIN dynasties d ON d.id = e.dynasty_id
         WHERE e.id = ?',
        [$id]
    );
}

function get_world_event(int $id): ?array
{
    return DB::fetchOne('SELECT * FROM world_events WHERE id = ?', [$id]);
}

/**
 * 核心查询：给定中国节点的年份窗口，拉取同期世界大事
 * @param int $year 节点年份
 * @param int $yearEnd 节点结束年（可空）
 * @param array{window?:int,region?:string,category?:string,limit?:int} $opt
 */
function world_events_near(int $year, ?int $yearEnd = null, array $opt = []): array
{
    $window = (int) ($opt['window'] ?? c_sync_window());
    $from   = min($year, $yearEnd ?? $year) - $window;
    $to     = max($year, $yearEnd ?? $year) + $window;

    $where = ['(
        (year_end IS NULL AND year BETWEEN ? AND ?)
        OR (year_end IS NOT NULL AND year <= ? AND year_end >= ?)
    )'];
    $args = [$from, $to, $to, $from];

    if (!empty($opt['region'])) {
        $where[] = 'region = ?';
        $args[]  = $opt['region'];
    }
    if (!empty($opt['category'])) {
        $where[] = 'category = ?';
        $args[]  = $opt['category'];
    }

    $limit = (int) ($opt['limit'] ?? c_sync_limit());
    $sql   = 'SELECT * FROM world_events
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY year ASC, month ASC, importance DESC, id ASC
              LIMIT ' . $limit;

    return DB::fetchAll($sql, $args);
}

/** 某节点已配置的中外联动（含世界事件详情） */
function relations_of_cn(int $cnId): array
{
    return DB::fetchAll(
        'SELECT r.*, w.title AS w_title, w.year AS w_year, w.year_end AS w_year_end,
                w.region AS w_region, w.category AS w_category, w.summary AS w_summary
         FROM relations r
         LEFT JOIN world_events w ON w.id = r.world_event_id
         WHERE r.cn_event_id = ?
         ORDER BY w_year ASC, r.id ASC',
        [$cnId]
    );
}

/** 世界大事反向关联的中国节点 */
function cn_events_of_world(int $worldId): array
{
    return DB::fetchAll(
        'SELECT r.*, c.title AS c_title, c.year AS c_year, c.dynasty_id
         FROM relations r
         LEFT JOIN cn_events c ON c.id = r.cn_event_id
         WHERE r.world_event_id = ?
         ORDER BY c_year ASC',
        [$worldId]
    );
}

/** 上一期 / 下一期中国节点 */
function cn_event_neighbours(int $id): array
{
    $cur = get_cn_event($id);
    if (!$cur) {
        return ['prev' => null, 'next' => null];
    }
    $prev = DB::fetchOne(
        'SELECT * FROM cn_events WHERE (year < ? OR (year = ? AND id < ?)) ORDER BY year DESC, id DESC LIMIT 1',
        [(int) $cur['year'], (int) $cur['year'], $id]
    );
    $next = DB::fetchOne(
        'SELECT * FROM cn_events WHERE (year > ? OR (year = ? AND id > ?)) ORDER BY year ASC, id ASC LIMIT 1',
        [(int) $cur['year'], (int) $cur['year'], $id]
    );
    return ['prev' => $prev, 'next' => $next];
}

/** 每个朝代下的节点数量统计 */
function dynasty_event_counts(): array
{
    $rows = DB::fetchAll(
        'SELECT dynasty_id, COUNT(*) AS c FROM cn_events GROUP BY dynasty_id'
    );
    $out  = [];
    foreach ($rows as $r) {
        $out[(int) $r['dynasty_id']] = (int) $r['c'];
    }
    return $out;
}

/** 首页统计 */
function site_stats(): array
{
    return [
        'dynasties' => (int) DB::fetchCol('SELECT COUNT(*) FROM dynasties'),
        'cn'        => (int) DB::fetchCol('SELECT COUNT(*) FROM cn_events'),
        'world'     => (int) DB::fetchCol('SELECT COUNT(*) FROM world_events'),
        'relations' => (int) DB::fetchCol('SELECT COUNT(*) FROM relations'),
        'from_year' => (int) DB::fetchCol('SELECT MIN(year) FROM cn_events'),
        'to_year'   => (int) DB::fetchCol('SELECT MAX(year) FROM cn_events'),
    ];
}

/** 随机取一条世界大事（用于首页「随机一站」功能） */
function random_world_event(): ?array
{
    return DB::fetchOne('SELECT * FROM world_events ORDER BY RAND() LIMIT 1');
}

// ===========================================================================
// 投稿与审核
// ===========================================================================

/** 投稿类型：键 => 显示名 */
function submission_kinds(): array
{
    return [
        'cn_node'     => '中国节点',
        'world_event' => '世界大事',
        'relation'    => '中外联动',
    ];
}

/** 投稿状态：键 => [显示名, 徽章样式] */
function submission_statuses(): array
{
    return [
        'pending'   => ['待审核', 'pending'],
        'approved'  => ['已通过', 'ok'],
        'rejected'  => ['已驳回', 'err'],
        'withdrawn' => ['已撤回', 'muted'],
    ];
}

/** 当前用户是否可投稿（管理员也可投稿，但直接进后台编辑更方便） */
function can_submit(): bool
{
    return is_logged_in() && !is_banned();
}

/**
 * 投稿列表
 * @param array{user_id?:int,status?:string,kind?:string,limit?:int,offset?:int} $opt
 */
function list_submissions(array $opt = []): array
{
    $where = ['1=1'];
    $args  = [];
    if (!empty($opt['user_id'])) { $where[] = 's.user_id = ?';    $args[] = (int) $opt['user_id']; }
    if (!empty($opt['status']))  { $where[] = 's.status = ?';     $args[] = $opt['status']; }
    if (!empty($opt['kind']))    { $where[] = 's.kind = ?';       $args[] = $opt['kind']; }

    $sql = 'SELECT s.*, u.username, u.display_name
            FROM submissions s
            LEFT JOIN users u ON u.id = s.user_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY
              CASE s.status WHEN \'pending\' THEN 0 WHEN \'rejected\' THEN 1 ELSE 2 END,
              s.created_at DESC';
    if (!empty($opt['limit'])) {
        $sql .= ' LIMIT ' . (int) $opt['limit'] . ' OFFSET ' . (int) ($opt['offset'] ?? 0);
    }
    return DB::fetchAll($sql, $args);
}

function count_submissions(array $opt = []): int
{
    $where = ['1=1'];
    $args  = [];
    if (!empty($opt['user_id'])) { $where[] = 'user_id = ?'; $args[] = (int) $opt['user_id']; }
    if (!empty($opt['status']))  { $where[] = 'status = ?';  $args[] = $opt['status']; }
    if (!empty($opt['kind']))    { $where[] = 'kind = ?';    $args[] = $opt['kind']; }
    return (int) DB::fetchCol(
        'SELECT COUNT(*) FROM submissions WHERE ' . implode(' AND ', $where),
        $args
    );
}

function get_submission(int $id): ?array
{
    return DB::fetchOne(
        'SELECT s.*, u.username, u.display_name
         FROM submissions s LEFT JOIN users u ON u.id = s.user_id
         WHERE s.id = ?',
        [$id]
    );
}

/** 解码投稿内容为数组（payload 存的是 JSON） */
function submission_payload(?array $sub): array
{
    if (!$sub || empty($sub['payload'])) {
        return [];
    }
    $d = json_decode((string) $sub['payload'], true);
    return is_array($d) ? $d : [];
}

/** 投稿的一行摘要（列表里显示用） */
function submission_brief(?array $sub): string
{
    $p = submission_payload($sub);
    if (!$p) {
        return '（内容为空）';
    }
    $title = $p['title'] ?? '';
    if ($title !== '') {
        return $title;
    }
    // 联动类投稿没有单一标题，拼两端
    if (($sub['kind'] ?? '') === 'relation') {
        $a = (int) ($p['cn_event_id'] ?? 0);
        $b = (int) ($p['world_event_id'] ?? 0);
        $cn = $a ? get_cn_event($a) : null;
        $we = $b ? get_world_event($b) : null;
        return trim(($cn['title'] ?? '?') . ' ↔ ' . ($we['title'] ?? '?'));
    }
    return '（未命名）';
}

/** 某用户某状态的投稿数 */
function user_submission_counts(int $userId): array
{
    $rows = DB::fetchAll(
        'SELECT status, COUNT(*) AS c FROM submissions WHERE user_id = ? GROUP BY status',
        [$userId]
    );
    $out = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'withdrawn' => 0];
    foreach ($rows as $r) {
        $out[(string) $r['status']] = (int) $r['c'];
    }
    return $out;
}

