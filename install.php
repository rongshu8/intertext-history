<?php
/**
 * 一键安装向导
 * ---------------------------------------------------------------------------
 * 用法（全新安装）：
 *   1. 上传全部文件到服务器
 *   2. 浏览器访问 https://你的域名/  →  自动跳到这里
 *   3. 页面第一步填 MySQL 信息，点「下一步」→ 自动测连接
 *   4. 环境检测全 ✓ 后点「开始安装」，装完全自动完成
 *
 * 全程不需要手工编辑任何文件，也不需要先复制 config.local.php。
 *
 * 安全：
 *   - CSRF 令牌防跨站触发
 *   - ★ 但 CSRF 挡不住**直接访问** —— 令牌就印在页面上。
 *     所以安装完成时写 data/install.lock，锁在时本页面只读、零表单。
 *     重装需 FTP 删锁或 `php install.php --force`。
 */

// ---------------------------------------------------------------------------
// 引导段：先不连库，把「数据库还没配」这件事变成可操作的表单，
// 而不是让 bootstrap 的 PDOException 变成白屏。
// ---------------------------------------------------------------------------
require_once __DIR__ . '/inc/install_precheck.php';

$pre = precheck_collect();
$stage = $pre['stage'];   // 'dbform' | 'env' | 'locked' | 'ready'

// ---- 第一步：填数据库信息（未配置时唯一能做的事）----
if ($stage === 'dbform') {
    $posted = precheck_handle_db_form($pre);   // 按引用：校验失败要回填错误
    if ($posted === null) {
        precheck_render_db_form($pre);   // 首次进入或校验失败 → 渲染表单
        exit;
    }
    // 写入成功，接下来走环境检测
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'), true, 303);
    exit;
}

// ---- 锁定态：只读，什么都不做 ----
if ($stage === 'locked') {
    // 后面有完整的锁定态 UI（复用原有逻辑），这里只确保不连库
    define('INSTALL_PRECHECK_OK', 1);
}

// ---- 已有配置，可以正常加载应用 ----
require_once __DIR__ . '/inc/bootstrap.php';

/**
 * 供 array_filter 回调使用的表存在性检查
 */
function db_table_exists(string $t): bool
{
    return DB::tableExists($t);
}

/**
 * 安装状态检测。返回三种状态：
 *   'empty'    表都不存在，全新安装
 *   'partial'  表已建好但数据没导完（上次安装中途失败），需要继续/重装
 *   'complete' 数据完整
 */
function detect_state(): string
{
    $tables = ['users', 'cn_events', 'world_events', 'dynasties', 'categories', 'regions', 'relation_types'];
    $exists = 0;
    foreach ($tables as $t) {
        if (DB::tableExists($t)) {
            $exists++;
        }
    }
    if ($exists === 0) {
        return 'empty';
    }
    // 表在但一条数据都没有 → 中途失败
    try {
        $users = (int) DB::fetchCol('SELECT COUNT(*) FROM users');
        $cn    = (int) DB::fetchCol('SELECT COUNT(*) FROM cn_events');
    } catch (Throwable $e) {
        return 'partial';
    }
    return ($users > 0 && $cn > 0) ? 'complete' : 'partial';
}

$state     = detect_state();
$force     = isset($_GET['force']) || isset($_POST['force']);
$done      = false;
$tablesOnly = false;   // 本次只建了表，没导数据（成功页据此换文案）
$log       = [];
$errors    = [];

// ---------------------------------------------------------------------------
// ★ 安装锁 —— 已装好的站点，Web 层不得再触发任何写库动作
// ---------------------------------------------------------------------------
// 为什么必须有这个：
//   CSRF 令牌就印在页面上，而 CSRF 防的是**第三方页面跨站触发**，不是**直接访问**。
//   也就是说，任何人打开 /install.php、复制令牌、勾上「清空并重建」、提交，
//   生产库就没了 —— 一个 GET 一个 POST，不需要任何权限。
//   我自己写完这个功能后才发现：这道「保护」等于没有。
//
// 因此：安装成功后写一个锁文件，锁在时本页面只读。要重新安装，
// 两条正规途径（都需要服务器/FTP 权限，不是 Web 能碰到的）：
//   ① 删掉 data/install.lock 再访问
//   ② php install.php --force   （命令行，绕过了 Web 层）
//
// 锁文件本身不含凭据，只记录时间与指纹，用来判断「这份锁对应当前这套数据」。
function install_lock_path(): string
{
    return __DIR__ . '/data/install.lock';
}

function install_is_locked(): bool
{
    return is_file(install_lock_path());
}

