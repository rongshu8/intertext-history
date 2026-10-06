<?php
/**
 * 后台公共布局
 * ---------------------------------------------------------------------------
 * 用法：
 *   require __DIR__ . '/../inc/bootstrap.php';
 *   require_login();                          // 鉴权放最前
 *   $PAGE_TITLE = '...'; $ADMIN_NAV = 'cn';
 *   require __DIR__ . '/../inc/admin_header.php';
 *   ... 内容 ...
 *   require __DIR__ . '/../inc/admin_footer.php';
 *
 * alink() 定义在 bootstrap.php，支持子目录部署。
 */

require_once __DIR__ . '/bootstrap.php';

$PAGE_TITLE = $PAGE_TITLE ?? '管理后台';
$ADMIN_NAV  = $ADMIN_NAV ?? '';
$user       = current_user();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($PAGE_TITLE) ?> · 管理后台</title>
<link rel="stylesheet" href="<?= h(asset('style.css')) ?>">
<link rel="stylesheet" href="<?= h(asset('admin.css')) ?>">
<link rel="stylesheet" href="<?= h(asset('responsive.css')) ?>">
</head>
<body class="admin-body">
<header class="admin-header">
  <div class="wrap admin-header-inner">
    <a class="admin-brand" href="<?= h(alink('index.php')) ?>">
      <span class="brand-mark small">互文</span>
      <strong>管理后台</strong>
    </a>
    <nav class="admin-nav">
      <a href="<?= h(alink('index.php')) ?>" class="<?= $ADMIN_NAV === 'dash' ? 'on' : '' ?>">概览</a>
      <a href="<?= h(alink('review.php')) ?>" class="<?= $ADMIN_NAV === 'review' ? 'on' : '' ?>">投稿审核<?php
        $__pending = 0;
        try { $__pending = (int) DB::fetchCol("SELECT COUNT(*) FROM submissions WHERE status = 'pending'"); } catch (Throwable $e) {}
        if ($__pending > 0): ?><span class="nav-badge"><?= $__pending ?></span><?php endif;
      ?></a>
      <a href="<?= h(alink('cn_events.php')) ?>" class="<?= $ADMIN_NAV === 'cn' ? 'on' : '' ?>">中国节点</a>
      <a href="<?= h(alink('world_events.php')) ?>" class="<?= $ADMIN_NAV === 'we' ? 'on' : '' ?>">世界大事</a>
      <a href="<?= h(alink('relations.php')) ?>" class="<?= $ADMIN_NAV === 'rel' ? 'on' : '' ?>">联动</a>
      <a href="<?= h(alink('dynasties.php')) ?>" class="<?= $ADMIN_NAV === 'dyn' ? 'on' : '' ?>">朝代</a>
      <a href="<?= h(alink('import.php')) ?>" class="<?= $ADMIN_NAV === 'imp' ? 'on' : '' ?>">导入</a>
      <a href="<?= h(alink('export.php')) ?>" class="<?= $ADMIN_NAV === 'export' ? 'on' : '' ?>">导出</a>
      <a href="<?= h(alink('account.php')) ?>" class="<?= $ADMIN_NAV === 'acc' ? 'on' : '' ?>">账户</a>
    </nav>
    <div class="admin-right">
      <a href="<?= h(alink('../index.php')) ?>" target="_blank" class="small">查看前台 ↗</a>
      <a href="<?= h(alink('logout.php')) ?>" class="small">退出</a>
    </div>
  </div>
</header>

<main class="wrap admin-main">
  <?php foreach (take_flash() as $f): ?>
    <div class="alert alert-<?= h($f['type']) ?>"><?= h($f['msg']) ?></div>
  <?php endforeach; ?>
