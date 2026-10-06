<?php
/**
 * 安装前置检查 —— 不连数据库
 * ---------------------------------------------------------------------------
 * 存在的理由：
 *   全新站点上 `config.local.php` 还不存在，而 inc/bootstrap.php 会
 *   DB::pdo() → new PDO(...)，连不上直接抛 PDOException，
 *   用户看到的是 **HTTP 500 + 白屏**，完全不知道下一步该干什么。
 *
 *   所以安装器必须先跑这一段：**不碰数据库**，先把「该填什么」问清楚，
 *   写好配置再让应用正常启动。
 *
 * 四种状态（stage）：
 *   dbform  还没配置 → 显示数据库信息表单（唯一能做的事）
 *   env     已配置、未装完 → 交给 install.php 主体做环境检测与安装
 *   ready   已配置、装完了（无锁）→ 主体显示「已安装 + 可重装」
 *   locked  已配置、装完了且有锁 → 只读
 *
 * 判据全部基于**文件是否存在**，不查表：
 *   config.local.php 不存在  → dbform
 *   data/install.lock 存在   → locked
 *   其余交给主体用真实连接判断
 */

// ===========================================================================
// 配置读写
// ===========================================================================

/** 项目根目录（本文件在 inc/ 下） */
function precheck_root(): string
{
    return dirname(__DIR__);
}

function precheck_config_file(): string
{
    return precheck_root() . '/config.local.php';
}

function precheck_lock_file(): string
{
    return precheck_root() . '/data/install.lock';
}

/** 配置是否已就位 */
function precheck_has_config(): bool
{
    return is_file(precheck_config_file());
}

/** 是否已上锁（装完了） */
function precheck_locked(): bool
{
    return is_file(precheck_lock_file());
}

/**
 * 写 config.local.php。
 *
 * 几个刻意的选择：
 *  - 用 var_export 生成字面量，值里的引号与反斜杠自动转义，
 *    密码含 `'` 也不会把文件写成语法错误。
 *  - 权限 0644：FTP 用户与 Web 进程可读，其他人不可写。
 *  - **原子写**：先写 .tmp 再 rename —— 直接 file_put_contents 到目标路径时，
 *    若中途失败会留下半截文件，下次 require 它就是白屏且极难定位。
 *
 * @return array{ok:bool, msg:string}
 */
function precheck_write_config(array $db): array
{
    $file = precheck_config_file();
    $dir  = dirname($file);
    if (!is_dir($dir) || !is_writable($dir)) {
        return ['ok' => false, 'msg' => '目录不可写：' . $dir
            . '（请给该目录写权限，或手工创建 config.local.php）'];
    }
    if (is_file($file) && !is_writable($file)) {
        return ['ok' => false, 'msg' => 'config.local.php 已存在但不可写，'
            . '请手工修改它并保证可写'];
    }

    $tpl = "<?php\n"
         . "/**\n"
         . " * 本地配置（安装器自动生成）\n"
         . " *\n"
         . " * 敏感信息只写在这里，不要写进 config.php —— 后者会被后续升级覆盖。\n"
         . " * 重新生成：本页删掉本文件，或直接改这里。\n"
         . " */\n"
         . "return [\n"
         . "    'db' => [\n"
         . "        'driver'     => 'mysql',\n"
         . "        'host'       => %s,\n"
         . "        'port'       => %d,\n"
         . "        'name'       => %s,\n"
         . "        'user'       => %s,\n"
         . "        'pass'       => %s,\n"
         . "        'charset'    => 'utf8mb4',\n"
         . "        'persistent' => true,\n"
         . "    ],\n"
         . "];\n";

    $body = sprintf($tpl,
        var_export((string) $db['host'], true),
        (int) $db['port'],
        var_export((string) $db['name'], true),
        var_export((string) $db['user'], true),
        var_export((string) $db['pass'], true)
    );

    // 原子写：先写临时文件再 rename，避免半截文件
    $tmp = $file . '.tmp';
    if (@file_put_contents($tmp, $body) === false) {
        return ['ok' => false, 'msg' => '写入失败，请检查目录权限'];
    }
    @chmod($tmp, 0644);
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return ['ok' => false, 'msg' => '写入失败（rename 不可用，请检查目录权限）'];
    }
    return ['ok' => true, 'msg' => '已写入 config.local.php'];
}

