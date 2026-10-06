<?php
/**
 * 退出登录
 */
require_once __DIR__ . '/../inc/bootstrap.php';

// 清空会话数据
$_SESSION = [];

// 销毁会话 cookie
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $p['path'],
        $p['domain'],
        $p['secure'],
        $p['httponly']
    );
}
session_destroy();

header('Location: ' . alink('login.php'));
exit;
