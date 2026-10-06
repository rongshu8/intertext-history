<?php
/**
 * 我要贡献
 * ---------------------------------------------------------------------------
 * 这一页刻意只放两个选择：注册 / 登录。
 *
 * 为什么不做成直接带表单的投稿页：
 *   - 未登录用户直接写表单会被 CSRF 与身份校验拦住，等于白填一遍
 *   - 贡献的前提是「先有账号」，所以先分流到注册或登录，登录后由
 *     user/submit.php 承接投稿表单
 *
 * 已登录用户没有再看这两个按钮的必要，直接送去投稿中心。
 */

// 未安装时导向安装向导（必须在 bootstrap 之前：那时数据库还连不上）
require_once __DIR__ . '/inc/install_gate.php';
require_once __DIR__ . '/inc/bootstrap.php';

if (is_logged_in()) {
    header('Location: ' . (is_admin() ? alink('index.php') : link_to('user/index.php')));
    exit;
}

$PAGE_TITLE = '我要贡献';
$ACTIVE     = 'contribute';
require __DIR__ . '/inc/layout_header.php';
?>

<div class="list-head">
  <h1>我要贡献</h1>
  <p>补充一条中国节点、一条世界大事，或指出某处年代错误 —— 都欢迎。</p>
</div>

<div style="max-width:520px;margin:0 auto 40px">
  <a class="btn btn-primary" href="<?= h(link_to('register.php')) ?>"
     style="display:flex;align-items:center;justify-content:center;
            width:100%;padding:15px 20px;font-size:16px;margin-bottom:12px">
    注册
  </a>
  <a class="btn" href="<?= h(alink('login.php')) ?>"
     style="display:flex;align-items:center;justify-content:center;
            width:100%;padding:15px 20px;font-size:16px">
    登录
  </a>

  <p style="margin:22px 0 0;font-size:13.5px;line-height:1.9;color:var(--muted)">
    还没有账号就选<b>注册</b>，只需用户名、昵称和密码，无需邮箱；
    已有账号选<b>登录</b>。登录后即可提交中国节点、世界大事与中外联动，
    提交的内容需经管理员审核，通过后会出现在时间轴与
    <a class="link-tap" href="<?= h(link_to('contributors.php')) ?>">贡献名单</a>上。
  </p>
</div>

<?php require __DIR__ . '/inc/layout_footer.php'; ?>