// ===========================================================================
// 连接测试（不依赖 inc/db.php，那会先加载配置）
// ===========================================================================

/**
 * 直接用 PDO 测一次连接与权限。
 *
 * 刻意不复用 DB::pdo()：此刻配置刚写进文件，PHP 的常量/静态缓存
 * 还没重载，自己new 一个最干净。
 */
function precheck_test_connection(array $db): array
{
    if (!extension_loaded('pdo_mysql')) {
        // 缺扩展时，**错误消息本身**就必须带操作指引。
        // 只说「缺少 pdo_mysql」等于没说——用户不知道去哪开，更不知道要先建库。
        return ['ok' => false, 'msg' => '缺少 pdo_mysql 扩展，无法连接数据库。'
            . '宝塔面板：软件商店 → PHP → 设置 → 安装扩展 → 勾选 pdo_mysql → 重启 PHP，'
            . '然后刷新本页。'];
    }
    $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], (int) $db['port']);
    try {
        $pdo = new PDO($dsn, (string) $db['user'], (string) $db['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
    } catch (PDOException $e) {
        return ['ok' => false, 'msg' => precheck_translate_pdo($e->getMessage())];
    }

    // ---- 库不存在就自动建 ----
    // 需求是「填完就能装」，所以主动尝试 CREATE DATABASE。
    // 但**必须如实区分两种失败**（否则用户会一直撞同一堵墙却不知道为什么）：
    //   · 权限不足 → 告诉他去面板建，或换个有权限的账号
    //   · 名字非法 → 库名只能含字母数字下划线
    $dbName = (string) $db['name'];
    $safe   = str_replace('`', '', $dbName);
    if ($safe === '') {
        return ['ok' => false, 'msg' => '数据库名不能为空。'];
    }
    $ver = '';
    try { $ver = (string) $pdo->query('SELECT VERSION()')->fetchColumn(); } catch (Throwable $e) {}

    if (!precheck_db_exists($pdo, $safe)) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $safe)) {
            return ['ok' => false, 'msg' => '数据库名「' . $dbName . '」含非法字符，'
                . '只能使用字母、数字和下划线。'];
        }
        try {
            $pdo->exec('CREATE DATABASE `' . $safe . '` '
                . 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (PDOException $e) {
            $raw    = $e->getMessage();
            $errno  = (int) ($e->errorInfo[1] ?? 0);
            $denied = stripos($raw, 'access denied') !== false
                   || stripos($raw, 'permission') !== false
                   || $errno === 1044 || $errno === 1045;
            if ($denied) {
                return ['ok' => false, 'msg' =>
                    '数据库「' . $dbName . '」不存在，而这个账号没有创建数据库的权限。'
                    . '两条路任选其一：'
                    . '① 在服务器面板（宝塔「数据库」）新建一个空库，字符集选 utf8mb4，'
                    . '把库名回填到这里；'
                    . '② 换一个对该库有权限的 MySQL 账号密码。'];
            }
            return ['ok' => false, 'msg' => '自动创建数据库「' . $dbName . '」失败：'
                . precheck_translate_pdo($raw)];
        }
    }

    // 选库
    try {
        $pdo->exec('USE `' . $safe . '`');
    } catch (PDOException $e) {
        return ['ok' => false, 'msg' => '连上了服务器，但无法使用数据库「' . $dbName . '」：'
            . precheck_translate_pdo($e->getMessage())];
    }

    // 能不能建表 —— 没有 CREATE 就装不了
    try {
        $pdo->query('CREATE TABLE IF NOT EXISTS `_precheck_tmp` (`id` INT)');
        $pdo->exec('DROP TABLE `_precheck_tmp`');
    } catch (PDOException $e) {
        $errno = (int) ($e->errorInfo[1] ?? 0);
        if ($errno === 1142 || stripos($e->getMessage(), 'denied') !== false) {
            return ['ok' => false, 'msg' => '账号对数据库「' . $dbName . '」没有建表权限。'
                . '请在面板里给该账号授权，或换一个有权限的账号。'];
        }
        return ['ok' => false, 'msg' => '数据库可用，但没有建表权限：'
            . precheck_translate_pdo($e->getMessage())];
    }

    return ['ok' => true, 'msg' => '连接成功' . ($ver ? '（MySQL ' . $ver . '）' : '')];
}

