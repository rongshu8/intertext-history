<?php
/**
 * 管理员 · 投稿审核台
 * ---------------------------------------------------------------------------
 * 无参数  待审列表（可按状态/类型筛选）
 * ?id=123  审核详情（通过 / 驳回）
 * ?id=123&preview=1  只读预览（不改状态）
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/submission.php';
require_admin();

$me       = current_user();
$kinds    = submission_kinds();
$statuses = submission_statuses();

// ---------------------------------------------------------------------------
// 审核详情 + 处理
// ---------------------------------------------------------------------------
$viewId = qi('id');
if ($viewId > 0) {
    $sub = get_submission($viewId);
    if (!$sub) {
        flash('投稿不存在。', 'err');
        header('Location: ' . alink('review.php'));
        exit;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!csrf_check()) {
            flash('会话已过期，请重试。', 'err');
        } else {
            $op   = p('op');
            $note = p('review_note');

            if ($op === 'approve') {
                $r = submission_publish($viewId, (int) $me['id'], $note);
                if ($r['ok']) {
                    $msg = '已通过，内容已发布到前台。';
                    if (!empty($r['warning'])) {
                        $msg .= ' ' . $r['warning'];
                    }
                    flash($msg, !empty($r['warning']) ? 'warn' : 'ok');
                    header('Location: ' . alink('review.php', ['status' => 'pending']));
                    exit;
                }
                flash($r['error'], 'err');
            } elseif ($op === 'reject') {
                $note = $note !== '' ? $note : '内容不符合收录标准。';
                $r = submission_reject($viewId, (int) $me['id'], $note);
                if ($r['ok']) {
                    flash('已驳回，并附上了审核意见。');
                    header('Location: ' . alink('review.php', ['status' => 'pending']));
                    exit;
                }
                flash($r['error'], 'err');
            } else {
                flash('未知的操作。', 'err');
            }
        }
        header('Location: ' . alink('review.php', ['id' => $viewId]));
        exit;
    }

    $p        = submission_payload($sub);
    $st       = $statuses[$sub['status']] ?? ['未知', 'muted'];
    $isPending = $sub['status'] === 'pending';

    // 预取联动两端
    $cnEv = $weEv = null;
    if ($sub['kind'] === 'relation') {
        $cnEv = get_cn_event((int) ($p['cn_event_id'] ?? 0));
        $weEv = get_world_event((int) ($p['world_event_id'] ?? 0));
    }

    // 查重：同标题是否已有正式内容
    $dupeCn = $dupeWe = null;
    if ($sub['kind'] === 'cn_node' && !empty($p['title'])) {
        $dupeCn = DB::fetchOne('SELECT id, year FROM cn_events WHERE title = ?', [$p['title']]);
    }
    if ($sub['kind'] === 'world_event' && !empty($p['title'])) {
        $dupeWe = DB::fetchOne('SELECT id, year FROM world_events WHERE title = ?', [$p['title']]);
    }

    $USER_NAV   = 'review';
    $USER_TITLE = '审核投稿';
    require __DIR__ . '/../inc/admin_header.php';
    ?>

    <div class="page-head">
      <h1>审核投稿 #<?= (int) $sub['id'] ?></h1>
      <span class="pill sub-st-<?= h($st[1]) ?>"><?= h($st[0]) ?></span>
      <div class="spacer"></div>
      <a class="btn-s" href="<?= h(alink('review.php', ['status' => $sub['status'] === 'pending' ? 'pending' : $sub['status']])) ?>">← 返回列表</a>
    </div>

    <div class="acard">
      <div class="acard-head"><h2>投稿信息</h2></div>
      <div class="acard-pad">
        <table class="atable">
          <tbody>
            <tr><td style="width:110px" class="muted">类型</td><td><?= h($kinds[$sub['kind']] ?? $sub['kind']) ?></td></tr>
            <tr><td class="muted">提交者</td>
              <td><?= h($sub['display_name'] ?: $sub['username']) ?>
                <span class="muted small">(@<?= h((string) $sub['username']) ?>)</span></td></tr>
            <tr><td class="muted">提交时间</td><td><?= h((string) $sub['created_at']) ?></td></tr>
            <?php if (!empty($sub['reviewed_at'])): ?>
              <tr><td class="muted">审核时间</td><td><?= h((string) $sub['reviewed_at']) ?></td></tr>
              <tr><td class="muted">审核人</td>
                <td>#<?= (int) $sub['reviewer_id'] ?> ·
                  <?= h((string) (DB::fetchOne('SELECT display_name, username FROM users WHERE id = ?', [(int) $sub['reviewer_id']])['display_name'] ?? '—')) ?>
                </td></tr>
            <?php endif; ?>
            <?php if (!empty($sub['review_note'])): ?>
              <tr><td class="muted">审核意见</td><td><?= h((string) $sub['review_note']) ?></td></tr>
            <?php endif; ?>
            <?php if (!empty($sub['target_id'])): ?>
              <tr><td class="muted">已发布为</td>
                <td>
                  <?php if ($sub['kind'] === 'cn_node'): ?>
                    <a href="<?= h(link_to('node.php', ['id' => $sub['target_id']])) ?>" target="_blank">节点 #<?= (int) $sub['target_id'] ?> ↗</a>
                  <?php elseif ($sub['kind'] === 'world_event'): ?>
                    <a href="<?= h(link_to('world.php', ['id' => $sub['target_id']])) ?>" target="_blank">大事 #<?= (int) $sub['target_id'] ?> ↗</a>
                  <?php else: ?>
                    <a href="<?= h(link_to('node.php', ['id' => $p['cn_event_id'] ?? 0])) ?>" target="_blank">查看节点 ↗</a>
                  <?php endif; ?>
                </td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($dupeCn || $dupeWe): ?>
      <div class="alert alert-warn" style="margin-top:16px">
        <b>查重提示</b>：已有同标题内容
        <?php if ($dupeCn): ?>
          <a href="<?= h(link_to('node.php', ['id' => $dupeCn['id']])) ?>" target="_blank">#<?= (int) $dupeCn['id'] ?>（<?= h(fmt_year((int) $dupeCn['year'], false)) ?>）</a>
        <?php endif; ?>
        <?php if ($dupeWe): ?>
          <a href="<?= h(link_to('world.php', ['id' => $dupeWe['id']])) ?>" target="_blank">#<?= (int) $dupeWe['id'] ?>（<?= h(fmt_year((int) $dupeWe['year'], false)) ?>）</a>
        <?php endif; ?>
        —— 通过时会自动改标题为「…（补充）」而非覆盖。
      </div>
    <?php endif; ?>

    <div class="acard" style="margin-top:16px">
      <div class="acard-head"><h2>投稿内容</h2></div>
      <div class="acard-pad">
        <?php if ($sub['kind'] === 'relation'): ?>
          <div class="region-head" style="margin-bottom:12px">中国节点</div>
          <?php if ($cnEv): ?>
            <table class="atable" style="margin-bottom:18px">
              <tbody>
                <tr><td style="width:90px" class="muted">标题</td><td><strong><?= h((string) $cnEv['title']) ?></strong></td></tr>
                <tr><td class="muted">年份</td><td><?= h(fmt_year((int) $cnEv['year'], false)) ?></td></tr>
                <tr><td class="muted">概述</td><td><?= h((string) ($cnEv['summary'] ?? '')) ?></td></tr>
              </tbody>
            </table>
            <a class="btn-s" href="<?= h(link_to('node.php', ['id' => $cnEv['id']])) ?>" target="_blank">查看完整节点 ↗</a>
          <?php else: ?>
            <div class="alert alert-err">关联的中国节点已不存在，无法发布此投稿。</div>
          <?php endif; ?>

          <div class="region-head" style="margin:20px 0 12px">世界大事</div>
          <?php if ($weEv): ?>
            <table class="atable" style="margin-bottom:18px">
              <tbody>
                <tr><td style="width:90px" class="muted">标题</td><td><strong><?= h((string) $weEv['title']) ?></strong></td></tr>
                <tr><td class="muted">年份</td><td><?= h(fmt_year((int) $weEv['year'], false)) ?></td></tr>
                <tr><td class="muted">概述</td><td><?= h((string) ($weEv['summary'] ?? '')) ?></td></tr>
              </tbody>
            </table>
            <a class="btn-s" href="<?= h(link_to('world.php', ['id' => $weEv['id']])) ?>" target="_blank">查看完整大事 ↗</a>
          <?php else: ?>
            <div class="alert alert-err">关联的世界大事已不存在，无法发布此投稿。</div>
          <?php endif; ?>

          <div class="region-head" style="margin:20px 0 12px">投稿的关联说明</div>
          <table class="atable">
            <tbody>
              <tr><td style="width:90px" class="muted">关系类型</td>
                <td><?= h(dict_relation_types()[$p['relation_type']]['name'] ?? ($p['relation_type'] ?? '')) ?></td></tr>
              <tr><td class="muted">说明</td><td style="white-space:pre-wrap"><?= h((string) ($p['note'] ?? '')) ?></td></tr>
            </tbody>
          </table>

        <?php else: ?>
          <table class="atable">
            <tbody>
              <tr><td style="width:110px" class="muted">标题</td><td><strong style="font-size:15px"><?= h((string) ($p['title'] ?? '')) ?></strong></td></tr>
              <tr><td class="muted">年份</td>
                <td><?= h(fmt_year((int) ($p['year'] ?? 0), false)) ?>
                  <?php if (!empty($p['year_end'])): ?>— <?= h(fmt_year((int) $p['year_end'], false)) ?><?php endif; ?>
                  <?php if (!empty($p['month'])): ?>
                    <span class="muted">（<?= (int) $p['month'] ?>月<?= !empty($p['day']) ? (int) $p['day'] . '日' : '' ?>）</span>
                  <?php endif; ?></td></tr>
              <?php if ($sub['kind'] === 'cn_node'): ?>
                <tr><td class="muted">朝代</td>
                  <td><?= h(dynasty_by_id((int) ($p['dynasty_id'] ?? 0))['name'] ?? '（无效）') ?></td></tr>
              <?php else: ?>
                <tr><td class="muted">区域</td>
                  <td><?= h(region_emoji((string) ($p['region'] ?? 'global'))) ?> <?= h(region_name((string) ($p['region'] ?? 'global'))) ?></td></tr>
              <?php endif; ?>
              <tr><td class="muted">主题</td><td><?= h(cat_name((string) ($p['category'] ?? 'politics'))) ?></td></tr>
              <tr><td class="muted">重要度</td><td><?= (int) ($p['importance'] ?? 3) ?> / 5</td></tr>
              <?php if ($sub['kind'] === 'cn_node' && !empty($p['is_key'])): ?>
                <tr><td class="muted">标记</td><td><span class="pill key">关键节点</span></td></tr>
              <?php endif; ?>
              <?php if (!empty($p['place'])): ?>
                <tr><td class="muted">地点</td><td><?= h((string) $p['place']) ?></td></tr>
              <?php endif; ?>
              <?php if (!empty($p['summary'])): ?>
                <tr><td class="muted">一句话概述</td><td><?= h((string) $p['summary']) ?></td></tr>
              <?php endif; ?>
              <?php if (!empty($p['detail'])): ?>
                <tr><td class="muted">详细描述</td><td style="white-space:pre-wrap"><?= h((string) $p['detail']) ?></td></tr>
              <?php endif; ?>
              <?php if (!empty($p['figures'])): ?>
                <tr><td class="muted">关键人物</td><td><?= h((string) $p['figures']) ?></td></tr>
              <?php endif; ?>
              <?php if (!empty($p['source'])): ?>
                <tr><td class="muted">来源参考</td><td><?= h((string) $p['source']) ?></td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($isPending): ?>
      <div class="acard" style="margin-top:16px">
        <div class="acard-head"><h2>审核处理</h2></div>
        <div class="acard-pad">
          <form method="post" class="aform">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <div class="afield">
              <label>审核意见</label>
              <textarea name="review_note" rows="3" maxlength="500"
                        placeholder="驳回时必填（会展示给提交者）；通过时可选，用于记录判断依据"></textarea>
            </div>
            <div class="form-actions">
              <button class="btn-s primary" type="submit" name="op" value="approve"
                      onclick="return confirm('通过后内容将立即发布到前台，确定吗？')">
                ✓ 通过并发布
              </button>
              <button class="btn-s danger" type="submit" name="op" value="reject"
                      onclick="return confirm('确定驳回这条投稿吗？')">
                ✕ 驳回
              </button>
              <div class="spacer"></div>
              <a class="btn-s" href="<?= h(alink('review.php')) ?>">取消</a>
            </div>
          </form>
        </div>
      </div>
    <?php else: ?>
      <div class="alert alert-info" style="margin-top:16px">
        该投稿已处理（<?= h($st[0]) ?>），无法重复操作。
      </div>
    <?php endif; ?>

    <?php
    require __DIR__ . '/../inc/admin_footer.php';
    exit;
}

// ---------------------------------------------------------------------------
// 列表视图
// ---------------------------------------------------------------------------
$statusFilter = q('status', 'pending');
$kindFilter   = q('kind');
if (!isset($statuses[$statusFilter])) {
    $statusFilter = 'pending';
}

$opt = [];
if ($statusFilter !== 'all') {
    $opt['status'] = $statusFilter;
}
if (isset($kinds[$kindFilter])) {
    $opt['kind'] = $kindFilter;
}

$totalAll = count_submissions([]);
$cntPending = count_submissions(['status' => 'pending']);
$cntApproved = count_submissions(['status' => 'approved']);
$cntRejected = count_submissions(['status' => 'rejected']);

$page    = max(1, qi('page', 1));
$perPage = 30;
$pages   = max(1, (int) ceil($totalAll / $perPage));
$page    = min($page, $pages);
$rows    = list_submissions($opt + ['limit' => $perPage, 'offset' => ($page - 1) * $perPage]);

$USER_NAV   = 'review';
$USER_TITLE = '投稿审核';
require __DIR__ . '/../inc/admin_header.php';
?>

<div class="page-head">
  <h1>投稿审核</h1>
  <div class="spacer"></div>
  <?php if ($cntPending > 0): ?>
    <span class="pill" style="background:#fee2e2;color:#b91c1c"><?= $cntPending ?> 条待处理</span>
  <?php endif; ?>
</div>

<div class="stat-grid">
  <div class="stat"><div class="n" style="color:var(--cinnabar)"><?= $cntPending ?></div><div class="l">待审核</div></div>
  <div class="stat"><div class="n" style="color:#059669"><?= $cntApproved ?></div><div class="l">已通过</div></div>
  <div class="stat"><div class="n" style="color:#a16207"><?= $cntRejected ?></div><div class="l">已驳回</div></div>
  <div class="stat"><div class="n"><?= $totalAll ?></div><div class="l">投稿总数</div></div>
</div>

<div class="acard">
  <div class="afilter">
    <span class="filter-label" style="font-size:12px;color:#6b7280;margin-right:2px">状态</span>
    <a class="btn-s <?= $statusFilter === 'pending' ? 'primary' : '' ?>" href="<?= h(alink('review.php', ['status' => 'pending'])) ?>">待审核<?= $cntPending ? " ($cntPending)" : '' ?></a>
    <a class="btn-s <?= $statusFilter === 'approved' ? 'primary' : '' ?>" href="<?= h(alink('review.php', ['status' => 'approved'])) ?>">已通过<?= $cntApproved ? " ($cntApproved)" : '' ?></a>
    <a class="btn-s <?= $statusFilter === 'rejected' ? 'primary' : '' ?>" href="<?= h(alink('review.php', ['status' => 'rejected'])) ?>">已驳回<?= $cntRejected ? " ($cntRejected)" : '' ?></a>
    <a class="btn-s <?= $statusFilter === 'all' ? 'primary' : '' ?>" href="<?= h(alink('review.php', ['status' => 'all'])) ?>">全部</a>
    <span class="spacer"></span>
    <span class="filter-label" style="font-size:12px;color:#6b7280;margin-right:2px">类型</span>
    <a class="btn-s <?= $kindFilter === '' ? 'primary' : '' ?>" href="<?= h(alink('review.php', ['status' => $statusFilter])) ?>">全部类型</a>
    <?php foreach ($kinds as $k => $label): ?>
      <a class="btn-s <?= $kindFilter === $k ? 'primary' : '' ?>"
         href="<?= h(alink('review.php', ['status' => $statusFilter, 'kind' => $k])) ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (!$rows): ?>
    <div class="aempty">
      <?php if ($statusFilter === 'pending'): ?>
        ✓ 没有待审核的投稿
      <?php else: ?>
        该筛选下没有投稿
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div style="overflow-x:auto">
    <table class="atable">
      <thead>
        <tr>
          <th style="width:56px">#</th>
          <th style="width:88px">类型</th>
          <th>内容</th>
          <th style="width:110px">提交者</th>
          <th style="width:80px">状态</th>
          <th style="width:130px">提交时间</th>
          <th style="width:66px">操作</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $s): ?>
        <?php $st = $statuses[$s['status']] ?? ['未知', 'muted']; ?>
        <tr>
          <td class="num"><?= (int) $s['id'] ?></td>
          <td class="nowrap small"><?= h($kinds[$s['kind']] ?? $s['kind']) ?></td>
          <td><div class="t-title"><?= h(submission_brief($s)) ?></div></td>
          <td class="nowrap small"><?= h($s['display_name'] ?: $s['username']) ?></td>
          <td><span class="pill sub-st-<?= h($st[1]) ?>"><?= h($st[0]) ?></span></td>
          <td class="num small"><?= h(substr((string) $s['created_at'], 0, 16)) ?></td>
          <td>
            <a class="btn-s <?= $s['status'] === 'pending' ? 'primary' : '' ?>"
               href="<?= h(alink('review.php', ['id' => $s['id']])) ?>">
              <?= $s['status'] === 'pending' ? '审核' : '查看' ?>
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>

  <?php if ($pages > 1): ?>
    <div class="pager-bar">
      <?php if ($page > 1): ?>
        <a href="<?= h(alink('review.php', ['status' => $statusFilter, 'kind' => $kindFilter, 'page' => $page - 1])) ?>">← 上一页</a>
      <?php endif; ?>
      <span class="cur"><?= $page ?> / <?= $pages ?></span>
      <?php if ($page < $pages): ?>
        <a href="<?= h(alink('review.php', ['status' => $statusFilter, 'kind' => $kindFilter, 'page' => $page + 1])) ?>">下一页 →</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../inc/admin_footer.php'; ?>
