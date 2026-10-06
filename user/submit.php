<?php
/**
 * 提交投稿
 * ---------------------------------------------------------------------------
 * ?kind=cn_node | world_event | relation
 * 提交后进入待审核状态，只有管理员通过后才写入正式表并在前台显示。
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/submission.php';
require_login();

$me   = current_user();
$kinds = submission_kinds();
$kind = q('kind', 'cn_node');
if (!isset($kinds[$kind])) {
    $kind = 'cn_node';
}

$errors = [];
$old    = [];

// 联动投稿需要的下拉选项
$cnOptions = DB::fetchAll(
    'SELECT e.id, e.title, e.year, d.name AS dynasty
     FROM cn_events e LEFT JOIN dynasties d ON d.id = e.dynasty_id
     ORDER BY e.year ASC'
);
$weOptions = DB::fetchAll('SELECT id, title, year FROM world_events ORDER BY year ASC');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // 以 POST 的 kind 为准（用户可能中途切换了类型）
    $pKind = p('kind', $kind);
    if (!isset($kinds[$pKind])) {
        $pKind = $kind;
    }
    $old = $_POST;

    if (!csrf_check()) {
        $errors[] = '会话已过期，请重新提交。';
    } else {
        $r = submission_validate($pKind, $_POST);
        if (!$r['ok']) {
            $errors = $r['errors'];
        } else {
            try {
                DB::exec(
                    'INSERT INTO submissions (user_id, kind, payload, status) VALUES (?, ?, ?, ?)',
                    [
                        (int) $me['id'],
                        $pKind,
                        json_encode($r['data'], JSON_UNESCAPED_UNICODE),
                        'pending',
                    ]
                );
                $newId = DB::lastInsertId();
                flash('投稿已提交，等待管理员审核。');
                header('Location: ' . link_to('user/index.php', ['id' => $newId]));
                exit;
            } catch (Throwable $e) {
                $errors[] = '提交失败：' . $e->getMessage();
            }
        }
        $kind = $pKind;
    }
}

$cats     = dict_categories();
$regions  = dict_regions();
$relTypes = dict_relation_types();
$dynasties = all_dynasties();

/** 取回旧值（提交失败时不丢内容） */
function sv(array $old, string $key, $default = ''): string
{
    return isset($old[$key]) ? h((string) $old[$key]) : h((string) $default);
}

$USER_NAV   = 'submit';
$USER_TITLE = '提交投稿';
require __DIR__ . '/../inc/user_header.php';
?>

<div class="page-head">
  <h1>提交投稿</h1>
  <div class="spacer"></div>
  <a class="btn-s" href="<?= h(link_to('user/index.php', ['tab' => 'mine'])) ?>">我的投稿</a>
</div>

<div class="alert alert-info">
  投稿提交后进入<b>待审核</b>状态，管理员通过后才会出现在前台。审核结果可在「我的投稿」查看。
</div>