/**
 * 库是否已存在。
 *
 * 用 `SHOW DATABASES LIKE ?` 而不是查 information_schema：
 * 前者对权限要求更宽，部分主机上 information_schema 被过滤而 SHOW 不受影响。
 * SHOW 权限也拿不到时，退回 `USE` 试探 —— 反正紧接着就要 USE 一次。
 */
function precheck_db_exists(PDO $pdo, string $name): bool
{
    try {
        $st = $pdo->prepare('SHOW DATABASES LIKE ?');
        $st->execute([$name]);
        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        try { $pdo->exec('USE `' . $name . '`'); return true; } catch (Throwable $e2) {}
        return false;
    }
}

/** 把 PDO 的英文报错翻成可操作的中文 */
function precheck_translate_pdo(string $raw): string
{
    $m = strtolower($raw);
    if (strpos($m, 'access denied') !== false) {
        return '用户名或密码不对。请检查拼写、区分大小写，以及该账号是否有这个库的权限。';
    }
    if (strpos($m, 'unknown database') !== false) {
        return '数据库不存在。请先在服务器面板创建这个空库。';
    }
    if (strpos($m, 'connection refused') !== false || strpos($m, 'no connection') !== false) {
        return '连不上数据库服务器。请确认 host 与 port（宝塔/MyBaaS 默认都是 127.0.0.1:3306）。';
    }
    if (strpos($m, 'timed out') !== false || strpos($m, 'timeout') !== false) {
        return '连接超时。可能是 host 填了外网地址但没放行端口。';
    }
    if (strpos($m, 'no such host') !== false || strpos($m, 'getaddrinfo') !== false) {
        return '主机名解析失败。请把 host 改成 127.0.0.1。';
    }
    return '连接失败：' . $raw;
}

// ===========================================================================
// 状态判定
// ===========================================================================

function precheck_collect(): array
{
    $stage = 'env';
    if (!precheck_has_config()) {
        $stage = 'dbform';
    } elseif (precheck_locked()) {
        $stage = 'locked';
    }
    return [
        'stage'   => $stage,
        'config'  => precheck_config_file(),
        'locked'  => precheck_locked(),
        'errs'    => [],
        'notice'  => '',
        'old'     => ['host' => '127.0.0.1', 'port' => '3306', 'name' => '', 'user' => '', 'pass' => ''],
    ];
}

// ===========================================================================
// 表单处理
// ===========================================================================

/**
 * 处理「填库信息」这一步的提交。
 *
 * ⚠️ $pre 必须**按引用**传。校验失败时要往 $pre['errs'] 里塞错误、
 *往 $pre['old'] 里塞用户刚填的值（免得白填一遍），
 * 按值传的话这些都写不回去，页面会渲染成「第一次进来」的样子 ——
 * 用户点了提交，却看不到任何错误提示，也不知道刚才填的哪一项错了。
 *
 * @return array|null 写入成功返回配置数组（调用方应重定向）；需要渲染表单返回 null
 */
