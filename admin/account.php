<?php
/**
 * 账户管理（改密码 / 改用户名 / 新增管理员）
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        flash('会话已过期，请重试。', 'err');
        header('Location: ' . alink('account.php'));;
        exit;
    }
    $op = p('op');

    if ($op === 'password') {
        $old = p('old_password');
        $new = p('new_password');
        $cfm = p('confirm_password');
        $me  = current_user();

        if (!password_verify($old, (string) $me['password'])) {
            flash('当前密码不正确。', 'err');
        } elseif (strlen($new) < 8) {
            flash('新密码至少 8 位。', 'err');
        } elseif ($new !== $cfm) {
            flash('两次输入的新密码不一致。', 'err');
        } else {
            DB::exec('UPDATE users SET password = ? WHERE id = ?',
                [password_hash($new, PASSWORD_DEFAULT), (int) $me['id']]);
            flash('密码已更新。');
        }
        header('Location: ' . alink('account.php'));;
        exit;
    }

    if ($op === 'profile') {
        $uid  = (int) $_SESSION['uid'];
        $name = p('display_name') ?: null;
        $uname = p('username');
        if ($uname === '') {
            flash('用户名不能为空。', 'err');
        } else {
            $dup = DB::fetchOne('SELECT id FROM users WHERE username = ? AND id <> ?', [$uname, $uid]);
            if ($dup) {
                flash('该用户名已被占用。', 'err');
            } else {
                DB::exec('UPDATE users SET username = ?, display_name = ? WHERE id = ?', [$uname, $name, $uid]);
                flash('账户信息已更新。');
            }
        }
        header('Location: ' . alink('account.php'));;
        exit;
    }

    if ($op === 'add_user') {
        $uname = p('new_username');
        $pwd   = p('new_password');
        $disp  = p('new_display') ?: null;
        if ($uname === '' || strlen($pwd) < 8) {
            flash('用户名不能为空，新密码至少 8 位。', 'err');
        } else {
            $dup = DB::fetchOne('SELECT id FROM users WHERE username = ?', [$uname]);
            if ($dup) {
                flash('该用户名已存在。', 'err');
            } else {
                DB::exec('INSERT INTO users (username, password, display_name, created_at) VALUES (?,?,?, ' . SQL_NOW . ')',
                    [$uname, password_hash($pwd, PASSWORD_DEFAULT), $disp]);
                flash("管理员 {$uname} 已创建。");
            }
        }
        header('Location: ' . alink('account.php'));;
        exit;
    }

    if ($op === 'del_user') {
        $did = pint('id');
        if ($did === (int) $_SESSION['uid']) {
            flash('不能删除当前登录的账户。', 'err');
        } else {
            DB::exec('DELETE FROM users WHERE id = ?', [$did]);
            flash('账户已删除。');
        }
        header('Location: ' . alink('account.php'));;
        exit;
    }
}

$PAGE_TITLE = '账户';
$ADMIN_NAV  = 'acc';
require __DIR__ . '/../inc/admin_header.php';

$me    = current_user();
$users = DB::fetchAll('SELECT * FROM users ORDER BY id');
?>

<div class="page-head">
  <h1>账户</h1>
  <div class="spacer"></div>
  <a class="btn-s" href="<?= h(alink('logout.php')) ?>">退出登录</a>
</div>

<div class="grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
  <div class="acard">
    <div class="acard-head"><h2>修改密码</h2></div>
    <div class="acard-pad">
      <form class="aform" method="post">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="op" value="password">
        <div class="afield">
          <label>当前密码</label>
          <input type="password" name="old_password" required autocomplete="current-password">
        </div>
        <div class="afield">
          <label>新密码</label>
          <input type="password" name="new_password" required minlength="8" autocomplete="new-password">
          <span class="hint">至少 8 位，建议包含字母与数字</span>
        </div>
        <div class="afield">
          <label>确认新密码</label>
          <input type="password" name="confirm_password" required minlength="8" autocomplete="new-password">
        </div>
        <div class="form-actions">
          <button class="btn-s primary" type="submit">更新密码</button>
        </div>
      </form>
    </div>
  </div>

  <div class="acard">
    <div class="acard-head"><h2>账户信息</h2></div>
    <div class="acard-pad">
      <form class="aform" method="post">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="op" value="profile">
        <div class="afield">
          <label>用户名</label>
          <input type="text" name="username" value="<?= h((string) $me['username']) ?>" required>
        </div>
        <div class="afield">
          <label>显示名</label>
          <input type="text" name="display_name" value="<?= h((string) ($me['display_name'] ?? '')) ?>" placeholder="可留空">
        </div>
        <div class="afield">
          <label>上次登录</label>
          <input type="text" value="<?= h((string) ($me['last_login'] ?? '—')) ?>" disabled>
        </div>
        <div class="form-actions">
          <button class="btn-s primary" type="submit">保存</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="acard" style="margin-top:16px">
  <div class="acard-head"><h2>管理员列表</h2></div>
  <div style="overflow-x:auto">
    <table class="atable">
      <thead><tr><th style="width:60px">ID</th><th style="width:150px">用户名</th><th>显示名</th><th style="width:170px">上次登录</th><th style="width:80px">操作</th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td class="num"><?= (int) $u['id'] ?></td>
          <td class="t-title"><?= h($u['username']) ?><?= (int) $u['id'] === (int) $me['id'] ? ' <span class="pill" style="background:#dbeafe;color:#1d4ed8">当前</span>' : '' ?></td>
          <td><?= h((string) ($u['display_name'] ?? '—')) ?></td>
          <td class="num small"><?= h((string) ($u['last_login'] ?? '—')) ?></td>
          <td>
            <?php if ((int) $u['id'] !== (int) $me['id']): ?>
              <form method="post" onsubmit="return confirm('确定删除管理员「<?= h(addslashes($u['username'])) ?>」？')">
                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="op" value="del_user">
                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                <button class="btn-s danger" type="submit">删除</button>
              </form>
            <?php else: ?><span class="muted small">—</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="acard" style="margin-top:16px">
  <div class="acard-head"><h2>新增管理员</h2></div>
  <div class="acard-pad">
    <form class="aform" method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="op" value="add_user">
      <div class="grid-3">
        <div class="afield">
          <label>用户名</label>
          <input type="text" name="new_username" required>
        </div>
        <div class="afield">
          <label>密码</label>
          <input type="password" name="new_password" required minlength="8" autocomplete="new-password">
        </div>
        <div class="afield">
          <label>显示名</label>
          <input type="text" name="new_display">
        </div>
      </div>
      <div class="form-actions">
        <button class="btn-s primary" type="submit">创建管理员</button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/../inc/admin_footer.php'; ?>
