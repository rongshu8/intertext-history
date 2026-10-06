<?php
/**
 * 登录（管理员与注册用户共用入口，按角色分流）
 */

// 未安装时导向安装向导（提交登录会连库 → PDOException）
require_once __DIR__ . '/../inc/install_gate.php';
require_once __DIR__ . '/../inc/bootstrap.php';

if (is_logged_in()) {
    header('Location: ' . (is_admin() ? alink('index.php') : link_to('user/index.php')));
    exit;
}

$error = '';
$pre   = q('err');

if ($pre === 'banned') {
    $error = '该账号已被禁用，请联系管理员。';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $user = p('username');
    $pass = p('password');

    // 限流桶按「用户名 + 客户端 IP」组合，避免单纯按 IP 被代理绕过，
    // 也避免不同用户名共享同一计数器（否则一人输错会锁死所有人）。
    $ip   = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $bucket = 'login_' . substr(hash('sha256', $user . '|' . $ip), 0, 32);

    if (!csrf_check()) {
        $error = '会话已过期，请重新提交。';
    } elseif (!login_rate_limit($bucket)) {
        $error = '尝试次数过多，请 5 分钟后再试。';
    } elseif ($user === '' || $pass === '') {
        $error = '请输入用户名和密码。';
    } else {
        $row = DB::fetchOne('SELECT * FROM users WHERE username = ?', [$user]);
        // 用户不存在时也走一次 password_verify，用假 hash 抵消时间差，防用户名枚举
        $hash = $row['password'] ?? '$2y$10$usesomesillystringfor.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        if ($row && password_verify($pass, $hash)) {
            if (($row['status'] ?? 'active') === 'banned') {
                $error = '该账号已被禁用，请联系管理员。';
            } else {
                session_regenerate_id(true);
                login_rate_clear($bucket);
                $_SESSION['uid'] = (int) $row['id'];
                DB::exec('UPDATE users SET last_login =  ' . SQL_NOW . ' WHERE id = ?', [(int) $row['id']]);
                // 按角色分流：管理员进后台，注册用户进用户中心
                $dest = ($row['role'] ?? 'user') === 'admin'
                    ? alink('index.php')
                    : link_to('user/index.php');
                header('Location: ' . $dest);
                exit;
            }
        } else {
            login_rate_hit($bucket);
            $left = login_rate_left($bucket);
            $error = '用户名或密码不正确。'
                . ($left > 0 && $left <= 2 ? "（还可尝试 {$left} 次）" : '');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>登录 · <?= h(c_site_name()) ?></title>
<link rel="stylesheet" href="<?= h(asset('style.css')) ?>">
<link rel="stylesheet" href="<?= h(asset('admin.css')) ?>">
<link rel="stylesheet" href="<?= h(asset('responsive.css')) ?>">
</head>
<body class="admin-body">
<div class="login-wrap">
  <form class="login-card" method="post" autocomplete="on">
    <div class="brand-mark">互文</div>
    <h1>登录</h1>
    <div class="sub">互文 · 世界同期大事录</div>

    <?php if ($error): ?>
      <div class="alert alert-err"><?= h($error) ?></div>
    <?php endif; ?>

    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">

    <div class="aform">
      <div class="afield">
        <label for="u">用户名</label>
        <input type="text" id="u" name="username" autocomplete="username" autofocus required>
      </div>
      <div class="afield">
        <label for="p">密码</label>
        <input type="password" id="p" name="password" autocomplete="current-password" required>
      </div>
      <button class="btn-s dark" type="submit" style="width:100%;justify-content:center;padding:9px">
        登录
      </button>
    </div>

    <p style="text-align:center;margin:18px 0 0;font-size:13px">
      还没有账号？<a href="<?= h(link_to('register.php')) ?>" style="color:var(--cinnabar)">立即注册</a>
    </p>
    <p style="text-align:center;margin:8px 0 0;font-size:12.5px">
      <a href="<?= h(link_to('index.php')) ?>" style="color:#6b7280">← 返回前台</a>
    </p>
  </form>
</div>
</body>
</html>