function precheck_handle_db_form(array &$pre)
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return null;
    }
    $db = [
        'host' => trim((string) ($_POST['db_host'] ?? '')),
        'port' => (int) ($_POST['db_port'] ?? 3306),
        'name' => trim((string) ($_POST['db_name'] ?? '')),
        'user' => trim((string) ($_POST['db_user'] ?? '')),
        'pass' => (string) ($_POST['db_pass'] ?? ''),
    ];
    // 回填失败时的输入，避免用户白填一遍
    $pre['old'] = $db;

    $miss = [];
    if ($db['host'] === '') $miss[] = '数据库地址';
    if ($db['name'] === '') $miss[] = '数据库名';
    if ($db['user'] === '') $miss[] = '用户名';
    if ($db['port'] < 1 || $db['port'] > 65535) $miss[] = '端口（应在 1~65535）';
    if ($miss) {
        $pre['errs'][] = '请填写：' . implode('、', $miss);
        return null;
    }

    $t = precheck_test_connection($db);
    if (!$t['ok']) {
        $pre['errs'][] = $t['msg'];
        return null;
    }

    $w = precheck_write_config($db);
    if (!$w['ok']) {
        $pre['errs'][] = $w['msg'];
        return null;
    }
    return $db;
}

// ===========================================================================
// 第一步的页面
// ===========================================================================