function install_lock_write(string $version = ''): void
{
    $p = install_lock_path();
    $info = [
        'installed_at' => date('c'),
        'php'          => PHP_VERSION,
        'seed_sha'     => substr(sha1(implode('|', array_keys(glob(__DIR__ . '/data/seed/*.json') ?: []))), 0, 12),
        'note'         => '删除本文件即可重新用安装器覆盖安装；请勿在生产站保留可写的安装器。',
    ];
    @file_put_contents($p, json_encode($info, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    @chmod($p, 0644);
}

function install_lock_remove(): void
{
    $p = install_lock_path();
    if (is_file($p)) @unlink($p);
}

/**
 * 命令行安装的环境门槛。
 *
 * 复用 inc/env_check.php 的判据而不是另写一套 —— 两处标准不一致的话，
 * 「页面上显示可以装、命令行却拒装」比没有检查更让人困惑。
 * （env_check.php 被 install.php 在渲染时才 require，这里要提前。）
 */
function install_cli_env_ok(): bool
{
    require_once __DIR__ . '/inc/env_check.php';
    $env = env_check();
    $ok  = !empty($env['php']['ok'])
        && !empty($env['extensions']['pdo'])
        && !empty($env['extensions']['pdo_mysql'])
        && !empty($env['db']['connected']);
    if (!$ok) {
        fwrite(STDERR, "环境检测：PHP="
            . ($env['php']['ok'] ? 'OK' : $env['php']['need'])
            . '  pdo=' . (!empty($env['extensions']['pdo']) ? 'OK' : '缺')
            . '  pdo_mysql=' . (!empty($env['extensions']['pdo_mysql']) ? 'OK' : '缺')
            . '  MySQL=' . ($env['db']['connected'] ? 'OK' : $env['db']['error'])
            . "\n");
    }
    return $ok;
}

// 命令行模式：php install.php --force  →  解锁并直接覆盖安装
if (PHP_SAPI === 'cli') {
    $isForce = in_array('--force', $argv ?? [], true);
    if ($isForce) install_lock_remove();
    if ($state !== 'empty') {
        fwrite(STDOUT, "当前状态: {$state}\n");
        if (!$isForce) {
            fwrite(STDOUT, "已安装的库请用：php install.php --force\n");
            exit(1);
        }
    }
    if (!install_cli_env_ok()) {
        fwrite(STDOUT, "环境检测未通过，请先修好标红项。\n");
        exit(1);
    }
    create_tables($log, $errors);
    if (!$errors) {
        DB::transaction(function () {
            foreach (['submissions', 'relations', 'cn_events', 'world_events', 'dynasties',
                      'categories', 'regions', 'relation_types', 'users'] as $t) {
                if (DB::tableExists($t)) DB::exec("DELETE FROM `{$t}`");
            }
        });
        $log[] = '命令行强制重装：已清空旧数据';
    }
    do_install($log, $errors);
    if (!$errors) install_lock_write();
    foreach ($log as $l) fwrite(STDOUT, "  $l\n");
    foreach ($errors as $e) fwrite(STDERR, "  [错误] $e\n");
    exit($errors ? 1 : 0);
}

// Web 层：锁在 → 只读，压根不进入任何 POST 处理
$locked = install_is_locked();
if ($locked && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    http_response_code(403);
    $force = false;              // 即使伪造 force 也不生效
    $errors[] = '本站已完成安装，安装锁 data/install.lock 已生效，'
              . 'Web 层不接受任何写库请求。如需重装请通过 FTP 删除该锁文件后重新访问。';
    $locked = true;
    $_POST['action'] = '';       // 阻断后续所有 action 分支
}

// ---------------------------------------------------------------------------
// 收集种子数据
// ---------------------------------------------------------------------------
/**
 * 数据来源优先级：
 *   1. data/seed/*.json   —— 当前主格式（语言无关、可 diff、可由后台导出）
 *   2. data/seed_part*.php —— 旧格式回退，保证老的包/老的分支仍能装
 *
 * 两条路径最终归一成同一结构，所以下面的装载逻辑只需写一套。
 */
/**
 * 生成一次性管理员密码。
 * 去掉 0/O/1/l/I 等易混字符 —— 它要被人从屏幕上抄下来手打。
 * 用 random_int 而不是 rand/uniqid：前者是密码学安全的随机源。
 */
function self_dp_random_password(int $len = 16): string
{
    // 去掉易混字符 0 O 1 l I
    $chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $max   = strlen($chars) - 1;
    $out   = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $chars[random_int(0, $max)];
    }
    // 保证至少各含一个小写、大写、数字
    $sets = ['abcdefghijkmnopqrstuvwxyz', 'ABCDEFGHJKLMNPQRSTUVWXYZ', '23456789'];
    foreach ($sets as $set) {
        if (!preg_match('/[' . $set . ']/', $out)) {
            $pos = random_int(0, $len - 1);
            $out[$pos] = $set[random_int(0, strlen($set) - 1)];
        }
    }
    return $out;
}

function load_seed(): array
{
    $data = datapack_load_default();

    // 旧格式里 cn_events 用 'dynasty' 存 slug，与新格式一致，无需转换。
    // 这里只补齐装载侧必需的键，避免下游因缺键报 undefined index。
    foreach (['categories', 'regions', 'relation_types',
              'dynasties', 'cn_events', 'world_events', 'relations'] as $k) {
        if (!isset($data[$k]) || !is_array($data[$k])) {
            $data[$k] = [];
        }
    }
    return $data;
}

// ---------------------------------------------------------------------------
// 执行安装
// ---------------------------------------------------------------------------
function do_install(array &$log, array &$errors): bool
{
    try {
        // 1) 字典表
        DB::transaction(function () use (&$log) {
            $t = Schema::tables();

            // 字典：优先用数据包里的文件（这样改 data/seed/*.json 就能改安装结果），
            // 数据包缺该表时回退到 Schema，保证不会出现「有事件但没有对应分类」。
            $seed = load_seed();

            $cats = !empty($seed['categories']) ? $seed['categories'] : Schema::categories();
            $i = 0;
            foreach ($cats as $c) {
                DB::exec(
                    'INSERT INTO categories (slug, name, color, sort) VALUES (?, ?, ?, ?)',
                    [$c['slug'], $c['name'], $c['color'], isset($c['sort']) ? $c['sort'] : ($i * 10)]
                );
                $i++;
            }

            $regs = !empty($seed['regions']) ? $seed['regions'] : Schema::regions();
            $i = 0;
            foreach ($regs as $r) {
                DB::exec(
                    'INSERT INTO regions (slug, name, emoji, sort) VALUES (?, ?, ?, ?)',
                    [$r['slug'], $r['name'], $r['emoji'], isset($r['sort']) ? $r['sort'] : ($i * 10)]
                );
                $i++;
            }

            $rts = !empty($seed['relation_types']) ? $seed['relation_types'] : [
                ['slug' => 'cause',  'name' => '因果', 'descr' => '此世界事件是中国节点发生的原因或直接触发因素', 'sort' => 10],
                ['slug' => 'effect', 'name' => '影响', 'descr' => '中国节点影响了此世界事件的走向', 'sort' => 20],
                ['slug' => 'echo',   'name' => '对照', 'descr' => '二者同期发生，反映某种结构性的历史呼应', 'sort' => 30],
            ];
            $i = 0;
            foreach ($rts as $row) {
                DB::exec(
                    'INSERT INTO relation_types (slug, name, descr, sort) VALUES (?, ?, ?, ?)',
                    [$row['slug'], $row['name'], $row['descr'] ?? null, isset($row['sort']) ? $row['sort'] : ($i * 10)]
                );
                $i++;
            }

            // 2) 朝代（$seed 已在上面载入）
            $dynIdBySlug = [];
            foreach ($seed['dynasties'] as $d) {
                DB::exec(
                    'INSERT INTO dynasties (name, slug, start_year, end_year, color, summary, sort, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ' . SQL_NOW . ')',
                    [$d['name'], $d['slug'], $d['start_year'], $d['end_year'],
                     $d['color'], $d['summary'] ?? null, $d['sort']]
                );
                $dynIdBySlug[$d['slug']] = DB::lastInsertId();
            }
            $log[] = '朝代 ' . count($dynIdBySlug) . ' 条';

            // 3) 中国节点
            //
            // 逐条包 try 的原因：117 条里出错时，必须能说出是**哪一条**。
            // 不加的话用户只看到一个裸 SQLSTATE，完全无从下手。
            $cnIdByTitle = [];
            foreach ($seed['cn_events'] as $idx => $e) {
                $dynSlug = $e['dynasty'] ?? '';
                if (!isset($dynIdBySlug[$dynSlug])) {
                    throw new RuntimeException("未知朝代 slug: {$dynSlug}（节点：{$e['title']}）");
                }
                try {
                    DB::exec(
                        'INSERT INTO cn_events (dynasty_id, title, year, year_end, month, day, category, place, summary, detail, figures, importance, is_key, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, ' . SQL_NOW . ')',
                        [
                            $dynIdBySlug[$dynSlug],
                            $e['title'],
                            $e['year'],
                            $e['year_end'] ?? null,
                            $e['month'] ?? null,
                            $e['day'] ?? null,
                            $e['category'] ?? 'politics',
                            $e['place'] ?? null,
                            $e['summary'] ?? null,
                            $e['detail'] ?? null,
                            $e['figures'] ?? null,
                            $e['importance'] ?? 3,
                            !empty($e['is_key']) ? 1 : 0,
                        ]
                    );
                    $cnIdByTitle[$e['title']] = DB::lastInsertId();
                } catch (Throwable $ex) {
                    throw new RuntimeException(sprintf(
                        '中国节点第 %d 条「%s」写入失败：%s',
                        $idx + 1, $e['title'] ?? '?', $ex->getMessage()
                    ), 0, $ex);
                }
            }
            $log[] = '中国节点 ' . count($cnIdByTitle) . ' 条';

            // 4) 世界大事（669 条，同样逐条包 try）
            $weIdByTitle = [];
            foreach ($seed['world_events'] as $idx => $e) {
                try {
                    DB::exec(
                        'INSERT INTO world_events (title, year, year_end, month, day, region, category, place, summary, detail, figures, importance, source, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, ' . SQL_NOW . ')',
                        [
                            $e['title'],
                            $e['year'],
                            $e['year_end'] ?? null,
                            $e['month'] ?? null,
                            $e['day'] ?? null,
                            $e['region'] ?? 'global',
                            $e['category'] ?? 'politics',
                            $e['place'] ?? null,
                            $e['summary'] ?? null,
                            $e['detail'] ?? null,
                            $e['figures'] ?? null,
                            $e['importance'] ?? 3,
                            $e['source'] ?? null,
                        ]
                    );
                    $weIdByTitle[$e['title']] = DB::lastInsertId();
                } catch (Throwable $ex) {
                    throw new RuntimeException(sprintf(
                        '世界大事第 %d 条「%s」写入失败：%s',
                        $idx + 1, $e['title'] ?? '?', $ex->getMessage()
                    ), 0, $ex);
                }
            }
            $log[] = '世界大事 ' . count($weIdByTitle) . ' 条';

            // 5) 联动关系
            $relCount = 0;
            $missRel  = [];
            foreach ($seed['relations'] as $idx => $r) {
                $cnId = $cnIdByTitle[$r['cn_ref']] ?? null;
                $weId = $weIdByTitle[$r['world_ref']] ?? null;
                if (!$cnId || !$weId) {
                    $missRel[] = ($r['cn_ref'] ?? '?') . ' ↔ ' . ($r['world_ref'] ?? '?');
                    continue;
                }
                try {
                    DB::exec(
                        'INSERT INTO relations (cn_event_id, world_event_id, relation_type, note, created_at) VALUES (?,?,?,?, ' . SQL_NOW . ')',
                        [$cnId, $weId, $r['type'] ?? 'echo', $r['note'] ?? null]
                    );
                    $relCount++;
                } catch (Throwable $ex) {
                    throw new RuntimeException(sprintf(
                        '联动第 %d 条（%s ↔ %s）写入失败：%s', $idx + 1,
                        $r['cn_ref'] ?? '?', $r['world_ref'] ?? '?', $ex->getMessage()
                    ), 0, $ex);
                }
            }
            $log[] = '中外联动 ' . $relCount . ' 条';
            if ($missRel) {
                $log[] = '⚠ 跳过未匹配的联动 ' . count($missRel) . ' 条：' . implode('；', $missRel);
            }

            // 6) 管理员
            //    密码**随机生成**，不在代码或文档里写死。
            //    早先这里硬编码 admin/admin888 —— 那意味着每个用这份安装包
            //    装出来的站都有一组公开的默认凭据，开源后等于把后台送人。
            $adminPass = self_dp_random_password();
            DB::exec(
                'INSERT INTO users (username, password, display_name, email, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ' . SQL_NOW . ')',
                ['admin', password_hash($adminPass, PASSWORD_DEFAULT), '管理员', null, 'admin', 'active']
            );
            $GLOBALS['__admin_plain_pass'] = $adminPass;
            $log[] = '管理员 admin 已创建，随机密码见下方（只显示这一次）';
        });

        return true;
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
        return false;
    }
}

// ---------------------------------------------------------------------------
// 建表（独立于导入，方便单独重跑）
// ---------------------------------------------------------------------------
function create_tables(array &$log, array &$errors): bool
{
    try {
        // 逐表建表，出错时能立刻指出是哪张表
        foreach (Schema::tables() as $name => $ddl) {
            try {
                DB::exec($ddl);
                $log[] = "建表 {$name}";
            } catch (Throwable $e) {
                throw new RuntimeException("表 {$name}: " . $e->getMessage(), 0, $e);
            }
        }

        // 逐个建索引，同上
        $okIdx = 0;
        foreach (Schema::indexes() as $idx) {
            $table = $idx[0];
            $name  = $idx[1];
            $def   = $idx[2];
            $uniq  = (bool) ($idx[3] ?? false);
            try {
                Schema::createIndex($table, $name, $def, $uniq);
                $okIdx++;
            } catch (Throwable $e) {
                throw new RuntimeException("索引 {$name} (ON {$table}): " . $e->getMessage(), 0, $e);
            }
        }
        $log[] = "索引创建完成（{$okIdx} 个）";
        return true;
    } catch (Throwable $e) {
        $errors[] = '建表失败: ' . $e->getMessage();
        // 打印出错 SQL 便于排查
        $errors[] = '提示：若错误信息含 near "...", 说明 SQL 语法问题；'
                  . '请检查 inc/schema.php 的 indexes() 与 createIndex() 拼串。';
        return false;
    }
}

// ---------------------------------------------------------------------------
// 触发安装
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // CSRF 校验：本页面能建表、清库、导数据，必须防止被第三方页面跨站触发。
    if (!csrf_check()) {
        $errors[] = 'CSRF 校验失败，请刷新页面（Ctrl+F5）后重新提交。';
        $action  = '';
    }

    if ($action === 'install') {
        if ($state === 'complete' && !$force) {
            $errors[] = '检测到已安装完整数据。如需重装，请勾选下方的「清空并重建」选项。';
        } else {
            create_tables($log, $errors);
            if (!$errors) {
                // 需要清空时（强制重装，或上次中途失败留下残留）先清
                $needClean = $force || $state === 'partial';
                if ($needClean) {
                    DB::transaction(function () use (&$log) {
                        // submissions 必须先清（它外键引用 users，但本库未建物理外键，
                        // 顺序仍按依赖来，避免将来加约束后出错）
                        foreach (['submissions', 'relations', 'cn_events', 'world_events', 'dynasties',
                                  'categories', 'regions', 'relation_types', 'users'] as $t) {
                            if (DB::tableExists($t)) {
                                DB::exec("DELETE FROM {$t}");
                            }
                        }
                    });
                    $log[] = $force ? '已清空旧数据（force）' : '已清理上次未完成的残留数据';
                }
                do_install($log, $errors);
            }
        }
        $done = true;
        // 装完立刻上锁 —— 这一步漏了，前面那道「保护」就完全不存在
        if (!$errors && $action === 'install') {
            install_lock_write();
            $log[] = '已写入安装锁 data/install.lock（要重装请先删它）';
        }
    } elseif ($action === 'tables_only') {
        create_tables($log, $errors);
        // 记下这次只建了表 —— 成功页要给不同的提示。
        // 之前不区分，导致「仅建表」跑完也显示"进入前台"，
        // 让人以为装完了，实际数据一行都没有。
        $tablesOnly = true;
        $done = true;
    }
}

