<?php
/**
 * 前台公共布局
 * ---------------------------------------------------------------------------
 * 用法：
 *   require __DIR__ . '/inc/bootstrap.php';       // 依赖 + URL 助手
 *   $PAGE_TITLE = '页面标题';
 *   $ACTIVE     = 'index' | 'world' | 'search' | 'about';
 *   require __DIR__ . '/inc/layout_header.php';   // 输出 <head> 到 <main> 开
 *   ... 页面内容 ...
 *   require __DIR__ . '/inc/layout_footer.php';   // 闭合并输出 footer
 *
 * 注意：bootstrap.php 已加载依赖与 site_base()/link_to()/asset()，
 * 本文件不再重复 require，也不重复定义这些函数。
 */

require_once __DIR__ . '/bootstrap.php';

$PAGE_TITLE = $PAGE_TITLE ?? c_site_name();
$ACTIVE     = $ACTIVE ?? '';
$BASE       = site_base();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($PAGE_TITLE) ?> · <?= h(c_site_name()) ?></title>
<meta name="description" content="<?= h(c_site_name()) ?> — <?= h(c_site_name()) ?>">
<link rel="stylesheet" href="<?= h(asset('style.css')) ?>">
<link rel="stylesheet" href="<?= h(asset('timeline.css')) ?>">
<link rel="stylesheet" href="<?= h(asset('responsive.css')) ?>">
</head>
<body>
<header class="site-header">
  <div class="wrap header-inner">
    <a class="brand" href="<?= h(link_to('index.php')) ?>">
      <span class="brand-mark">互文</span>
      <span class="brand-text">
        <strong>世界同期大事录</strong>
        <em>以中国节点为轴，看见同一刻的世界</em>
      </span>
    </a>
    <nav class="site-nav">
      <a href="<?= h(link_to('index.php')) ?>" class="<?= $ACTIVE === 'index' ? 'on' : '' ?>">时间轴</a>
      <a href="<?= h(link_to('world.php')) ?>" class="<?= $ACTIVE === 'world' ? 'on' : '' ?>">世界大事</a>
      <a href="<?= h(link_to('about.php')) ?>" class="<?= $ACTIVE === 'about' ? 'on' : '' ?>">关于</a>
      <?php if (is_admin()): ?>
        <a href="<?= h(alink('index.php')) ?>" class="admin-link">管理</a>
      <?php elseif (is_logged_in()): ?>
        <a href="<?= h(link_to('user/index.php')) ?>" class="admin-link">我的贡献</a>
      <?php else: ?>
        <a href="<?= h(link_to('contributors.php')) ?>" class="admin-link">贡献</a>
      <?php endif; ?>
      <?php if (!is_logged_in()): ?>
        <a href="<?= h(alink('login.php')) ?>">登录</a>
      <?php endif; ?>
    </nav>
  </div>
</header>

<main class="wrap">
