<?php
/**
 * 用户中心 · 账户设置（改昵称/邮箱/密码）
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_login();

$me     = current_user();
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        flash('会话已过期，请重试。', 'err');
        header('Location: ' . link_to('user/account.php'));
        exit;
    }
    $op = p('op');

    if ($op === 'profile') {
        $name  = p('display_name');
        $email = p('email');

        if (mb_strlen($name) > 48) {
            $errors[] = '昵称不能超过 48 字。';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = '邮箱格式不正确。';
        }
        if (empty($errors) && $email !== '' && $email !== ($me['email'] ?? '')) {
            $dup = DB::fetchOne('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, (int) $me['id']]);
            if ($dup) {
                $errors[] = '该邮箱已被其他账号使用。';
            }
        }
        if (empty($errors)) {
            DB::exec(
                'UPDATE users SET display_name = ?, email = ? WHERE id = ?',
                [$name ?: null, $email ?: null, (int) $me['id']]
            );
            flash('资料已更新。');
            header('Location: ' . link_to('user/account.php'));
            exit;
        }

    } elseif ($op === 'password') {
        $old = p('old_password');
        $new = p('new_password');
        $cfm = p('confirm_password');

        if (!password_verify($old, (string) $me['password'])) {
            $errors[] = '当前密码不正确。';
        } elseif (mb_strlen($new) < 8) {
            $errors[] = '新密码至少 8 位。';
        } elseif ($new !== $cfm) {
            $errors[] = '两次输入的新密码不一致。';
        } else {
            DB::exec('UPDATE users SET password = ? WHERE id = ?',
                [password_hash($new, PASSWORD_DEFAULT), (int) $me['id']]);
            flash('密码已更新。');
            header('Location: ' . link_to('user/account.php'));
            exit;
        }
    }
}

$USER_NAV   = 'account';
$USER_TITLE = '账户设置';
require __DIR__ . '/../inc/user_header.php';
?>

<div class="page-head">
  <h1>账户设置</h1>
  <div class="spacer"></div>
  <a class="btn-s" href="<?= h(link_to('user/index.php', ['tab' => 'mine'])) ?>">我的投稿</a>
</div>

<?php if ($errors): ?>
  <div class="alert alert-err">
    <?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="acard">
  <div class="acard-head"><h2>基本资料</h2></div>
  <div class="acard-pad">
    <form class="aform" method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="op" value="profile">
      <div class="grid-2">
        <div class="afield">
          <label>用户名</label>
          <input type="text" value="<?= h((string) $me['username']) ?>" disabled>
          <span class="hint">用户名注册后不可更改</span>
        </div>
        <div class="afield">
          <label>身份</label>
          <input type="text" value="<?= is_admin() ? '管理员' : '注册用户' ?>" disabled>
        </div>
      </div>
      <div class="grid-2">
        <div class="afield">
          <label>昵称</label>
          <input type="text" name="display_name" value="<?= h((string) ($me['display_name'] ?? '')) ?>" maxlength="48">
        </div>
        <div class="afield">
          <label>邮箱</label>
          <input type="email" name="email" value="<?= h((string) ($me['email'] ?? '')) ?>">
        </div>
      </div>
      <div class="form-actions">
        <button class="btn-s primary" type="submit">保存资料</button>
        <div class="spacer"></div>
        <span class="small muted">注册于 <?= h(substr((string) $me['created_at'], 0, 10)) ?></span>
      </div>
    </form>
  </div>
</div>

<div class="acard" style="margin-top:16px">
  <div class="acard-head"><h2>修改密码</h2></div>
  <div class="acard-pad">
    <form class="aform" method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="op" value="password">
      <div class="grid-3">
        <div class="afield">
          <label>当前密码</label>
          <input type="password" name="old_password" required autocomplete="current-password">
        </div>
        <div class="afield">
          <label>新密码</label>
          <input type="password" name="new_password" required minlength="8" autocomplete="new-password">
          <span class="hint">至少 8 位</span>
        </div>
        <div class="afield">
          <label>确认新密码</label>
          <input type="password" name="confirm_password" required minlength="8" autocomplete="new-password">
        </div>
      </div>
      <div class="form-actions">
        <button class="btn-s primary" type="submit">更新密码</button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/../inc/user_footer.php'; ?>
