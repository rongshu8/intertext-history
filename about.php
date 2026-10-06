<?php
/**
 * 关于页
 */

// 未安装时导向安装向导（必须在 bootstrap 之前：那时数据库还连不上）
require_once __DIR__ . '/inc/install_gate.php';
require __DIR__ . '/inc/bootstrap.php';

$PAGE_TITLE = '关于';
$ACTIVE     = 'about';
require __DIR__ . '/inc/layout_header.php';

$stats = site_stats();
?>

<div class="list-head">
  <h1>关于本站</h1>
  <p>一个把中国历史节点与世界大事放在同一时间轴上对照阅读的工具。</p>
</div>

<div class="card" style="padding:20px 24px;margin-bottom:20px">
  <h2 style="font-size:15px;margin-bottom:8px">站名</h2>
  <p style="font-size:14px;line-height:1.9;color:var(--ink-2);margin:0">
    叫「互文」。<b>互文</b>本是文学批评的术语，指两部作品彼此引用、
    彼此生成意义——放到这里，指的是同一年里中国与世界互相构成对方的历史：
    没有欧亚大陆的商贸与思想流动，就没有中国的造纸术外传；
    没有中国的丝与瓷，也没有欧洲远洋航路的动力。
    两边不是主从关系，而是互相参照、彼此改写。
  </p>
</div>

<div class="card" style="padding:24px 28px;margin-bottom:20px">
  <h2 style="font-size:16px;margin-bottom:10px">为什么做这个</h2>
  <p style="font-size:14.5px;line-height:1.9;color:var(--ink-2);margin:0">
    学中国史的时候，很容易把中国当成一条孤立的线：某年发生某事。但同一时刻，
    罗马正在扩张、阿拉伯正在统一半岛、欧洲正在重建大学、造纸术正在西传。
    这些事彼此不知道对方存在，却在同一条时间线上互相挤压、碰撞、交换。
    本站想做的是把这条对照线画出来——以中国节点为锚点，把同期世界发生的事并排放上去。
  </p>
</div>

<div class="card" style="padding:24px 28px;margin-bottom:20px">
  <h2 style="font-size:16px;margin-bottom:14px">怎么用</h2>
  <ul style="font-size:14.5px;line-height:2;color:var(--ink-2);padding-left:20px;margin:0">
    <li><b>时间轴</b>：左侧中国节点，右侧同期世界大事。点任一卡片看详情。</li>
    <li><b>节点详情</b>：可调时间窗口（±1 到 ±20 年），按区域、主题筛选同期世界大事。</li>
    <li><b>中外联动</b>：紫色卡片标出真正存在因果或结构性呼应的节点，比如「怛罗斯之战 → 造纸术西传」。</li>
    <li><b>世界大事</b>：完整列表，每条可反查同期中国节点。</li>
  </ul>
</div>

<div class="card" style="padding:24px 28px;margin-bottom:20px">
  <h2 style="font-size:16px;margin-bottom:14px">内容说明与免责</h2>
  <div style="font-size:14.5px;line-height:1.95;color:var(--ink-2)">
    <p style="margin:0 0 10px">· 本站是<b>历史学习辅助材料</b>，不是学术著作。事件年代与人物生卒采用通说。</p>
    <p style="margin:0 0 10px">· 早期断代（尤其夏商周）存在学术分歧，本站取主流说法，欢迎指正。</p>
    <p style="margin:0 0 10px">· 中外并列仅表示<b>大致同时期</b>，不代表事件之间存在因果关系。真正有因果关系的节点已单独用「联动」标出。</p>
    <p style="margin:0 0 10px">· 涉及近现代史的表述，以中国现行中学历史教材的通行口径为基础，便于对照记忆。</p>
    <p style="margin:0">· 种子内容由 AI 生成后人工整理，细节与年代可能存在错误，<b>请以权威史料为准</b>。</p>
  </div>
</div>

<div class="card" style="padding:24px 28px">
  <h2 style="font-size:16px;margin-bottom:14px">当前规模</h2>
  <div style="display:flex;flex-wrap:wrap;gap:28px">
    <div><b style="font-size:22px"><?= number_format($stats['cn']) ?></b>
      <span style="color:var(--muted);font-size:13px;margin-left:6px">中国节点</span></div>
    <div><b style="font-size:22px"><?= number_format($stats['world']) ?></b>
      <span style="color:var(--muted);font-size:13px;margin-left:6px">世界大事</span></div>
    <div><b style="font-size:22px"><?= number_format($stats['dynasties']) ?></b>
      <span style="color:var(--muted);font-size:13px;margin-left:6px">朝代分期</span></div>
    <div><b style="font-size:22px"><?= number_format($stats['relations']) ?></b>
      <span style="color:var(--muted);font-size:13px;margin-left:6px">中外联动</span></div>
  </div>
  <p style="margin:14px 0 0;font-size:14px;line-height:1.9;color:var(--ink-2)">
    内容仍在持续补充，欢迎<a class="link-tap" href="<?= h(link_to('contributors.php')) ?>"
    style="color:var(--cinnabar,#c0392b)">参与贡献</a>。
  </p>
</div>

<?php require __DIR__ . '/inc/layout_footer.php'; ?>
