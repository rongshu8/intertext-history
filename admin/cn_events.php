<?php
/**
 * 中国节点 · 列表 / 新增 / 编辑 / 删除
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_admin();

$action = q('action');
$id     = qi('id');

// ===========================================================================
// 写操作
// ===========================================================================
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        flash('会话已过期，请重试。', 'err');
        header('Location: ' . alink('cn_events.php'));;
        exit;
    }
    $op = p('op');

    if ($op === 'delete') {
        $did = pint('id');
        DB::transaction(function () use ($did) {
            DB::exec('DELETE FROM relations WHERE cn_event_id = ?', [$did]);
            DB::exec('DELETE FROM cn_events WHERE id = ?', [$did]);
        });
        flash('节点已删除。');
        header('Location: ' . alink('cn_events.php'));;
        exit;
    }

    if ($op === 'save') {
        $eid = pint('id');
        $data = [
            'dynasty_id' => pint('dynasty_id'),
            'title'      => p('title'),
            'year'       => pint('year'),
            'year_end'   => empty($_POST['year_end']) ? null : pint('year_end'),
            'month'      => empty($_POST['month']) ? null : pint('month'),
            'day'        => empty($_POST['day']) ? null : pint('day'),
            'category'   => p('category') ?: 'politics',
            'place'      => p('place') ?: null,
            'summary'    => p('summary') ?: null,
            'detail'     => p('detail') ?: null,
            'figures'    => p('figures') ?: null,
            'importance' => max(1, min(5, pint('importance', 3))),
            'is_key'     => isset($_POST['is_key']) ? 1 : 0,
        ];

        if ($data['title'] === '' || $data['dynasty_id'] === 0) {
            flash('标题和朝代为必填项。', 'err');
            header('Location: ' . ($eid ? alink('cn_events.php', ['action' => 'edit', 'id' => $eid]) : alink('cn_events.php', ['action' => 'new'])));
            exit;
        }
        // year_end 不能早于 year
        if ($data['year_end'] !== null && $data['year_end'] < $data['year']) {
            $data['year_end'] = $data['year'];
        }

        if ($eid > 0) {
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($data)));
            DB::exec("UPDATE cn_events SET {$sets} WHERE id = ?", array_merge(array_values($data), [$eid]));
            flash('节点已更新。');
        } else {
            $cols = implode(', ', array_keys($data));
            $qs   = implode(', ', array_fill(0, count($data), '?'));
            DB::exec("INSERT INTO cn_events ({$cols}, created_at) VALUES ({$qs}, ' . SQL_NOW . ')", array_values($data));
            $eid = DB::lastInsertId();
            flash('节点已创建。');
        }
        header('Location: ' . alink('cn_events.php', ['action' => 'edit', 'id' => $eid]));
        exit;
    }
}

$PAGE_TITLE = '中国节点';
$ADMIN_NAV  = 'cn';
require __DIR__ . '/../inc/admin_header.php';

$dynasties = all_dynasties();
$cats      = dict_categories();

// ===========================================================================
// 编辑 / 新增表单
// ===========================================================================
if ($action === 'new' || $action === 'edit') {
    $e = [
        'id' => 0, 'dynasty_id' => 0, 'title' => '', 'year' => 0, 'year_end' => null,
        'month' => null, 'day' => null, 'category' => 'politics', 'place' => '',
        'summary' => '', 'detail' => '', 'figures' => '', 'importance' => 3, 'is_key' => 0,
    ];
    if ($action === 'edit') {
        $row = get_cn_event($id);
        if (!$row) { flash('节点不存在。', 'err'); header('Location: ' . alink('cn_events.php'));; exit; }
        $e = $row;
    } elseif (!empty($_GET['year'])) {
        $e['year'] = qi('year');
        $e['dynasty_id'] = (int) ($_GET['dynasty_id'] ?? 0);
    }
    $isEdit = $action === 'edit';
    ?>
    <div class="page-head">
      <h1><?= $isEdit ? '编辑节点' : '新增中国节点' ?></h1>
      <div class="spacer"></div>
      <a class="btn-s" href="<?= h(alink('cn_events.php')) ?>">← 返回列表</a>
      <?php if ($isEdit): ?>
        <a class="btn-s" href="<?= h(link_to('../node.php', ['id' => $e['id']])) ?>" target="_blank">前台预览 ↗</a>
      <?php endif; ?>
    </div>

    <form class="acard acard-pad" method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="op" value="save">
      <input type="hidden" name="id" value="<?= (int) $e['id'] ?>">

      <div class="aform">
        <div class="afield">
          <label>标题 <span style="color:#dc2626">*</span></label>
          <input type="text" name="title" value="<?= h((string) $e['title']) ?>" required
                 placeholder="如：秦始皇统一六国，建立中央集权帝国">
        </div>

        <div class="grid-4">
          <div class="afield">
            <label>朝代 <span style="color:#dc2626">*</span></label>
            <select name="dynasty_id" required>
              <option value="">— 选择朝代 —</option>
              <?php foreach ($dynasties as $d): ?>
                <option value="<?= (int) $d['id'] ?>" <?= (int) $e['dynasty_id'] === (int) $d['id'] ? 'selected' : '' ?>>
                  <?= h($d['name']) ?>（<?= h(fmt_year((int) $d['start_year'], false)) ?> 起）
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="afield">
            <label>年份 <span style="color:#dc2626">*</span></label>
            <input type="number" name="year" value="<?= (int) $e['year'] ?>" required
                   placeholder="-221">
            <span class="hint">公元前用负数，如 -221</span>
          </div>
          <div class="afield">
            <label>结束年份</label>
            <input type="number" name="year_end" value="<?= $e['year_end'] ? (int) $e['year_end'] : '' ?>"
                   placeholder="留空表示单点事件">
          </div>
          <div class="afield">
            <label>主题</label>
            <select name="category">
              <?php foreach ($cats as $s => $c): ?>
                <option value="<?= h($s) ?>" <?= (string) $e['category'] === $s ? 'selected' : '' ?>><?= h($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="grid-4">
          <div class="afield">
            <label>月（可选）</label>
            <input type="number" name="month" min="1" max="12" value="<?= $e['month'] ? (int) $e['month'] : '' ?>">
          </div>
          <div class="afield">
            <label>日（可选）</label>
            <input type="number" name="day" min="1" max="31" value="<?= $e['day'] ? (int) $e['day'] : '' ?>">
          </div>
          <div class="afield">
            <label>地点</label>
            <input type="text" name="place" value="<?= h((string) ($e['place'] ?? '')) ?>" placeholder="如：咸阳">
          </div>
          <div class="afield">
            <label>重要度</label>
            <select name="importance">
              <?php for ($i = 5; $i >= 1; $i--): ?>
                <option value="<?= $i ?>" <?= (int) $e['importance'] === $i ? 'selected' : '' ?>>
                  <?= $i ?> — <?= ['参考','一般','重要','王朝级大事','世界级转折'][$i - 1] ?>
                </option>
              <?php endfor; ?>
            </select>
          </div>
        </div>

        <div class="afield">
          <label>一句话概述</label>
          <input type="text" name="summary" value="<?= h((string) ($e['summary'] ?? '')) ?>"
                 placeholder="这条会显示在时间轴卡片上，建议 30 字以内">
        </div>

        <div class="afield">
          <label>详细描述</label>
          <textarea name="detail" rows="7" placeholder="展开讲清楚：起因、经过、影响。&lt;支持换行&gt;"><?= h((string) ($e['detail'] ?? '')) ?></textarea>
        </div>

        <div class="grid-2">
          <div class="afield">
            <label>关键人物</label>
            <input type="text" name="figures" value="<?= h((string) ($e['figures'] ?? '')) ?>" placeholder="用顿号分隔，如：秦始皇、李斯、尉缭">
          </div>
          <div class="afield" style="justify-content:end">
            <label class="check">
              <input type="checkbox" name="is_key" value="1" <?= !empty($e['is_key']) ? 'checked' : '' ?>>
              标记为关键节点（时间轴上加红点、加 ◆ 标识）
            </label>
          </div>
        </div>

        <div class="form-actions">
          <button class="btn-s primary" type="submit"><?= $isEdit ? '保存修改' : '创建节点' ?></button>
          <a class="btn-s" href="<?= h(alink('cn_events.php')) ?>">取消</a>
          <div class="spacer"></div>
          <?php if ($isEdit): ?>
            <span class="small muted">ID <?= (int) $e['id'] ?></span>
          <?php endif; ?>
        </div>
      </div>
    </form>
    <?php

// ===========================================================================
// 列表
// ===========================================================================
} else {
    $kw        = q('q');
    $fDyn      = qi('dynasty');
    $fCat      = q('cat');
    $fKey      = qi('key') === 1;
    $page      = max(1, qi('page', 1));
    $perPage   = 30;

    $where = ['1=1'];
    $args  = [];
    if ($fDyn) { $where[] = 'dynasty_id = ?'; $args[] = $fDyn; }
    if ($fCat) { $where[] = 'category = ?';   $args[] = $fCat; }
    if ($fKey) { $where[] = 'is_key = 1'; }
    if ($kw) {
        $where[] = '(title LIKE ? OR summary LIKE ? OR detail LIKE ? OR figures LIKE ? OR place LIKE ?)';
        $like = '%' . $kw . '%';
        array_push($args, $like, $like, $like, $like, $like);
    }
    $ws = implode(' AND ', $where);

    $total = (int) DB::fetchCol("SELECT COUNT(*) FROM cn_events WHERE {$ws}", $args);
    $pages = max(1, (int) ceil($total / $perPage));
    $page  = min($page, $pages);
    $off   = ($page - 1) * $perPage;

    $rows = DB::fetchAll(
        "SELECT e.*, d.name AS dynasty_name, d.color AS dynasty_color
         FROM cn_events e LEFT JOIN dynasties d ON d.id = e.dynasty_id
         WHERE {$ws}
         ORDER BY e.year ASC, e.month ASC, e.id ASC
         LIMIT {$perPage} OFFSET {$off}",
        $args
    );

    function cLink(array $ov = []): string {
        global $kw, $fDyn, $fCat, $fKey, $page;
        return link_to('admin/cn_events.php', array_merge([
            'q' => $kw, 'dynasty' => $fDyn, 'cat' => $fCat, 'key' => $fKey ? 1 : '', 'page' => $page,
        ], array_filter($ov, fn($v) => $v !== '' && $v !== null)));
    }
    ?>
    <div class="page-head">
      <h1>中国节点</h1>
      <span class="muted small">共 <?= number_format($total) ?> 条</span>
      <div class="spacer"></div>
      <a class="btn-s primary" href="<?= h(alink('cn_events.php', ['action' => 'new'])) ?>">+ 新增节点</a>
    </div>

    <div class="acard">
      <form class="afilter" method="get">
        <input type="text" name="q" value="<?= h($kw) ?>" placeholder="搜索标题、概述、人物、地点…">
        <select name="dynasty">
          <option value="">全部朝代</option>
          <?php foreach ($dynasties as $d): ?>
            <option value="<?= (int) $d['id'] ?>" <?= $fDyn === (int) $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="cat">
          <option value="">全部主题</option>
          <?php foreach ($cats as $s => $c): ?>
            <option value="<?= h($s) ?>" <?= $fCat === $s ? 'selected' : '' ?>><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <label class="check"><input type="checkbox" name="key" value="1" <?= $fKey ? 'checked' : '' ?>>仅关键</label>
        <button class="btn-s dark" type="submit">筛选</button>
        <?php if ($kw || $fDyn || $fCat || $fKey): ?>
          <a class="btn-s" href="<?= h(alink('cn_events.php')) ?>">清除</a>
        <?php endif; ?>
      </form>

      <?php if (!$rows): ?>
        <div class="aempty">没有匹配的节点。</div>
      <?php else: ?>
        <div style="overflow-x:auto">
        <table class="atable">
          <thead>
            <tr>
              <th style="width:96px">年份</th>
              <th>标题</th>
              <th style="width:88px">朝代</th>
              <th style="width:80px">主题</th>
              <th style="width:52px">重要</th>
              <th style="width:130px">操作</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $e): ?>
            <tr>
              <td class="num nowrap"><?= h(fmt_year_range((int) $e['year'], !empty($e['year_end']) ? (int) $e['year_end'] : null, false)) ?></td>
              <td>
                <div class="t-title">
                  <?= !empty($e['is_key']) ? '<span class="pill key">关键</span> ' : '' ?>
                  <?= h($e['title']) ?>
                </div>
                <?php if (!empty($e['summary'])): ?><div class="t-sum"><?= h($e['summary']) ?></div><?php endif; ?>
              </td>
              <td class="nowrap">
                <?php if (!empty($e['dynasty_name'])): ?>
                  <span class="pill" style="background:<?= h($e['dynasty_color']) ?>1a;color:<?= h($e['dynasty_color']) ?>"><?= h($e['dynasty_name']) ?></span>
                <?php else: ?><span class="muted">—</span><?php endif; ?>
              </td>
              <td class="nowrap">
                <span class="pill" style="background:<?= h(cat_color((string) $e['category'])) ?>1a;color:<?= h(cat_color((string) $e['category'])) ?>"><?= h(cat_name((string) $e['category'])) ?></span>
              </td>
              <td class="num" style="text-align:center"><?= (int) $e['importance'] ?></td>
              <td>
                <div class="row-actions">
                  <a class="btn-s" href="<?= h(alink('cn_events.php', ['action' => 'edit', 'id' => $e['id']])) ?>">编辑</a>
                  <a class="btn-s" href="<?= h(link_to('node.php', ['id' => $e['id']])) ?>" target="_blank">预览</a>
                  <form method="post" style="display:inline"
                        onsubmit="return confirm('确定删除「<?= h(addslashes($e['title'])) ?>」？其关联的联动关系也会一并删除。')">
                    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="op" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
                    <button class="btn-s danger" type="submit">删除</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>

      <?php if ($pages > 1): ?>
        <div class="pager-bar">
          <?php if ($page > 1): ?><a href="<?= h(cLink(['page' => $page - 1])) ?>">← 上一页</a><?php endif; ?>
          <span class="cur"><?= $page ?> / <?= $pages ?></span>
          <?php if ($page < $pages): ?><a href="<?= h(cLink(['page' => $page + 1])) ?>">下一页 →</a><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php
}

require __DIR__ . '/../inc/admin_footer.php';
