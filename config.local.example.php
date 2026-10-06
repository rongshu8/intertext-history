<?php
/**
 * 本地配置示例 —— 复制为 config.local.php 后按实际填写
 *
 *  cp config.local.example.php config.local.php
 *
 * config.local.php 优先级高于 config.php，且已在 .gitignore 与
 * 目录级 .htaccess 中排除，**不要提交到版本库或外传**。
 *
 * ⚠️ 本文件里是明文密码。放在 Web 根目录下有泄露风险，
 *    务必确认服务器规则已禁止访问它
 *    （本项目根 .htaccess 已含 FilesMatch "^(config|config\.local)\.php$"；
 *     Nginx 不读 .htaccess，需手工加 location 规则，见安装说明）。
 */

return [
    'db' => [
        'host'       => '127.0.0.1',  // 宝塔默认 localhost；容器/远程库填实际地址
        'port'       => 3306,
        'name'       => 'history_timeline',
        'user'       => '你的数据库用户名',
        'pass'       => '你的数据库密码',
        // 必须是 utf8mb4（不是 utf8）—— 否则中文会乱码
        'charset'    => 'utf8mb4',
        // 持久连接：PHP-FPM 下复用 TCP 连接，高并发时省去反复握手
        'persistent' => true,
    ],
    'site' => [
        'debug' => false,             // 生产环境务必 false（true 会把错误直接输出到页面）
    ],
];
