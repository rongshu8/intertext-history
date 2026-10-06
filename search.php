<?php
/**
 * 全站搜索：同时检索中国节点与世界大事
 */

// 未安装时导向安装向导（必须在 bootstrap 之前：那时数据库还连不上）
require_once __DIR__ . '/inc/install_gate.php';
require __DIR__ . '/inc/bootstrap.php';

$PAGE_TITLE = '搜索';
$ACTIVE     = 'search';
require __DIR__ . '/inc/layout_header.php';

$kw = q('q');
if ($kw === '') {
    echo '<div class="empty" style="padding:60px 20px">请输入搜索关键词。</div>';
    require __DIR__ . '/inc/layout_footer.php';
    exit;
}

$like = '%' . $kw . '%';

$cnRows = DB::fetchAll(
    'SELECT e.*, d.name AS dynasty_name, d.color AS dynasty_color
     FROM cn_events e LEFT JOIN dynasties d ON d.id = e.dynasty_id
     WHERE e.title LIKE ? OR e.summary LIKE ? OR e.detail LIKE ? OR e.figures LIKE ?
     ORDER BY e.year ASC LIMIT 60',
    [$like, $like, $like, $like]
);

$weRows = DB::fetchAll(
    'SELECT * FROM world_events
     WHERE title LIKE ? OR summary LIKE ? OR detail LIKE ? OR figures LIKE ? OR place LIKE ?
     ORDER BY year ASC LIMIT 60',
    [$like, $like, $like, $like, $like]
);

/** 在文本中高亮关键词 */
function hl(?string $text, string $kw): string
{
    $text = (string) $text;
    if ($text === '' || $kw === '') {
        return h($text);
    }
    $safe = h($text);
    $needle = h($kw);
    return str_ireplace($needle, '<mark>' . $needle . '</mark>', $safe);
}
?>

<div class="list-head">
  <h1>搜索「<?= h($kw) ?>」</h1>
  <p>在中国节点中找到 <?= count($cnRows) ?> 条，在世界大事中找到 <?= count($weRows) ?> 条<?= (count($cnRows) >= 60 || count($weRows) >= 60) ? '（各自最多显示 60 条）' : '' ?>。</p>
  <form class="search-box" action="<?= h(link_to('search.php')) ?>" method="get" style="max-width:520px">
    <input type="search" name="q" value="<?= h($kw) ?>" placeholder="换个关键词试试…" autocomplete="off">
    <button class="btn btn-primary" type="submit">搜索</button>
  </form>
</div>

<?php if ($cnRows): ?>
  <div class="sync-head" style="margin-top:30px">
    <h2 style="font-size:17px">🇨🇳 中国节点</h2>
    <span class="count"><?= count($cnRows) ?> 条</span>
  </div>
  <?php foreach ($cnRows as $e): ?>
    <div class="wl-row">
      <div class="wl-year"><?= h(fmt_year_range((int) $e['year'], !empty($e['year_end']) ? (int) $e['year_end'] : null, false)) ?></div>
      <div>
        <div class="wl-title"><a href="<?= h(link_to('node.php', ['id' => $e['id']])) ?>"><?= hl($e['title'], $kw) ?></a></div>
        <?php if (!empty($e['summary'])): ?><div class="wl-sum"><?= hl($e['summary'], $kw) ?></div><?php endif; ?>
        <div class="wl-meta">
          <?php if (!empty($e['dynasty_name'])): ?>
            <span class="tag tag-cat" style="color:<?= h($e['dynasty_color']) ?>"><?= h($e['dynasty_name']) ?></span>
          <?php endif; ?>
          <span class="tag tag-cat" style="color:<?= h(cat_color((string) $e['category'])) ?>"><?= h(cat_name((string) $e['category'])) ?></span>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php if ($weRows): ?>
  <div class="sync-head" style="margin-top:38px">
    <h2 style="font-size:17px">🌍 世界大事</h2>
    <span class="count"><?= count($weRows) ?> 条</span>
  </div>
  <?php foreach ($weRows as $w): ?>
    <div class="wl-row">
      <div class="wl-year"><?= h(fmt_year_range((int) $w['year'], !empty($w['year_end']) ? (int) $w['year_end'] : null, false)) ?></div>
      <div>
        <div class="wl-title"><a href="<?= h(link_to('world.php', ['id' => $w['id']])) ?>"><?= hl($w['title'], $kw) ?></a></div>
        <?php if (!empty($w['summary'])): ?><div class="wl-sum"><?= hl($w['summary'], $kw) ?></div><?php endif; ?>
        <div class="wl-meta">
          <span class="tag tag-cat" style="color:<?= h(cat_color((string) $w['category'])) ?>"><?= h(cat_name((string) $w['category'])) ?></span>
          <span class="tag tag-cat"><?= h(region_emoji((string) $w['region'])) ?> <?= h(region_name((string) $w['region'])) ?></span>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php if (!$cnRows && !$weRows): ?>
  <div class="empty">没有找到与「<?= h($kw) ?>」相关的内容。</div>
<?php endif; ?>

<?php require __DIR__ . '/inc/layout_footer.php'; ?>