<div class="acard">
  <div class="acard-head">
    <h2>选择投稿类型</h2>
  </div>
  <div class="acard-pad">
    <div style="display:flex;gap:9px;flex-wrap:wrap">
      <?php foreach ($kinds as $k => $label): ?>
        <a class="btn-s <?= $kind === $k ? 'primary' : '' ?>"
           href="<?= h(link_to('user/submit.php', ['kind' => $k])) ?>"><?= h($label) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php if ($errors): ?>
  <div class="alert alert-err" style="margin-top:16px">
    <?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<form class="acard acard-pad" method="post" style="margin-top:16px">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="kind" value="<?= h($kind) ?>">

  <div class="aform">
    <?php if ($kind === 'cn_node'): ?>
      <h3 style="font-size:15px;margin:0">中国节点</h3>
      <div class="grid-2">
        <div class="afield">
          <label>朝代 <span style="color:#dc2626">*</span></label>
          <select name="dynasty_id" required>
            <option value="">— 选择朝代 —</option>
            <?php foreach ($dynasties as $d): ?>
              <option value="<?= (int) $d['id'] ?>" <?= (string) sv($old, 'dynasty_id') === (string) $d['id'] ? 'selected' : '' ?>>
                <?= h($d['name']) ?>（<?= h(fmt_year((int) $d['start_year'], false)) ?> 起）
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="afield">
          <label>主题</label>
          <select name="category">
            <?php foreach ($cats as $s => $c): ?>
              <option value="<?= h($s) ?>" <?= sv($old, 'category', 'politics') === $s ? 'selected' : '' ?>><?= h($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="afield">
        <label>标题 <span style="color:#dc2626">*</span></label>
        <input type="text" name="title" value="<?= sv($old, 'title') ?>" required maxlength="160"
               placeholder="如：某某重大事件及其影响">
      </div>
      <div class="grid-4">
        <div class="afield">
          <label>年份 <span style="color:#dc2626">*</span></label>
          <input type="number" name="year" value="<?= sv($old, 'year') ?>" required placeholder="-221">
          <span class="hint">公元前用负数</span>
        </div>
        <div class="afield">
          <label>结束年份</label>
          <input type="number" name="year_end" value="<?= sv($old, 'year_end') ?>" placeholder="留空=单点">
        </div>
        <div class="afield"><label>月</label><input type="number" name="month" min="1" max="12" value="<?= sv($old, 'month') ?>"></div>
        <div class="afield"><label>日</label><input type="number" name="day" min="1" max="31" value="<?= sv($old, 'day') ?>"></div>
      </div>
      <div class="grid-3">
        <div class="afield">
          <label>地点</label>
          <input type="text" name="place" value="<?= sv($old, 'place') ?>" placeholder="如：长安">
        </div>
        <div class="afield">
          <label>重要度</label>
          <select name="importance">
            <?php for ($i = 5; $i >= 1; $i--): ?>
              <option value="<?= $i ?>" <?= sv($old, 'importance', '3') === (string) $i ? 'selected' : '' ?>>
                <?= $i ?> — <?= ['参考','一般','重要','王朝级大事','世界级转折'][$i - 1] ?>
              </option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="afield" style="justify-content:end">
          <label class="check">
            <input type="checkbox" name="is_key" value="1" <?= !empty($old['is_key']) ? 'checked' : '' ?>>
            关键节点
          </label>
        </div>
      </div>
      <div class="afield">
        <label>一句话概述</label>
        <input type="text" name="summary" value="<?= sv($old, 'summary') ?>" maxlength="500"
               placeholder="会显示在时间轴卡片上，建议 30 字以内">
      </div>
      <div class="afield">
        <label>详细描述</label>
        <textarea name="detail" rows="6" placeholder="起因、经过、影响。"><?= sv($old, 'detail') ?></textarea>
      </div>
      <div class="afield">
        <label>关键人物</label>
        <input type="text" name="figures" value="<?= sv($old, 'figures') ?>" placeholder="顿号分隔">
      </div>

    <?php elseif ($kind === 'world_event'): ?>
      <h3 style="font-size:15px;margin:0">世界大事</h3>
      <div class="afield">
        <label>标题 <span style="color:#dc2626">*</span></label>
        <input type="text" name="title" value="<?= sv($old, 'title') ?>" required maxlength="200"
               placeholder="如：某国完成某项重大工程">
      </div>
      <div class="grid-4">
        <div class="afield">
          <label>年份 <span style="color:#dc2626">*</span></label>
          <input type="number" name="year" value="<?= sv($old, 'year') ?>" required placeholder="1687">
          <span class="hint">公元前用负数</span>
        </div>
        <div class="afield">
          <label>结束年份</label>
          <input type="number" name="year_end" value="<?= sv($old, 'year_end') ?>" placeholder="留空=单点">
        </div>
        <div class="afield">
          <label>区域</label>
          <select name="region">
            <?php foreach ($regions as $s => $r): ?>
              <option value="<?= h($s) ?>" <?= sv($old, 'region', 'global') === $s ? 'selected' : '' ?>>
                <?= h($r['emoji']) ?> <?= h($r['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="afield">
          <label>主题</label>
          <select name="category">
            <?php foreach ($cats as $s => $c): ?>
              <option value="<?= h($s) ?>" <?= sv($old, 'category', 'politics') === $s ? 'selected' : '' ?>><?= h($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="grid-4">
        <div class="afield"><label>月</label><input type="number" name="month" min="1" max="12" value="<?= sv($old, 'month') ?>"></div>
        <div class="afield"><label>日</label><input type="number" name="day" min="1" max="31" value="<?= sv($old, 'day') ?>"></div>
        <div class="afield">
          <label>地点</label>
          <input type="text" name="place" value="<?= sv($old, 'place') ?>" placeholder="如：伦敦">
        </div>
        <div class="afield">
          <label>重要度</label>
          <select name="importance">
            <?php for ($i = 5; $i >= 1; $i--): ?>
              <option value="<?= $i ?>" <?= sv($old, 'importance', '3') === (string) $i ? 'selected' : '' ?>>
                <?= $i ?> — <?= ['参考','一般','重要','王朝级大事','世界级转折'][$i - 1] ?>
              </option>
            <?php endfor; ?>
          </select>
        </div>
      </div>
      <div class="afield">
        <label>一句话概述</label>
        <input type="text" name="summary" value="<?= sv($old, 'summary') ?>" maxlength="500">
      </div>
      <div class="afield">
        <label>详细描述</label>
        <textarea name="detail" rows="6" placeholder="这件事为什么重要。"><?= sv($old, 'detail') ?></textarea>
      </div>
      <div class="grid-2">
        <div class="afield">
          <label>关键人物</label>
          <input type="text" name="figures" value="<?= sv($old, 'figures') ?>" placeholder="顿号分隔">
        </div>
        <div class="afield">
          <label>来源参考</label>
          <input type="text" name="source" value="<?= sv($old, 'source') ?>" placeholder="便于审核时核查">
        </div>
      </div>

    <?php else: ?>
      <h3 style="font-size:15px;margin:0">中外联动</h3>
      <div class="alert alert-info" style="margin-bottom:4px">
        联动用于标注两个事件之间<b>真实的因果或结构性呼应</b>，而不是仅仅时间上相近。
        需关联<b>已收录</b>的节点与大事。
      </div>
      <div class="grid-2">
        <div class="afield">
          <label>中国节点 <span style="color:#dc2626">*</span></label>
          <select name="cn_event_id" required>
            <option value="">— 选择中国节点（共 <?= count($cnOptions) ?> 条）—</option>
            <?php foreach ($cnOptions as $c): ?>
              <option value="<?= (int) $c['id'] ?>" <?= sv($old, 'cn_event_id') === (string) $c['id'] ? 'selected' : '' ?>>
                <?= h(fmt_year((int) $c['year'], false)) ?>　<?= h(mb_substr((string) $c['title'], 0, 30)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="afield">
          <label>世界大事 <span style="color:#dc2626">*</span></label>
          <select name="world_event_id" required>
            <option value="">— 选择世界大事（共 <?= count($weOptions) ?> 条）—</option>
            <?php foreach ($weOptions as $w): ?>
              <option value="<?= (int) $w['id'] ?>" <?= sv($old, 'world_event_id') === (string) $w['id'] ? 'selected' : '' ?>>
                <?= h(fmt_year((int) $w['year'], false)) ?>　<?= h(mb_substr((string) $w['title'], 0, 30)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="afield">
        <label>关系类型</label>
        <select name="relation_type">
          <?php foreach ($relTypes as $s => $t): ?>
            <option value="<?= h($s) ?>" <?= sv($old, 'relation_type', 'echo') === $s ? 'selected' : '' ?>>
              <?= h($t['name']) ?> — <?= h($t['descr']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="afield">
        <label>关联说明 <span style="color:#dc2626">*</span></label>
        <textarea name="note" rows="5" required maxlength="500"
                  placeholder="说清楚这两件事怎么联系：因果链条是什么？呼应体现在哪？"><?= sv($old, 'note') ?></textarea>
      </div>
    <?php endif; ?>

    <div class="form-actions">
      <button class="btn-s primary" type="submit">提交投稿</button>
      <a class="btn-s" href="<?= h(link_to('user/index.php', ['tab' => 'mine'])) ?>">取消</a>
      <div class="spacer"></div>
      <span class="small muted">提交后进入待审核状态</span>
    </div>
  </div>
</form>

<?php require __DIR__ . '/../inc/user_footer.php'; ?>
