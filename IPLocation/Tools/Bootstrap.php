<?php

/**
 * 按需加载注册：插件自身类 + 第三方 SDK。
 *
 * 背景一（插件自身类）：插件全部类已集中到 Tools/ 目录（含 Engine/ 子目录），
 * 而 Typecho 核心自动加载器按「插件名/类名.php」寻址（如 IPLocation/Resolver.php、
 * IPLocation/Engine/Ip2RegionEngine.php），找不到迁移后的文件；且后台面板上下文
 * 不经过 Plugin.php，核心类可能始终没有被加载的入口。因此在此统一映射
 * TypechoPlugin\IPLocation\ 下的类到 Tools/ 对应路径。
 *
 * 背景二（第三方 SDK）：MaxMind\Db\* 与 ip2region\xdb\* 均为官方最新实现，
 * 分别位于 Tools/GeoLite/ 与 Tools/IP2Region/Search.XDB.php。
 *
 * 本文件由 Plugin.php（钩子上下文）与 Resolver.php / Panel.php
 * （后台面板等不经过 Plugin.php 的上下文）共同引入。
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/** 插件自身类：TypechoPlugin\IPLocation\X（含 Engine\X 等子目录）→ Tools/ 下对应文件 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'TypechoPlugin\\IPLocation\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));

    if ('' === $relative) {
        return;
    }

    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

/** MaxMind\Db\* → Tools/GeoLite/ 官方 Reader */
spl_autoload_register(static function (string $class): void {
    $prefix = 'MaxMind\\Db\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $file = __DIR__ . '/GeoLite/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

/** ip2region\xdb\* → Tools/IP2Region/Search.XDB.php（单文件实现） */
spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'ip2region\\xdb\\', 13) !== 0) {
        return;
    }

    $file = __DIR__ . '/IP2Region/Search.XDB.php';

    if (is_file($file)) {
        require_once $file;
    }
});
