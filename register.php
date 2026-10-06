<?php
/**
 * 用户注册
 */

// 未安装时导向安装向导（必须在 bootstrap 之前：那时数据库还连不上）
require_once __DIR__ . '/inc/install_gate.php';
require_once __DIR__ . '/inc/bootstrap.php';

// 已登录用户直接去对应首页
if (is_logged_in()) {
    header('Location: ' . (is_admin() ? alink('index.php') : link_to('user/index.php')));
    exit;
}

$errors = [];
$old    = ['username' => '', 'email' => '', 'display_name' => ''];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $old['username']     = p('username');
    $old['email']        = p('email');
    $old['display_name'] = p('display_name');
    $pass                = p('password');
    $pass2               = p('password2');

    if (!csrf_check()) {
        $errors[] = '会话已过期，请重新提交。';
    } elseif (!login_rate_limit('reg_' . substr(hash('sha256',
                    (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')), 0, 16), 10, 3600)) {
        // 同一 IP 一小时内最多注册 10 次，防止批量灌账号
        $errors[] = '注册过于频繁，请稍后再试。';
    }
    // 用户名：3-24 位，字母数字下划线或连字符
    if (!preg_match('/^[A-Za-z0-9_-]{3,24}$/', $old['username'])) {
        $errors[] = '用户名需 3–24 位，只能用字母、数字、下划线或连字符。';
    }
    if (mb_strlen($old['display_name']) > 48) {
        $errors[] = '昵称不能超过 48 字。';
    }
    if ($old['email'] !== '' && !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = '邮箱格式不正确。';
    }
    if (mb_strlen($pass) < 8) {
        $errors[] = '密码至少 8 位。';
    } elseif ($pass !== $pass2) {
        $errors[] = '两次输入的密码不一致。';
    }

    // 用户名 / 邮箱唯一性
    if (empty($errors)) {
        if (DB::fetchOne('SELECT id FROM users WHERE username = ?', [$old['username']])) {
            $errors[] = '该用户名已被注册。';
        } elseif ($old['email'] !== '' && DB::fetchOne('SELECT id FROM users WHERE email = ?', [$old['email']])) {
            $errors[] = '该邮箱已被注册。';
        }
        if ($errors) {
            // 撞名也要计数，否则可用「枚举用户名」的方式绕过注册频率限制
            login_rate_hit('reg_' . substr(hash('sha256',
                (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')), 0, 16));
        }
    }

    if (empty($errors)) {
        try {
            DB::exec(
                'INSERT INTO users (username, password, display_name, email, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ' . SQL_NOW . ')',
                [
                    $old['username'],
                    password_hash($pass, PASSWORD_DEFAULT),
                    $old['display_name'] ?: null,
                    $old['email'] ?: null,
                    'user',
                    'active',
                ]
            );
            $uid = DB::lastInsertId();

            // 注册即登录，省一次输入
            session_regenerate_id(true);
            login_rate_clear('reg_' . substr(hash('sha256',
                (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')), 0, 16));
            $_SESSION['uid'] = $uid;
            DB::exec('UPDATE users SET last_login =  ' . SQL_NOW . ' WHERE id = ?', [$uid]);

            header('Location: ' . link_to('user/index.php') . '?welcome=1');
            exit;
        } catch (Throwable $e) {
            $errors[] = '注册失败：' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>注册 · <?= h(c_site_name()) ?></title>
<link rel="stylesheet" href="<?= h(asset('style.css')) ?>">
<link rel="stylesheet" href="<?= h(asset('admin.css')) ?>">
<link rel="stylesheet" href="<?= h(asset('responsive.css')) ?>">
</head>
<body class="admin-body">
<div class="login-wrap">
  <form class="login-card" method="post" style="max-width:430px" autocomplete="on">
    <div class="brand-mark">互文</div>
    <h1>注册账号</h1>
    <div class="sub">注册后可提交内容，由管理员审核后展示</div>

    <?php if ($errors): ?>
      <div class="alert alert-err">
        <?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">

    <div class="aform">
      <div class="afield">
        <label for="u">用户名 <span style="color:#dc2626">*</span></label>
        <input type="text" id="u" name="username" value="<?= h($old['username']) ?>"
               required autocomplete="username" pattern="[A-Za-z0-9_\-]{3,24}"
               title="3–24 位，字母数字下划线或连字符">
        <span class="hint">3–24 位，登录时使用，注册后不可更改</span>
      </div>

      <div class="afield">
        <label for="d">昵称</label>
        <input type="text" id="d" name="display_name" value="<?= h($old['display_name']) ?>"
               maxlength="48" autocomplete="nickname">
        <span class="hint">显示在投稿记录里，留空则用用户名</span>
      </div>

      <div class="afield">
        <label for="e">邮箱</label>
        <input type="email" id="e" name="email" value="<?= h($old['email']) ?>" autocomplete="email">
        <span class="hint">选填，仅用于审核结果通知</span>
      </div>

      <div class="afield">
        <label for="p">密码 <span style="color:#dc2626">*</span></label>
        <input type="password" id="p" name="password" required minlength="8" autocomplete="new-password">
        <span class="hint">至少 8 位</span>
      </div>

      <div class="afield">
        <label for="p2">确认密码 <span style="color:#dc2626">*</span></label>
        <input type="password" id="p2" name="password2" required minlength="8" autocomplete="new-password">
      </div>

      <button class="btn-s dark" type="submit" style="width:100%;justify-content:center;padding:9px">
        注册并登录
      </button>
    </div>

    <p style="text-align:center;margin:18px 0 0;font-size:13px">
      已有账号？<a href="<?= h(alink('login.php')) ?>" style="color:var(--cinnabar)">去登录</a>
    </p>
    <p style="text-align:center;margin:8px 0 0;font-size:12.5px">
      <a href="<?= h(link_to('index.php')) ?>" style="color:#6b7280">← 返回前台</a>
    </p>
  </form>
</div>
</body>
</html>