$stats = [];
if ($state !== 'empty') {
    try {
        $stats = site_stats();
    } catch (Throwable $e) {
        // ignore
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>安装 · <?= h(c_site_name()) ?></title>
<style>
  :root { --ink:#1a1a1a; --muted:#6b7280; --line:#e5e7eb; --accent:#c0392b; }
  * { box-sizing:border-box; }
  body { font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;
         margin:0; background:#f7f5f2; color:var(--ink); line-height:1.7; }
  .wrap { max-width:760px; margin:0 auto; padding:48px 24px 80px; }
  h1 { font-size:26px; margin:0 0 6px; }
  .sub { color:var(--muted); font-size:14px; margin-bottom:28px; }
  .card { background:#fff; border:1px solid var(--line); border-radius:12px; padding:24px 28px; margin-bottom:20px; }
  h2 { font-size:17px; margin:0 0 14px; }
  table { width:100%; border-collapse:collapse; font-size:14px; }
  td { padding:7px 0; border-bottom:1px solid #f3f4f6; }
  td:last-child { text-align:right; color:var(--muted); font-variant-numeric:tabular-nums; }
  .btn { display:inline-block; background:var(--accent); color:#fff; padding:11px 26px; border:none;
         border-radius:8px; font-size:15px; cursor:pointer; font-family:inherit; }
  .btn:hover { background:#a93226; }
  .btn.ghost { background:#fff; color:var(--ink); border:1px solid var(--line); }
  .ok { color:#059669; } .err { color:#dc2626; }
  .log { font-size:13px; line-height:1.9; }
  .log div { padding:2px 0; }
  code { background:#f3f4f6; padding:2px 6px; border-radius:4px; font-size:13px; }
  .warn { background:#fffbeb; border:1px solid #fcd34d; color:#92400e; padding:14px 18px; border-radius:8px; font-size:14px; margin-bottom:20px; }
  a { color:var(--accent); }
  /* env_check 渲染所需的辅助类 */
  .muted { color:var(--muted); }
  .small { font-size:12.5px; }
  .mono  { font-family:Consolas,Monaco,monospace; }
  .env-tbl { width:100%; }
  .env-tbl td:last-child { text-align:left; color:inherit; }
  .alert { border-radius:8px; font-size:14px; margin-bottom:20px; }
  .alert ul { line-height:1.8; }
  /* 安装成功后的两个去向。做成大卡片而不是文字链接：
     安装完这一刻用户最需要的是"下一步去哪"，小链接容易被忽略。 */
  .done-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-top:20px; }
  .done-card { display:flex; flex-direction:column; gap:4px; text-decoration:none;
               border:2px solid var(--ink); border-radius:10px; padding:18px 20px;
               background:#fff; color:var(--ink); transition:background .15s, color .15s; }
  .done-card strong { font-size:17px; }
  .done-card span { font-size:13px; color:var(--muted); }
  .done-card:hover { background:var(--ink); color:#fff; }
  .done-card:hover span { color:rgba(255,255,255,.75); }
  @media (max-width: 560px) { .done-grid { grid-template-columns:1fr; } }
</style>
</head>
<body>
<div class="wrap">
  <h1>互文 · 世界同期大事录</h1>
  <div class="sub">以中国历史节点为轴，看同一时刻世界正在发生什么</div>

  <div class="card">
    <h2>环境检测</h2>
    <?php
    // 所有状态下都显示：装到新环境时第一眼就要确认版本与扩展是否匹配。
    // 走统一的 env_check —— 一次看全 PHP 版本 / 扩展 / MySQL 版本 /
    // 连接排序规则 / sql_mode / STRICT 是否生效 / 索引键长上限，
    // 避免运行到一半才暴露环境问题。
    require_once __DIR__ . '/inc/env_check.php';
    echo env_check_html(env_check());
    ?>
  </div>

<?php if ($locked): ?>
  <div class="warn">
    <b>安装锁已生效，本站已完成安装。</b><br>
    为安全起见，本页面已停止全部写库功能 —— 不是靠登录或令牌，而是<b>没有任何表单可提交</b>。
    这道保护是必要的：CSRF 令牌就印在页面上，拿到它不需要任何权限，
    所以只靠令牌的「保护」对直接访问者等于不存在。
  </div>
  <div class="card">
    <h2>当前状态</h2>
    <table>
      <tr><td>中国节点</td><td><?= number_format($stats['cn'] ?? 0) ?></td></tr>
      <tr><td>世界大事</td><td><?= number_format($stats['world'] ?? 0) ?></td></tr>
      <tr><td>朝代分期</td><td><?= number_format($stats['dynasties'] ?? 0) ?></td></tr>
      <tr><td>中外联动</td><td><?= number_format($stats['relations'] ?? 0) ?></td></tr>
      <tr><td>安装锁</td><td><code>data/install.lock</code>（已生效）</td></tr>
    </table>
    <p class="log" style="margin:18px 0 0">
      <b>推荐</b>：直接把 <code>install.php</code> 删掉，需要重装时从源码包重新上传。<br>
      若要保留安装器，重装前先通过 FTP 删除 <code>data/install.lock</code>，
      或在命令行执行 <code>php install.php --force</code>。<br>
      更新内容数据请用后台的<a href="<?= h(link_to('admin/restore.php')) ?>">导入</a>，它是事务化的，失败会整体回滚。
    </p>
  </div>
  <div class="card">
    <h2>下一步</h2>
    <p class="log">
      ① <a href="<?= h(link_to('index.php')) ?>">打开前台</a>　② <a href="<?= h(alink('login.php')) ?>">登录后台</a>（用户名 <code>admin</code>）<br>
      ③ 在后台「账户」里改密码　④ <b>删除 install.php</b>
    </p>
  </div>
<?php endif; ?>

<?php if ($state === 'partial' && !$locked): ?>
  <div class="warn">
    <b>检测到上次安装未完成。</b>数据表已创建，但数据没有导完（常见于中途报错）。<br>
    直接点下面的「开始安装 / 修复」即可 —— 会先清空残留再重新导入，不会重复报错。
  </div>
  <div class="card">
    <h2>修复安装</h2>
    <table>
      <tr><td>已存在的表</td><td><?= count(array_filter(['users','cn_events','world_events','dynasties','categories','regions','relation_types'], 'db_table_exists')) ?> / 8</td></tr>
      <tr><td>中国节点</td><td><?= number_format($stats['cn'] ?? 0) ?></td></tr>
      <tr><td>世界大事</td><td><?= number_format($stats['world'] ?? 0) ?></td></tr>
    </table>
    <form method="post" style="margin-top:18px">
        <?= csrf_field() ?>
      <input type="hidden" name="action" value="install">
      <button class="btn" type="submit">开始安装 / 修复</button>
    </form>
  </div>
<?php endif; ?>

<?php if ($state === 'complete' && !$locked): ?>
  <div class="card">
    <h2>当前状态</h2>
    <table>
      <tr><td>中国节点</td><td><?= number_format($stats['cn'] ?? 0) ?></td></tr>
      <tr><td>世界大事</td><td><?= number_format($stats['world'] ?? 0) ?></td></tr>
      <tr><td>朝代分期</td><td><?= number_format($stats['dynasties'] ?? 0) ?></td></tr>
      <tr><td>中外联动</td><td><?= number_format($stats['relations'] ?? 0) ?></td></tr>
    </table>
    <div class="warn" style="margin-top:18px">
      检测到已安装完整数据。重新导入会<b>清空并重建</b>所有内容表（含你在后台新增的内容）。<br>
      如需重装，勾选下面的选项后点按钮。
    </div>
    <form method="post" onsubmit="return confirm('确定要清空并重建全部数据吗？后台添加的内容将全部丢失。')">
        <?= csrf_field() ?>
      <input type="hidden" name="action" value="install">
      <label style="display:flex;gap:8px;align-items:center;margin-bottom:12px;font-size:14px;cursor:pointer">
        <input type="checkbox" name="force" value="1" checked>
        清空并重建（删除现有全部内容）
      </label>
      <button class="btn" type="submit">重新安装</button>
    </form>
  </div>
  <div class="card">
    <h2>下一步</h2>
    <p class="log">
      ① <a href="<?= h(link_to('index.php')) ?>">打开前台</a>　② <a href="<?= h(alink('login.php')) ?>">登录后台</a>（用户名 <code>admin</code>）<br>
      ③ <b>删除 install.php</b>　④ 在后台「账户」里改密码
    </p>
  </div>

  <?php if (!empty($GLOBALS['__admin_plain_pass'])): ?>
  <div class="card" style="border:2px solid #c0392b">
    <h2 style="color:#c0392b">管理员密码（只显示这一次）</h2>
    <p class="log" style="font-size:15px">
      用户名 <code>admin</code>　密码
      <code style="font-size:17px;letter-spacing:1px;background:#f6f6f6;padding:3px 8px;border-radius:4px"><?= h($GLOBALS['__admin_plain_pass']) ?></code>
    </p>
    <p class="log" style="color:#c0392b">
      请立刻抄下来。密码是随机生成的，<b>无法找回</b>；
      本页刷新后也不再显示。若丢失，只能回后台用另一个管理员账号重置。
    </p>
  </div>
  <?php endif; ?>
<?php endif; ?>

<?php if ($done): ?>
  <div class="card">
    <h2><?= $errors ? '安装过程出现问题' : '安装完成' ?></h2>
    <div class="log">
      <?php foreach ($log as $l): ?>
        <div class="ok">✓ <?= h($l) ?></div>
      <?php endforeach; ?>
      <?php foreach ($errors as $e): ?>
        <div class="err">✗ <?= h($e) ?></div>
      <?php endforeach; ?>
    </div>
    <?php if (!$errors): ?>
      <?php
      // ★ 成功页要一次性把三件事说清：装了什么、管理员怎么进、接下来做什么。
      //   管理员密码**只在这里显示一次**（安装会话结束后无法再取回），
      //   所以它必须和「去后台」的按钮在同一屏，否则用户很可能先点走再也回不来。
      $pass = (string) ($GLOBALS['__admin_plain_pass'] ?? '');
      ?>
      <?php if ($tablesOnly): ?>
      <!-- ★ 只建了表、没导数据：这时给「进入前台」按钮是错的 ——
             前台一片空白，用户会以为装坏了。必须引导回去点「开始安装」。 -->
      <div class="warn" style="margin-top:20px">
        <b>表结构已建好，但数据还没导入。</b><br>
        现在数据库里只有表、没有任何内容。<b>请回到本页再点一次「开始安装」</b>，
        才会导入 25 个朝代 / 117 个中国节点 / 669 条世界大事 / 19 条联动，
        并创建管理员账号。
        <div style="margin-top:14px">
          <a class="btn" href="<?= h(link_to('install.php')) ?>">继续，点「开始安装」</a>
        </div>
      </div>
      <?php else: ?>
      <div class="done-grid">
        <a class="done-card" href="<?= h(link_to('index.php')) ?>">
          <strong>进入前台</strong>
          <span>查看时间轴与同期世界大事</span>
        </a>
        <a class="done-card" href="<?= h(alink('login.php')) ?>">
          <strong>进入后台</strong>
          <span>登录 admin 管理内容与投稿</span>
        </a>
      </div>

      <?php if ($pass !== ''): ?>
      <div class="card" style="border:2px solid #c0392b;margin-top:18px">
        <h2 style="color:#c0392b">管理员账号（只显示这一次）</h2>
        <table>
          <tr><td>用户名</td><td><code>admin</code></td></tr>
          <tr><td>密码</td><td><code style="font-size:17px;letter-spacing:1px;background:#f6f6f6;padding:3px 8px;border-radius:4px"><?= h($pass) ?></code></td></tr>
        </table>
        <p style="color:#c0392b;font-size:14px;margin:14px 0 0">
          请立刻抄下来。密码是随机生成的，<b>无法找回</b>；本页刷新后不再显示。
          登录后请在「账户」里改成自己的密码。
        </p>
      </div>
      <?php endif; ?>

      <div class="warn" style="margin-top:18px">
        <b>接下来做两件事：</b><br>
        ① <b>删除 <code>install.php</code></b> —— 它能建表与清库，留着有风险<br>
        ② 登录后台改管理员密码<br>
        <span style="color:#92400e">
          （若目录不允许删除文件也别担心：安装完成时已自动写入安装锁
          <code>data/install.lock</code>，该页面此后<b>不再接受任何提交</b>。）
        </span>
      </div>
      <?php endif; /* tablesOnly */ ?>
    <?php else: ?>
      <p style="margin-top:18px"><a href="<?= h(link_to('install.php')) ?>">重试</a></p>
    <?php endif; ?>
  </div>
<?php elseif ($state === 'empty'): ?>

  <div class="card">
    <h2>将要导入的内容</h2>
    <table>
<?php
    // 数字必须实时统计，不能写死。
    // 这里曾写着「同期世界大事 124」，实际是 669 —— 数据早就更新了，
    // 而这行字一直摆在新用户面前。用户看到"将导入 124 条"、装完数出 669 条，
    // 第一反应是装坏了。
    $rows = [
        'dynasties'    => '朝代分期（夏商周 → 当代）',
        'cn_events'    => '中国历史节点',
        'world_events' => '同期世界大事',
        'relations'    => '中外联动关系',
    ];
    $probe = [];
    try {
        $probe = datapack_load_default();
    } catch (Throwable $e) {
        $probe = [];   // 读不到就显示 ?，绝不显示一个错的数字
    }
    foreach ($rows as $key => $label) {
        $n = isset($probe[$key]) && is_array($probe[$key]) ? count($probe[$key]) : null;
        echo '      <tr><td>' . h($label) . '</td><td>'
             . ($n === null ? '?' : number_format($n)) . '</td></tr>' . "\n";
    }
?>
    </table>
    <p style="margin-top:16px;color:var(--muted);font-size:14px">
      节点与大事均为可编辑的种子内容，安装后可在后台自由增删改。
    </p>
    <form method="post" style="margin-top:18px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <?= csrf_field() ?>
      <input type="hidden" name="action" value="install">
      <button class="btn" type="submit">开始安装</button>
    </form>
    <form method="post" style="margin-top:14px">
        <?= csrf_field() ?>
      <input type="hidden" name="action" value="tables_only">
      <button class="btn ghost" type="submit" style="font-size:13px;padding:7px 16px">
        仅建表与索引（不导入数据）
      </button>
      <span style="margin-left:10px;font-size:12.5px;color:var(--muted)">
        报「建表失败」时可先用这个只修复表结构，再回来点开始安装
      </span>
    </form>
  </div>
<?php endif; ?>
</div>
</body>
</html>
