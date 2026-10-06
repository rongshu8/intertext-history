<?php
/**
 * 用户中心公共布局
 * ---------------------------------------------------------------------------
 * 用法：
 *   require __DIR__ . '/../inc/bootstrap.php';
 *   require_login();
 *   require __DIR__ . '/../inc/user_header.php';   // $USER_NAV = 'home'|'submit'|'mine'|'account'
 *   ... 内容 ...
 *   require __DIR__ . '/../inc/user_footer.php';
 */

require_once __DIR__ . '/bootstrap.php';
require_login();

$USER_NAV = $USER_NAV ?? '';
$USER_TITLE = $USER_TITLE ?? '用户中心';
$me = current_user();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($USER_TITLE) ?> · <?= h(c_site_name()) ?></title>
<link rel="stylesheet" href="<?= h(asset('style.css')) ?>">
<link rel="stylesheet" href="<?= h(asset('admin.css')) ?>">
<link rel="stylesheet" href="<?= h(asset('responsive.css')) ?>">
</head>
<body class="admin-body">
<header class="admin-header">
  <div class="wrap admin-header-inner">
    <a class="admin-brand" href="<?= h(link_to('user/index.php')) ?>">
      <span class="brand-mark small">互文</span>
      <strong>用户中心</strong>
    </a>
    <nav class="admin-nav">
      <a href="<?= h(link_to('user/index.php')) ?>" class="<?= $USER_NAV === 'home' ? 'on' : '' ?>">概览</a>
      <a href="<?= h(link_to('user/submit.php')) ?>" class="<?= $USER_NAV === 'submit' ? 'on' : '' ?>">我要投稿</a>
      <a href="<?= h(link_to('user/index.php', ['tab' => 'mine'])) ?>" class="<?= $USER_NAV === 'mine' ? 'on' : '' ?>">我的投稿</a>
      <a href="<?= h(link_to('user/account.php')) ?>" class="<?= $USER_NAV === 'account' ? 'on' : '' ?>">账户</a>
      <?php if (is_admin()): ?>
        <a href="<?= h(alink('index.php')) ?>">管理后台</a>
      <?php endif; ?>
    </nav>
    <div class="admin-right">
      <span class="small" style="color:#94a3b8">
        <?= h($me['display_name'] ?: $me['username']) ?>
      </span>
      <a href="<?= h(link_to('index.php')) ?>" target="_blank" class="small">前台 ↗</a>
      <a href="<?= h(alink('logout.php')) ?>" class="small">退出</a>
    </div>
  </div>
</header>

<main class="wrap admin-main">
  <?php foreach (take_flash() as $f): ?>
    <div class="alert alert-<?= h($f['type']) ?>"><?= h($f['msg']) ?></div>
  <?php endforeach; ?>
