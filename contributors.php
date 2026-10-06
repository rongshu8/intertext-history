<?php
/**
 * 贡献名单
 * ---------------------------------------------------------------------------
 * 展示「谁贡献了哪些条目」。数据来源是 submissions 表里**已通过审核**的投稿：
 *
 *   users.display_name  → 贡献者昵称（为空时退回 username）
 *   submissions.payload  → JSON，title 字段是条目标题（见 inc/submission.php）
 *   submissions.target_id → 审核通过后写进正式表的主键，可反查详情页
 *
 * 口径说明（页面上也写出来了）：
 *   - 只列 approved；pending / rejected / withdrawn 不公开
 *   - 按「已通过的条目数」倒序，同数按最近贡献时间
 *   - target_id 为空的（例如关联内容被删）仍列出标题，但不给链接
 */

// 未安装时导向安装向导（必须在 bootstrap 之前：那时数据库还连不上）
require_once __DIR__ . '/inc/install_gate.php';
require_once __DIR__ . '/inc/bootstrap.php';

$PAGE_TITLE = '贡献';
$ACTIVE     = 'contribute';
require __DIR__ . '/inc/layout_header.php';

// ---------------------------------------------------------------------------
// 取数据：一次查完，避免按人循环查（N+1）
// ---------------------------------------------------------------------------
$rows = DB::fetchAll(
    "SELECT s.id            AS sid,
            s.kind          AS kind,
            s.payload       AS payload,
            s.target_id     AS target_id,
            s.reviewed_at   AS reviewed_at,
            u.id            AS uid,
            u.username      AS username,
            u.display_name  AS display_name
     FROM submissions s
     JOIN users u ON u.id = s.user_id
     WHERE s.status = 'approved'
     ORDER BY s.reviewed_at DESC, s.id DESC"
);

$KIND_LABEL = [
    'cn_node'     => '中国节点',
    'world_event' => '世界大事',
    'relation'    => '中外联动',
];

// 按贡献者归并
$people = [];
foreach ($rows as $r) {
    $name = trim((string) ($r['display_name'] ?? ''));
    if ($name === '') {
        $name = (string) $r['username'];
    }
    $uid = (int) $r['uid'];

    $title = '';
    $year  = null;
    $p = json_decode((string) $r['payload'], true);
    if (is_array($p)) {
        if (isset($p['title'])) $title = (string) $p['title'];
        if (isset($p['year']) && is_numeric($p['year'])) $year = (int) $p['year'];
    }
    if ($title === '') $title = '（标题缺失）';

    if (!isset($people[$uid])) {
        $people[$uid] = [
            'name'  => $name,
            'items' => [],
        ];
    }
    $people[$uid]['items'][] = [
        'sid'    => (int) $r['sid'],
        'kind'   => (string) $r['kind'],
        'title'  => $title,
        'year'   => $year,
        'target' => $r['target_id'] !== null ? (int) $r['target_id'] : 0,
        'at'     => (string) $r['reviewed_at'],
    ];
}

// 按条目数倒序
uasort($people, function ($a, $b) {
    $d = count($b['items']) <=> count($a['items']);
    return $d !== 0 ? $d : strcmp((string) end($a['items'])['at'], (string) end($b['items'])['at']);
});

$totalItems = count($rows);
$totalPeople = count($people);

/** 把 target_id 变成可点链接（不同 kind 落在不同表） */
function contrib_link(array $it): ?string
{
    if ($it['target'] <= 0) return null;
    if ($it['kind'] === 'cn_node')     return link_to('node.php', ['id' => $it['target']]);
    if ($it['kind'] === 'world_event') return link_to('world.php', ['id' => $it['target']]);
    return null; // relation 无独立详情页
}
?>

<div class="list-head">
  <h1>贡献</h1>
  <p>这里列的是读者补充的内容。站点现有条目由站方整理，
     你提交的内容审核通过后，会连同昵称一起出现在这里。</p>
</div>

<?php if (!$people): ?>
  <?php $st = site_stats(); ?>
  <div class="card" style="padding:32px 28px;text-align:center">
    <h2 style="font-size:16px;margin-bottom:10px">还没有通过审核的投稿</h2>
    <p style="margin:0 auto 18px;max-width:460px;font-size:14.5px;line-height:1.95;color:var(--ink-2)">
      站点现有的 <?= number_format($st['cn']) ?> 个中国节点与
      <?= number_format($st['world']) ?> 条世界大事由站方整理，
      这里是留给读者的补充区。
    </p>
    <a class="btn btn-cinnabar" href="<?= h(link_to('contribute.php')) ?>"
       style="padding:10px 30px;font-size:15px">我要贡献</a>
  </div>
<?php else: ?>
  <div class="hero-stats" style="margin-bottom:22px">
    <div><b><?= number_format($totalPeople) ?></b>位贡献者</div>
    <div><b><?= number_format($totalItems) ?></b>条已收录条目</div>
  </div>

  <?php foreach ($people as $uid => $p): ?>
    <div class="card" style="padding:20px 24px;margin-bottom:16px">
      <h2 style="font-size:16px;margin-bottom:4px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <?= h($p['name']) ?>
        <span style="font-size:13px;font-weight:400;color:var(--muted)">
          贡献 <?= count($p['items']) ?> 条
        </span>
      </h2>

      <ul style="list-style:none;margin:12px 0 0;padding:0">
        <?php foreach ($p['items'] as $it): ?>
          <?php
            $url  = contrib_link($it);
            $kind = $KIND_LABEL[$it['kind']] ?? '内容';
          ?>
          <li style="display:flex;gap:10px;align-items:baseline;
                     padding:7px 0;border-top:1px solid var(--line);
                     font-size:14.5px;line-height:1.7">
            <span class="tag tag-cat" style="flex:0 0 auto"><?= h($kind) ?></span>
            <?php if ($it['year'] !== null): ?>
              <span class="tag-year" style="flex:0 0 auto"><?= h(fmt_year($it['year'], false)) ?></span>
            <?php endif; ?>
            <span style="flex:1 1 auto;min-width:0">
              <?php if ($url): ?>
                <a href="<?= h($url) ?>"><?= h($it['title']) ?></a>
              <?php else: ?>
                <?= h($it['title']) ?>
              <?php endif; ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endforeach; ?>

  <div style="text-align:center;padding:24px 0 8px">
    <a class="btn btn-cinnabar" href="<?= h(link_to('contribute.php')) ?>"
       style="padding:10px 30px;font-size:15px">我要贡献</a>
    <p style="margin:12px 0 0;font-size:13px;color:var(--muted)">
      提交的内容需经管理员审核，通过后才会出现在这里与时间轴上。
    </p>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/inc/layout_footer.php'; ?>
