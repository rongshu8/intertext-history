<?php
/**
 * 用户中心 · 概览 / 我的投稿
 * ---------------------------------------------------------------------------
 * ?tab=mine   查看自己的投稿记录（默认）
 * ?id=123     查看某条投稿详情
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/submission.php';
require_login();

$me     = current_user();
$tab    = q('tab', 'mine');
$viewId = qi('id');

// ---------------------------------------------------------------------------
// 查看单条投稿详情
// ---------------------------------------------------------------------------
if ($viewId > 0) {
    $sub = get_submission($viewId);
    if (!$sub || (int) $sub['user_id'] !== (int) $me['id']) {
        flash('投稿不存在或无权查看。', 'err');
        header('Location: ' . link_to('user/index.php'));
        exit;
    }

    // 撤回操作
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && p('op') === 'withdraw') {
        if (!csrf_check()) {
            flash('会话已过期，请重试。', 'err');
        } else {
            $r = submission_withdraw($viewId, (int) $me['id']);
            flash($r['ok'] ? '投稿已撤回。' : $r['error'], $r['ok'] ? 'ok' : 'err');
        }
        header('Location: ' . link_to('user/index.php', ['id' => $viewId]));
        exit;
    }

    $p        = submission_payload($sub);
    $kinds    = submission_kinds();
    $statuses = submission_statuses();
    $stKey    = (string) $sub['status'];
    $st       = $statuses[$stKey] ?? ['未知', 'muted'];
    $pubLink  = null;
    if ($sub['kind'] === 'cn_node' && $sub['target_id']) {
        $pubLink = link_to('node.php', ['id' => $sub['target_id']]);
    } elseif ($sub['kind'] === 'world_event' && $sub['target_id']) {
        $pubLink = link_to('world.php', ['id' => $sub['target_id']]);
    } elseif ($sub['kind'] === 'relation' && $sub['target_id']) {
        $cnId = (int) ($p['cn_event_id'] ?? 0);
        $pubLink = link_to('node.php', ['id' => $cnId]);
    }

    $USER_NAV   = 'mine';
    $USER_TITLE = '投稿详情';
    require __DIR__ . '/../inc/user_header.php';
    ?>
    <div class="page-head">
      <h1>投稿详情</h1>
      <a class="btn-s" href="<?= h(link_to('user/index.php', ['tab' => 'mine'])) ?>">← 返回列表</a>
    </div>

    <div class="acard">
      <div class="acard-head">
        <h2><?= h($kinds[$sub['kind']] ?? $sub['kind']) ?></h2>
        <span class="pill sub-st-<?= h($st[1]) ?>"><?= h($st[0]) ?></span>
        <div class="spacer"></div>
        <span class="small muted">#<?= (int) $sub['id'] ?> · 提交于 <?= h((string) $sub['created_at']) ?></span>
      </div>
      <div class="acard-pad">
        <table class="atable">
          <tbody>
            <tr><td style="width:130px" class="muted">类型</td><td><?= h($kinds[$sub['kind']] ?? $sub['kind']) ?></td></tr>
            <tr><td class="muted">状态</td><td><?= h($st[0]) ?></td></tr>
            <tr><td class="muted">提交时间</td><td><?= h((string) $sub['created_at']) ?></td></tr>
            <?php if (!empty($sub['reviewed_at'])): ?>
              <tr><td class="muted">审核时间</td><td><?= h((string) $sub['reviewed_at']) ?></td></tr>
            <?php endif; ?>
            <?php if ($pubLink): ?>
              <tr><td class="muted">已发布</td>
                <td><a href="<?= h($pubLink) ?>" target="_blank">查看前台页面 ↗</a></td></tr>
            <?php endif; ?>
            <?php if (!empty($sub['review_note'])): ?>
              <tr><td class="muted">审核意见</td><td><?= h($sub['review_note']) ?></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="acard" style="margin-top:16px">
      <div class="acard-head"><h2>投稿内容</h2></div>
      <div class="acard-pad">
        <?php if ($sub['kind'] === 'relation'): ?>
          <?php
          $cn = get_cn_event((int) ($p['cn_event_id'] ?? 0));
          $we = get_world_event((int) ($p['world_event_id'] ?? 0));
          ?>
          <table class="atable">
            <tbody>
              <tr><td style="width:110px" class="muted">中国节点</td>
                <td><?= $cn ? h($cn['title']) . '（' . h(fmt_year((int) $cn['year'], false)) . '）' : '<span class="muted">（已删除）</span>' ?></td></tr>
              <tr><td class="muted">世界大事</td>
                <td><?= $we ? h($we['title']) . '（' . h(fmt_year((int) $we['year'], false)) . '）' : '<span class="muted">（已删除）</span>' ?></td></tr>
              <tr><td class="muted">关系类型</td><td><?= h(dict_relation_types()[$p['relation_type']]['name'] ?? $p['relation_type']) ?></td></tr>
              <tr><td class="muted">关联说明</td><td style="white-space:pre-wrap"><?= h((string) ($p['note'] ?? '')) ?></td></tr>
            </tbody>
          </table>
        <?php else: ?>
          <table class="atable">
            <tbody>
              <tr><td style="width:110px" class="muted">标题</td><td><strong><?= h((string) ($p['title'] ?? '')) ?></strong></td></tr>
              <tr><td class="muted">年份</td><td><?= h(fmt_year((int) ($p['year'] ?? 0), false)) ?>
                <?php if (!empty($p['year_end'])): ?>— <?= h(fmt_year((int) $p['year_end'], false)) ?><?php endif; ?></td></tr>
              <?php if ($sub['kind'] === 'cn_node'): ?>
                <tr><td class="muted">朝代</td>
                  <td><?= h(dynasty_by_id((int) ($p['dynasty_id'] ?? 0))['name'] ?? '—') ?></td></tr>
              <?php else: ?>
                <tr><td class="muted">区域</td><td><?= h(region_emoji((string) ($p['region'] ?? 'global'))) ?> <?= h(region_name((string) ($p['region'] ?? 'global'))) ?></td></tr>
              <?php endif; ?>
              <tr><td class="muted">主题</td><td><?= h(cat_name((string) ($p['category'] ?? 'politics'))) ?></td></tr>
              <?php if (!empty($p['place'])): ?>
                <tr><td class="muted">地点</td><td><?= h((string) $p['place']) ?></td></tr>
              <?php endif; ?>
              <?php if (!empty($p['summary'])): ?>
                <tr><td class="muted">概述</td><td><?= h((string) $p['summary']) ?></td></tr>
              <?php endif; ?>
              <?php if (!empty($p['detail'])): ?>
                <tr><td class="muted">详细</td><td style="white-space:pre-wrap"><?= h((string) $p['detail']) ?></td></tr>
              <?php endif; ?>
              <?php if (!empty($p['figures'])): ?>
                <tr><td class="muted">关键人物</td><td><?= h((string) $p['figures']) ?></td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($stKey === 'pending'): ?>
      <form method="post" style="margin-top:18px"
            onsubmit="return confirm('撤回后需要重新提交，确定撤回吗？')">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="op" value="withdraw">
        <button class="btn-s danger" type="submit">撤回这条投稿</button>
      </form>
    <?php endif; ?>

    <?php
    require __DIR__ . '/../inc/user_footer.php';
    exit;
}

// ---------------------------------------------------------------------------
// 列表视图
// ---------------------------------------------------------------------------
$statusFilter = q('status');
$statuses     = submission_statuses();
$kinds        = submission_kinds();
$counts       = user_submission_counts((int) $me['id']);
$page         = max(1, qi('page', 1));
$perPage      = 20;

$opt = ['user_id' => (int) $me['id']];
if (isset($statuses[$statusFilter])) {
    $opt['status'] = $statusFilter;
}
$total = count_submissions($opt);
$pages = max(1, (int) ceil($total / $perPage));
$page  = min($page, $pages);
$rows  = list_submissions($opt + ['limit' => $perPage, 'offset' => ($page - 1) * $perPage]);

$USER_NAV   = $tab === 'home' ? 'home' : 'mine';
$USER_TITLE = '用户中心';
require __DIR__ . '/../inc/user_header.php';
?>

<?php if (q('welcome') === '1'): ?>
  <div class="alert alert-ok">欢迎加入！你现在可以提交内容，管理员审核通过后会展示在前台。</div>
<?php endif; ?>

<div class="page-head">
  <h1>你好，<?= h($me['display_name'] ?: $me['username']) ?></h1>
  <div class="spacer"></div>
  <a class="btn-s primary" href="<?= h(link_to('user/submit.php')) ?>">+ 提交新投稿</a>
</div>

<div class="stat-grid">
  <div class="stat"><div class="n"><?= (int) ($counts['pending'] ?? 0) ?></div><div class="l">待审核</div></div>
  <div class="stat"><div class="n"><?= (int) ($counts['approved'] ?? 0) ?></div><div class="l">已通过</div></div>
  <div class="stat"><div class="n"><?= (int) ($counts['rejected'] ?? 0) ?></div><div class="l">已驳回</div></div>
  <div class="stat"><div class="n"><?= (int) ($counts['withdrawn'] ?? 0) ?></div><div class="l">已撤回</div></div>
</div>

<div class="acard">
  <div class="acard-head">
    <h2>我的投稿</h2>
    <div class="spacer"></div>
    <span class="small muted">共 <?= number_format($total) ?> 条</span>
  </div>

  <div class="afilter">
    <a class="btn-s <?= $statusFilter === '' ? 'primary' : '' ?>" href="<?= h(link_to('user/index.php', ['tab' => 'mine'])) ?>">全部</a>
    <?php foreach ($statuses as $k => $s): ?>
      <a class="btn-s <?= $statusFilter === $k ? 'primary' : '' ?>"
         href="<?= h(link_to('user/index.php', ['tab' => 'mine', 'status' => $k])) ?>">
        <?= h($s[0]) ?><?= isset($counts[$k]) && $counts[$k] ? ' (' . (int) $counts[$k] . ')' : '' ?>
      </a>
    <?php endforeach; ?>
  </div>

  <?php if (!$rows): ?>
    <div class="aempty">
      还没有投稿。<a href="<?= h(link_to('user/submit.php')) ?>" style="color:var(--cinnabar)">去提交第一条 →</a>
    </div>
  <?php else: ?>
    <div style="overflow-x:auto">
    <table class="atable">
      <thead>
        <tr>
          <th style="width:60px">#</th>
          <th style="width:90px">类型</th>
          <th>内容</th>
          <th style="width:80px">状态</th>
          <th style="width:150px">提交时间</th>
          <th style="width:66px">操作</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $s): ?>
        <?php
        $st = $statuses[$s['status']] ?? ['未知', 'muted'];
        ?>
        <tr>
          <td class="num"><?= (int) $s['id'] ?></td>
          <td class="nowrap small"><?= h($kinds[$s['kind']] ?? $s['kind']) ?></td>
          <td>
            <div class="t-title"><?= h(submission_brief($s)) ?></div>
            <?php if (!empty($s['review_note']) && $s['status'] === 'rejected'): ?>
              <div class="t-sum" style="color:#991b1b">驳回原因：<?= h((string) $s['review_note']) ?></div>
            <?php endif; ?>
          </td>
          <td><span class="pill sub-st-<?= h($st[1]) ?>"><?= h($st[0]) ?></span></td>
          <td class="num small"><?= h(substr((string) $s['created_at'], 0, 16)) ?></td>
          <td><a class="btn-s" href="<?= h(link_to('user/index.php', ['id' => $s['id']])) ?>">详情</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>

  <?php if ($pages > 1): ?>
    <div class="pager-bar">
      <?php if ($page > 1): ?>
        <a href="<?= h(link_to('user/index.php', ['tab' => 'mine', 'status' => $statusFilter, 'page' => $page - 1])) ?>">← 上一页</a>
      <?php endif; ?>
      <span class="cur"><?= $page ?> / <?= $pages ?></span>
      <?php if ($page < $pages): ?>
        <a href="<?= h(link_to('user/index.php', ['tab' => 'mine', 'status' => $statusFilter, 'page' => $page + 1])) ?>">下一页 →</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../inc/user_footer.php'; ?>