function precheck_render_db_form(array $pre): void
{
    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $o = $pre['old'];
    $phpv = PHP_VERSION;
    $ext  = extension_loaded('pdo_mysql');
    $root = precheck_root();

    header('Content-Type: text/html; charset=utf-8');
    // 这一页不该被缓存：写完配置后刷新必须回到第二步
    header('Cache-Control: no-store');
    ?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>安装 · <?= $e(precheck_site_name()) ?></title>
<style>
  :root { --ink:#1a1a1a; --muted:#6b7280; --line:#e5e7eb; --accent:#c0392b; }
  * { box-sizing:border-box; }
  body { font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;
         margin:0; background:#f7f5f2; color:var(--ink); line-height:1.7; }
  .wrap { max-width:680px; margin:0 auto; padding:44px 24px 80px; }
  h1 { font-size:25px; margin:0 0 6px; }
  .sub { color:var(--muted); font-size:14px; margin-bottom:26px; }
  .steps { display:flex; gap:8px; margin-bottom:24px; }
  .step { flex:1; font-size:12.5px; color:var(--muted); padding:8px 12px;
          background:#eeeae5; border-radius:6px; }
  .step.on { background:var(--ink); color:#fff; font-weight:600; }
  .card { background:#fff; border:1px solid var(--line); border-radius:12px; padding:24px 28px; margin-bottom:18px; }
  h2 { font-size:16px; margin:0 0 14px; }
  label { display:block; font-size:13.5px; color:#374151; margin:14px 0 5px; }
  input { width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:7px;
          font:inherit; font-size:15px; background:#fff; }
  input:focus { outline:2px solid var(--accent); outline-offset:-1px; border-color:var(--accent); }
  .row { display:flex; gap:12px; }
  .row > div { flex:1; }
  .hint { font-size:12.5px; color:var(--muted); margin-top:5px; }
  .btn { display:inline-block; background:var(--accent); color:#fff; padding:11px 28px; border:none;
         border-radius:8px; font-size:15px; cursor:pointer; font-family:inherit; }
  .btn:hover { background:#a93226; }
  .err { background:#fef2f2; border:1px solid #fecaca; color:#b91c1c;
         padding:12px 16px; border-radius:8px; font-size:14px; margin-bottom:16px; }
  .err ul { margin:6px 0 0 18px; line-height:1.75; }
  .ok { color:#059669; } .bad { color:#dc2626; }
  .small { font-size:12.5px; color:var(--muted); }
  code { background:#f3f4f6; padding:2px 6px; border-radius:4px; font-size:12.5px; }
</style>
</head>
<body>
<div class="wrap">
  <h1><?= $e(precheck_site_name()) ?></h1>
  <div class="sub">首次安装 —— 先填数据库信息，剩下的会自动完成</div>

  <div class="steps">
    <div class="step on">1 · 数据库</div>
    <div class="step">2 · 环境检测</div>
    <div class="step">3 · 安装</div>
  </div>

<?php if ($pre['errs']): ?>
  <div class="err">
    <b>还差一点：</b>
    <ul><?php foreach ($pre['errs'] as $m): ?><li><?= $e($m) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

  <div class="card">
    <h2>数据库信息</h2>
    <p class="small" style="margin:0 0 6px">
      在服务器面板（如宝塔「数据库」）新建一个<strong>空库</strong>，
      把下面的信息填进来。字符集请选 <code>utf8mb4</code>。
    </p>

    <form method="post" action="">
      <div class="row">
        <div>
          <label for="h">数据库地址</label>
          <input id="h" name="db_host" value="<?= $e($o['host']) ?>" placeholder="127.0.0.1" required>
          <div class="hint">本机数据库填 127.0.0.1</div>
        </div>
        <div style="max-width:120px">
          <label for="p">端口</label>
          <input id="p" name="db_port" value="<?= $e($o['port']) ?>" inputmode="numeric" required>
          <div class="hint">默认 3306</div>
        </div>
      </div>

      <label for="n">数据库名</label>
      <input id="n" name="db_name" value="<?= $e($o['name']) ?>" placeholder="history_timeline" required>

      <div class="row">
        <div>
          <label for="u">用户名</label>
          <input id="u" name="db_user" value="<?= $e($o['user']) ?>" required>
        </div>
        <div>
          <label for="pw">密码</label>
          <input id="pw" name="db_pass" type="password" autocomplete="off">
        </div>
      </div>

      <div style="margin-top:22px">
<?php if ($ext): ?>
        <button class="btn" type="submit">下一步：测试连接</button>
<?php else: ?>
        <?php /* 缺 pdo_mysql 时不给提交按钮：填了也连不上，让用户白填一遍没有意义 */ ?>
        <button class="btn" type="button" disabled
                style="background:#9ca3af;cursor:not-allowed">请先启用 pdo_mysql 扩展</button>
<?php endif; ?>
      </div>
    </form>
  </div>

  <div class="card">
    <h2>当前环境</h2>
    <table style="width:100%;font-size:14px;border-collapse:collapse">
      <tr><td style="padding:6px 0;color:var(--muted)">PHP 版本</td>
          <td style="text-align:right"><?= $e($phpv) ?>
            <?= version_compare($phpv, '7.4', '<') ? '<span class="bad">（需 ≥ 7.4）</span>'
                : '<span class="ok">✓</span>' ?></td></tr>
      <tr><td style="padding:6px 0;color:var(--muted)">pdo_mysql 扩展</td>
          <td style="text-align:right"><?= $ext ? '<span class="ok">✓ 已启用</span>'
              : '<span class="bad">✗ 缺失 —— 请在 PHP 设置里启用后重试</span>' ?></td></tr>
    </table>
<?php if (!$ext): ?>
    <p class="small" style="margin:14px 0 0">
      宝塔面板：软件商店 → PHP → 设置 → 安装扩展 → 勾 <code>pdo_mysql</code>。
    </p>
<?php endif; ?>
  </div>

  <p class="small" style="text-align:center;margin-top:26px">
    这一步只会在本目录生成 <code>config.local.php</code>，不上传任何数据到外部。
  </p>
</div>
</body>
</html>
<?php
    exit;
}

/**
 * 站名（读 config.php 的默认值，此时还没有本地配置）。
 *
 * 刻意**直接 require config.php** 而不是拿正则扫文件 ——
 * 扫文件的结果是抓到文件头注释里那个 `'name' => 'history_timeline'` 示例，
 * 标题就变成了数据库名。（踩过一次）
 *
 * require config.php 不会连库：它只是把 defaults 与 config.local.php
 * 合并后放进 $GLOBALS['__cfg_map']，cfg() 是纯数组读取。
 * 这个时刻 config.local.php 还不存在，override 为空，正好拿到默认值。
 */
function precheck_site_name(): string
{
    static $name = null;
    if ($name !== null) {
        return $name;
    }
    $name = '互文 · 世界同期大事录';   // 兜底：连 config.php 都读不到时
    if (is_file(precheck_root() . '/config.php')) {
        try {
            require_once precheck_root() . '/config.php';
            if (function_exists('cfg')) {
                $n = (string) cfg('site.name', '');
                if ($n !== '') {
                    $name = $n;
                }
            }
        } catch (Throwable $e) {
            // 配置坏了就用兜底名，向导第一步不该因为它整个挂掉
        }
    }
    return $name;
}
