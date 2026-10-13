<?php
/**
 * IPLocation 管理面板。
 *
 * 独立页面 - 与 Passkey 面板一致的设计语言：直角边框、CSS 变量主题、
 * 桌面表格 + 移动卡片双渲染、暗色模式跟随系统。
 *
 * 入口：管理 → IP 属地（extending.php?panel=IPLocation/Panel.php）。
 */

use TypechoPlugin\IPLocation\Cache;
use TypechoPlugin\IPLocation\Config;
use TypechoPlugin\IPLocation\Ip;
use TypechoPlugin\IPLocation\Location;
use TypechoPlugin\IPLocation\Plugin;
use TypechoPlugin\IPLocation\Resolver;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

if (!class_exists('Typecho\Common', false)) {
    require_once __TYPECHO_ROOT_DIR__ . '/var/Typecho/Common.php';
}

/** 注册插件自身类与第三方 SDK 的按需加载（核心类位于 Tools/，后台面板不经过 Plugin.php） */
require_once __DIR__ . '/Tools/Bootstrap.php';

/** 无权限时：未登录跳转登录页，已登录但非管理员抛 403 */
$user = \Widget\User::alloc();
$user->pass('administrator');

$options = \Widget\Options::alloc();
$security = \Widget\Security::alloc();
$request = \Typecho\Request::getInstance();

$pluginUrl = $options->pluginUrl . '/IPLocation';
$requestUrl = $request->getRequestUrl();
$token = $security->getToken($requestUrl);

if (!function_exists('ipl_escape')) {
    /**
     * HTML 转义。
     *
     * @param mixed $value 原值
     * @return string
     */
    function ipl_escape($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('ipl_size')) {
    /**
     * 格式化字节数。
     *
     * @param int $bytes 字节
     * @return string
     */
    function ipl_size(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) $bytes;
        $index = 0;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return ($index > 0 ? number_format($value, 1) : (string) $value) . ' ' . $units[$index];
    }
}

if (!function_exists('getIpLocationAvatar')) {
    /**
     * 获取用户头像（矩形设计）。
     *
     * @param string $email 邮箱
     * @param string $name 昵称
     * @param int $size 尺寸
     * @return string
     */
    function getIpLocationAvatar($email, $name, $size = 28)
    {
        $hash = md5(strtolower((string) $email));
        $url = 'https://weavatar.com/avatar/' . $hash . '?s=' . $size . '&d=identicon';

        return '<img src="' . $url . '" alt="' . ipl_escape($name) . '" width="' . $size . '" height="' . $size
            . '" style="display:block;">';
    }
}

$notice = '';
$queryError = '';
$result = null;
$queryIp = '';

/** ── 写操作：查询 / 清空缓存 / 刷新统计 ── */
if ($request->isPost()) {
    $security->protect();

    $action = (string) $request->get('action');

    if ('clear_cache' === $action) {
        $notice = _t('已清空 %d 条解析缓存', Cache::clear());
        Config::flush();
    }

    if ('refresh_stats' === $action) {
        Cache::forget(md5('iplocation_comment_stats_v1'));
        $notice = _t('统计缓存已刷新');
    }

    if ('query' === $action) {
        $queryIp = trim((string) $request->get('ip'));

        if ('' === $queryIp) {
            $queryError = _t('请输入要查询的 IP 地址');
        } elseif (null === Ip::normalize($queryIp)) {
            $queryError = _t('IP 地址格式不正确');
        } else {
            $result = Resolver::resolve($queryIp, true);

            if (null === $result->engine && Ip::KIND_PUBLIC === $result->kind) {
                $queryError = _t('所有归属地库均未命中该地址，请检查库文件是否就位');
            }
        }
    }
}

$config = Config::all();
$statusList = Resolver::status();
$customFiles = array_filter([
    $config['fileIp2RegionV4'],
    $config['fileIp2RegionV6'],
    $config['fileGeoipCity'],
    $config['fileGeoipCountry'],
    $config['fileGeoipAsn'],
]);
$cacheStats = Cache::stats();
$trustedProxies = Config::proxyList();
$availableDatabases = 0;
$totalDatabases = 0;

foreach ($statusList as $status) {
    foreach ($status['files'] as $file) {
        $totalDatabases++;

        if (!empty($file['exists']) && !empty($file['readable'])) {
            $availableDatabases++;
        }
    }
}

/** ── 评论来源分布（最近 500 条，结果缓存 10 分钟） ── */
$statsKey = md5('iplocation_comment_stats_v1');
$stats = Cache::get($statsKey);

if (null === $stats) {
    $stats = ['total' => 0, 'counter' => []];

    try {
        $db = \Typecho\Db::get();
        $rows = $db->fetchAll(
            $db->select('ip')->from('table.comments')
                ->order('created', \Typecho\Db::SORT_DESC)
                ->limit(500)
        );

        $counter = [];
        $invalid = 0;

        foreach ($rows as $row) {
            if (null === Ip::normalize((string) ($row['ip'] ?? ''))) {
                $invalid++;
            }
        }

        foreach (Resolver::resolveMany(array_column($rows, 'ip')) as $location) {
            $label = Resolver::label($location);
            $counter[$label] = ($counter[$label] ?? 0) + 1;
        }

        arsort($counter);
        $stats = [
            'total'   => count($rows),
            'invalid' => $invalid,
            'counter' => array_slice($counter, 0, 12, true),
        ];
    } catch (\Throwable $e) {
        $stats = ['total' => 0, 'counter' => [], 'error' => $e->getMessage()];
    }

    Cache::set($statsKey, $stats, 600);
}

$sourceLabels = [
    Config::SOURCE_AUTO      => _t('自动'),
    Config::SOURCE_IP2REGION => 'ip2region',
    Config::SOURCE_GEOIP2    => 'GeoLite2',
];

$detailLabels = [
    'smart'   => _t('智能'),
    'country' => _t('仅国家'),
    'region'  => _t('国家 + 行政区'),
    'city'    => _t('国家 + 行政区 + 城市'),
    'isp'     => _t('全部'),
];

$configRows = [
    _t('数据源')      => $sourceLabels[$config['dataSource']] ?? $config['dataSource'],
    _t('回退策略')    => empty($config['fallback']) ? _t('关闭') : _t('ip2region 未命中回退 GeoLite2'),
    _t('细节补全')    => empty($config['enrich']) ? _t('关闭') : _t('用 GeoLite2 补全缺失字段'),
    _t('GeoLite2 库') => $config['geoipDatabase'],
    _t('库目录')      => basename(Config::databaseDir()),
    _t('库文件命名') => empty($customFiles) ? _t('默认') : _t('%d 个自定义', count($customFiles)),
    _t('展示语言')    => $config['language'],
    _t('展示粒度')    => $detailLabels[$config['detail']] ?? $config['detail'],
    _t('自定义模板')  => '' === $config['format'] ? _t('未设置（按粒度生成）') : $config['format'],
    _t('附加运营商')  => empty($config['showIsp']) ? _t('隐藏') : _t('显示'),
    _t('精简后缀')    => empty($config['trimSuffix']) ? _t('关闭') : _t('开启'),
    _t('未知文案')    => $config['unknownText'],
    _t('前台展示')    => $config['showFront'] ? _t('开启') : _t('关闭'),
    _t('后台展示')    => $config['showAdmin'] ? _t('开启') : _t('关闭'),
    _t('隐私级别')    => 'masked' === $config['privacy'] ? _t('属地 + 脱敏 IP') : _t('只显示属地'),
    _t('缓存')        => $config['cache'] ? _t('开启（%d 天）', $config['cacheTtl']) : _t('关闭'),
    _t('代理还原')    => empty($config['proxyEnabled']) ? _t('关闭（一律 REMOTE_ADDR）') : _t('开启'),
    _t('CIDR 校验')   => empty($config['proxyEnabled']) ? '-'
        : (empty($config['proxyCidr']) ? _t('关闭') : _t('开启（%d 条白名单）', count($trustedProxies))),
    _t('自定义请求头') => empty($config['proxyEnabled']) || empty($config['proxyHeader'])
        ? _t('关闭') : ('' !== $config['proxyHeaderName'] ? $config['proxyHeaderName'] : _t('未填写头名')),
    _t('可信代理')    => empty($trustedProxies) ? _t('未配置') : _t('%d 条', count($trustedProxies)),
    _t('入库策略')    => $config['anonymousStore'] ? _t('匿名化后入库') : _t('保存原始 IP'),
];

/** 查询结果字段（供桌面表格与移动卡片共用） */
$resultFields = [
    _t('地址族')   => $result ? ($result->version ? 'IPv' . $result->version : null) : null,
    _t('地址类型') => $result ? Ip::kindLabel($result->kind) : null,
    _t('洲')       => $result ? $result->continent : null,
    _t('国家')     => $result ? $result->country : null,
    _t('国家代码') => $result ? $result->countryCode : null,
    _t('行政区')   => $result ? $result->region : null,
    _t('城市')     => $result ? $result->city : null,
    _t('运营商')   => $result ? $result->isp : null,
    _t('ASN')     => $result ? $result->asn : null,
    _t('ASN 组织') => $result ? $result->asnOrg : null,
    _t('邮政编码') => $result ? $result->postal : null,
    _t('时区')     => $result ? $result->timeZone : null,
    _t('坐标')     => $result && null !== $result->latitude && null !== $result->longitude
        ? $result->latitude . ', ' . $result->longitude : null,
    _t('命中网段') => $result ? $result->network : null,
];
?>
<!DOCTYPE html>
<html lang="zh-CN" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex,nofollow">
    <title>IP 属地管理 - <?php echo ipl_escape($options->title); ?></title>
    <!-- 字体导入 -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cascadia+Code:ital,wght@0,200..700;1,200..700&family=Inter:wght@100..900&family=Noto+Sans+SC:wght@100..900&display=swap" rel="stylesheet">
    <!-- TailwindCSS -->
    <link rel="stylesheet" href="<?php echo ipl_escape($pluginUrl); ?>/assist/css/panel.css?v=<?php echo ipl_escape(Plugin::VERSION); ?>">
    <!-- IPLocation 样式 -->
    <link rel="stylesheet" href="<?php echo ipl_escape($pluginUrl); ?>/assist/css/style.css?v=<?php echo ipl_escape(Plugin::VERSION); ?>">
    <!-- Font Awesome -->
    <link href="https://cdn.garfieldtom.cool/resource/libs/fontawesome/7.2.0/css/all.min.css" rel="stylesheet">
</head>
<body class="min-h-screen flex flex-col font-sans text-sm" style="background: var(--ipl-bg);">
    <script>
        // 立即应用暗色模式，避免闪烁
        if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
            document.body.style.background = '#000000';
        }
    </script>

    <!-- 顶部导航栏 -->
    <header class="h-16 flex items-center justify-between px-6 z-10 fixed top-0 left-0 right-0 border-b" style="background: var(--ipl-surface); border-color: var(--ipl-border);">
        <div class="flex items-center">
            <a href="<?php echo ipl_escape($options->adminUrl('index.php')); ?>" class="p-2 transition-colors hover:opacity-70" title="<?php echo ipl_escape(_t('返回控制台')); ?>" style="color: var(--ipl-muted);">
                <i class="fas fa-arrow-left text-sm"></i>
            </a>
            <span class="w-px h-6 mx-3" style="background: var(--ipl-border);"></span>
            <h1 class="text-base font-semibold" style="color: var(--ipl-text);">IP 属地管理</h1>
        </div>
        <div class="flex items-center gap-3">
            <div class="hidden md:flex items-center gap-2.5">
                <?php echo getIpLocationAvatar($user->mail, $user->screenName, 28); ?>
                <div class="flex flex-col">
                    <span class="text-sm font-medium leading-tight" style="color: var(--ipl-text);"><?php echo ipl_escape($user->screenName); ?></span>
                    <span class="text-xs leading-tight" style="color: var(--ipl-muted);"><?php echo ipl_escape($user->group); ?></span>
                </div>
            </div>
        </div>
    </header>

    <!-- 主内容区域 -->
    <main class="flex-1 pt-16 md:pt-16">
        <div class="p-6">
            <?php if ('' !== $notice): ?>
                <div class="ipl-info-box ipl-info-box--blue" style="margin-bottom:16px;">
                    <i class="fas fa-circle-check"></i>
                    <span><?php echo ipl_escape($notice); ?></span>
                </div>
            <?php endif; ?>

            <!-- 统计概览 -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="p-5 border" style="background: var(--ipl-surface); border-color: var(--ipl-border);">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs uppercase tracking-wide" style="color: var(--ipl-muted);">当前数据源</span>
                        <i class="fas fa-globe text-lg" style="color: var(--ipl-primary);"></i>
                    </div>
                    <div class="text-3xl font-bold" style="color: var(--ipl-text);"><?php echo ipl_escape($sourceLabels[$config['dataSource']] ?? $config['dataSource']); ?></div>
                    <div class="text-xs mt-1" style="color: var(--ipl-muted);">
                        <?php echo empty($config['fallback']) ? _t('未命中不回退') : _t('未命中回退 GeoLite2'); ?>
                    </div>
                </div>
                <div class="p-5 border" style="background: var(--ipl-surface); border-color: var(--ipl-border);">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs uppercase tracking-wide" style="color: var(--ipl-muted);">可用归属地库</span>
                        <i class="fas fa-database text-lg" style="color: var(--ipl-info);"></i>
                    </div>
                    <div class="text-3xl font-bold" style="color: var(--ipl-text);"><?php echo ipl_escape($availableDatabases . ' / ' . $totalDatabases); ?></div>
                    <div class="text-xs mt-1" style="color: var(--ipl-muted);"><?php echo _t('ip2region v4 / v6 + GeoLite2 City / Country / ASN'); ?></div>
                </div>
                <div class="p-5 border" style="background: var(--ipl-surface); border-color: var(--ipl-border);">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs uppercase tracking-wide" style="color: var(--ipl-muted);">解析缓存</span>
                        <i class="fas fa-clock text-lg" style="color: var(--ipl-success);"></i>
                    </div>
                    <div class="text-3xl font-bold" id="iplocation-cache-count" style="color: var(--ipl-text);"><?php echo ipl_escape($cacheStats['count']); ?></div>
                    <div class="text-xs mt-1" style="color: var(--ipl-muted);"><?php echo ipl_escape(ipl_size($cacheStats['size'])); ?></div>
                </div>
                <div class="p-5 border" style="background: var(--ipl-surface); border-color: var(--ipl-border);">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs uppercase tracking-wide" style="color: var(--ipl-muted);">评论样本</span>
                        <i class="fas fa-chart-line text-lg" style="color: var(--ipl-primary);"></i>
                    </div>
                    <div class="text-3xl font-bold" style="color: var(--ipl-text);"><?php echo ipl_escape($stats['total']); ?></div>
                    <div class="text-xs mt-1" style="color: var(--ipl-muted);"><?php echo _t('最近 500 条评论'); ?></div>
                </div>
            </div>

            <!-- 查询自检 -->
            <div class="border mb-6" style="background: var(--ipl-surface); border-color: var(--ipl-border);">
                <div class="flex flex-col md:flex-row md:items-center justify-between p-4 border-b gap-3" style="background: var(--ipl-surface-2); border-color: var(--ipl-border);">
                    <h2 class="text-sm font-semibold" style="color: var(--ipl-text);">查询自检</h2>
                    <span class="text-xs" style="color: var(--ipl-muted);"><?php echo _t('手动验证任意地址的解析链路与返回细节'); ?></span>
                </div>
                <div class="p-5">
                    <form method="post" action="<?php echo ipl_escape($requestUrl); ?>" class="flex flex-col md:flex-row gap-3">
                        <input type="hidden" name="action" value="query">
                        <input type="hidden" name="_" value="<?php echo ipl_escape($token); ?>">
                        <input type="text" name="ip" placeholder="<?php echo ipl_escape(_t('例如 1.2.4.8 或 2400:3200::1')); ?>" value="<?php echo ipl_escape($queryIp); ?>" autocomplete="off" class="flex-1 px-4 py-2.5 text-sm border outline-none transition-colors focus:opacity-100" style="background: var(--ipl-surface-2); color: var(--ipl-text); border-color: var(--ipl-border);">
                        <button type="submit" class="flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-medium text-white transition-all hover:opacity-90" style="background: var(--ipl-primary);">
                            <i class="fas fa-magnifying-glass"></i>
                            <span><?php echo ipl_escape(_t('查询')); ?></span>
                        </button>
                    </form>

                    <?php if ('' !== $queryError): ?>
                        <div class="ipl-info-box ipl-info-box--yellow" style="margin-top:16px;">
                            <i class="fas fa-triangle-exclamation"></i>
                            <span><?php echo ipl_escape($queryError); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($result instanceof Location && '' === $queryError): ?>
                        <div class="flex flex-wrap items-center gap-2 mt-5 mb-4">
                            <span class="ipl-code"><?php echo ipl_escape($result->ip); ?></span>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 text-xs font-medium" style="background: var(--ipl-info-bg); color: var(--ipl-info);"><?php echo ipl_escape(Ip::kindLabel($result->kind)); ?></span>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 text-xs font-medium" style="background: var(--ipl-surface-2); color: var(--ipl-muted);"><?php echo ipl_escape($result->engine ?: _t('未使用库')); ?></span>
                            <span class="text-sm font-semibold" style="color: var(--ipl-text);"><?php echo ipl_escape(Resolver::label($result)); ?></span>
                        </div>

                        <?php if (!empty($result->error)): ?>
                            <div class="ipl-info-box ipl-info-box--yellow">
                                <i class="fas fa-triangle-exclamation"></i>
                                <span><?php echo ipl_escape($result->error); ?></span>
                            </div>
                        <?php endif; ?>

                        <!-- 桌面端表格 -->
                        <div class="overflow-x-auto hidden md:block">
                            <table class="w-full">
                                <thead>
                                    <tr style="background: var(--ipl-surface-2);">
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide border-b" style="color: var(--ipl-muted); border-color: var(--ipl-border);"><?php echo _t('字段'); ?></th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide border-b" style="color: var(--ipl-muted); border-color: var(--ipl-border);"><?php echo _t('取值'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($resultFields as $key => $value): ?>
                                    <tr class="border-b transition-colors hover:opacity-80" style="border-color: var(--ipl-border);">
                                        <td class="px-4 py-3 text-sm" style="color: var(--ipl-muted);"><?php echo ipl_escape($key); ?></td>
                                        <td class="px-4 py-3 text-sm" style="color: var(--ipl-text);"><?php echo ipl_escape(null === $value || '' === $value ? '-' : $value); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <!-- 移动端卡片 -->
                        <div class="md:hidden">
                            <?php foreach ($resultFields as $key => $value): ?>
                                <div class="p-4 border-b" style="border-color: var(--ipl-border); background: var(--ipl-surface);">
                                    <div class="flex justify-between items-center py-2 gap-3">
                                        <span class="text-xs font-medium" style="color: var(--ipl-muted);"><?php echo ipl_escape($key); ?></span>
                                        <span class="text-sm text-right break-all" style="color: var(--ipl-text);"><?php echo ipl_escape(null === $value || '' === $value ? '-' : $value); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if (!empty($result->raw)): ?>
                            <details class="mt-4">
                                <summary class="cursor-pointer select-none text-xs" style="color: var(--ipl-muted);"><?php echo _t('引擎原始返回'); ?></summary>
                                <pre class="mt-2 p-3 overflow-x-auto text-xs whitespace-pre-wrap break-all border" style="background: var(--ipl-surface-2); color: var(--ipl-text); border-color: var(--ipl-border); font-family:'Cascadia Code',monospace;"><?php echo ipl_escape(json_encode($result->raw, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); ?></pre>
                            </details>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 归属地库状态 -->
            <div class="border mb-6" style="background: var(--ipl-surface); border-color: var(--ipl-border);">
                <div class="p-4 border-b" style="background: var(--ipl-surface-2); border-color: var(--ipl-border);">
                    <h2 class="text-sm font-semibold" style="color: var(--ipl-text);">归属地库状态</h2>
                </div>
                <div class="p-5">
                    <!-- 桌面端表格 -->
                    <div class="overflow-x-auto hidden md:block">
                        <table class="w-full">
                            <thead>
                                <tr style="background: var(--ipl-surface-2);">
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide border-b" style="color: var(--ipl-muted); border-color: var(--ipl-border);"><?php echo _t('引擎'); ?></th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide border-b" style="color: var(--ipl-muted); border-color: var(--ipl-border);"><?php echo _t('范围'); ?></th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide border-b" style="color: var(--ipl-muted); border-color: var(--ipl-border);"><?php echo _t('状态'); ?></th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide border-b" style="color: var(--ipl-muted); border-color: var(--ipl-border);"><?php echo _t('大小'); ?></th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide border-b" style="color: var(--ipl-muted); border-color: var(--ipl-border);"><?php echo _t('库版本 / 构建'); ?></th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide border-b" style="color: var(--ipl-muted); border-color: var(--ipl-border);"><?php echo _t('文件时间'); ?></th>
<th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide border-b" style="color: var(--ipl-muted); border-color: var(--ipl-border);"><?php echo _t('文件名'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($statusList as $status): ?>
                                <?php foreach ($status['files'] as $scope => $file): ?>
                                    <tr class="border-b transition-colors hover:opacity-80" style="border-color: var(--ipl-border);">
                                        <td class="px-4 py-3 text-sm" style="color: var(--ipl-text);"><?php echo ipl_escape($status['name']); ?></td>
                                        <td class="px-4 py-3 text-sm" style="color: var(--ipl-muted);"><?php echo ipl_escape($scope); ?></td>
                                        <td class="px-4 py-3">
                                            <?php if (!empty($file['exists']) && !empty($file['readable'])): ?>
                                                <span class="inline-flex items-center gap-1.5 px-3 py-1 text-xs font-medium" style="background: var(--ipl-success-bg); color: var(--ipl-success);"><i class="fas fa-circle-check"></i><?php echo _t('可用'); ?></span>
                                            <?php elseif (!empty($file['exists'])): ?>
                                                <span class="inline-flex items-center gap-1.5 px-3 py-1 text-xs font-medium" style="background: var(--ipl-error-bg); color: var(--ipl-danger);"><i class="fas fa-circle-xmark"></i><?php echo _t('不可读'); ?></span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1.5 px-3 py-1 text-xs font-medium" style="background: var(--ipl-error-bg); color: var(--ipl-danger);"><i class="fas fa-circle-xmark"></i><?php echo _t('缺失'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 text-sm" style="color: var(--ipl-muted);"><?php echo ipl_escape(!empty($file['size']) ? ipl_size($file['size']) : '-'); ?></td>
                                        <td class="px-4 py-3 text-sm" style="color: var(--ipl-muted);"><?php echo ipl_escape($status['buildDate'] ?: ($status['databaseType'] ?: '-')); ?></td>
                                        <td class="px-4 py-3 text-sm" style="color: var(--ipl-muted);"><?php echo ipl_escape(!empty($file['modified']) ? date('Y-m-d', (int) $file['modified']) : '-'); ?></td>
                                        <td class="px-4 py-3 text-sm" style="color: var(--ipl-muted);"><?php echo ipl_escape(!empty($file['path']) ? basename($file['path']) : '-'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- 移动端卡片 -->
                    <div class="md:hidden">
                        <?php foreach ($statusList as $status): ?>
                            <?php foreach ($status['files'] as $scope => $file): ?>
                                <div class="p-4 border-b" style="border-color: var(--ipl-border); background: var(--ipl-surface);">
                                    <div class="flex justify-between items-center py-2 gap-3">
                                        <span class="text-xs font-medium" style="color: var(--ipl-muted);"><?php echo _t('引擎'); ?></span>
                                        <span class="text-sm text-right" style="color: var(--ipl-text);"><?php echo ipl_escape($status['name']); ?></span>
                                    </div>
                                    <div class="flex justify-between items-center py-2 gap-3">
                                        <span class="text-xs font-medium" style="color: var(--ipl-muted);"><?php echo _t('范围'); ?></span>
                                        <span class="text-sm text-right" style="color: var(--ipl-text);"><?php echo ipl_escape($scope); ?></span>
                                    </div>
                                    <div class="flex justify-between items-center py-2 gap-3">
                                        <span class="text-xs font-medium" style="color: var(--ipl-muted);"><?php echo _t('状态'); ?></span>
                                        <span class="text-sm text-right">
                                            <?php if (!empty($file['exists']) && !empty($file['readable'])): ?>
                                                <span class="ipl-text-success"><?php echo _t('可用'); ?></span>
                                            <?php elseif (!empty($file['exists'])): ?>
                                                <span class="ipl-text-danger"><?php echo _t('不可读'); ?></span>
                                            <?php else: ?>
                                                <span class="ipl-text-danger"><?php echo _t('缺失'); ?></span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <div class="flex justify-between items-center py-2 gap-3">
                                        <span class="text-xs font-medium" style="color: var(--ipl-muted);"><?php echo _t('大小'); ?></span>
                                        <span class="text-sm text-right" style="color: var(--ipl-text);"><?php echo ipl_escape(!empty($file['size']) ? ipl_size($file['size']) : '-'); ?></span>
                                    </div>
                                    <div class="flex justify-between items-center py-2 gap-3">
                                        <span class="text-xs font-medium" style="color: var(--ipl-muted);"><?php echo _t('库版本 / 构建'); ?></span>
                                        <span class="text-sm text-right" style="color: var(--ipl-text);"><?php echo ipl_escape($status['buildDate'] ?: ($status['databaseType'] ?: '-')); ?></span>
                                    </div>
                                    <div class="flex justify-between items-center py-2 gap-3">
                                        <span class="text-xs font-medium" style="color: var(--ipl-muted);"><?php echo _t('文件时间'); ?></span>
                                        <span class="text-sm text-right" style="color: var(--ipl-text);"><?php echo ipl_escape(!empty($file['modified']) ? date('Y-m-d', (int) $file['modified']) : '-'); ?></span>
                                    </div>
                                    <div class="flex justify-between items-center py-2 gap-3">
                                        <span class="text-xs font-medium" style="color: var(--ipl-muted);"><?php echo _t('文件名'); ?></span>
                                        <span class="text-sm text-right break-all" style="color: var(--ipl-text);"><?php echo ipl_escape(!empty($file['path']) ? basename($file['path']) : '-'); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="px-5 py-3 border-t text-xs" style="border-color: var(--ipl-border); color: var(--ipl-muted);">
                    <?php echo _t('GeoLite2 由 MaxMind 每周更新，请定期下载新版 mmdb 覆盖同名文件；ip2region 库升级后同样需要清空解析缓存。库目录与文件名均可在设置中自定义，改名后解析缓存自动失效。'); ?>
                </div>
            </div>

            <!-- 运行配置与缓存维护 -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div class="border" style="background: var(--ipl-surface); border-color: var(--ipl-border);">
                    <div class="p-4 border-b" style="background: var(--ipl-surface-2); border-color: var(--ipl-border);">
                        <h2 class="text-sm font-semibold" style="color: var(--ipl-text);">运行配置</h2>
                    </div>
                    <div class="p-5">
                        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6">
                            <?php foreach ($configRows as $key => $value): ?>
                                <div class="flex justify-between items-center py-2 border-b" style="border-color: var(--ipl-border);">
                                    <dt class="text-xs font-medium" style="color: var(--ipl-muted);"><?php echo ipl_escape($key); ?></dt>
                                    <dd class="text-sm text-right" style="color: var(--ipl-text);"><?php echo ipl_escape($value); ?></dd>
                                </div>
                            <?php endforeach; ?>
                        </dl>
                        <p class="text-xs mt-4" style="color: var(--ipl-muted);"><?php echo _t('完整参数请前往「设置 → 插件 → IPLocation」调整。'); ?></p>
                    </div>
                </div>

                <div class="border" style="background: var(--ipl-surface); border-color: var(--ipl-border);">
                    <div class="p-4 border-b" style="background: var(--ipl-surface-2); border-color: var(--ipl-border);">
                        <h2 class="text-sm font-semibold" style="color: var(--ipl-text);">缓存维护</h2>
                    </div>
                    <div class="p-5">
                        <p class="text-sm mb-4" style="color: var(--ipl-muted);">
                            <?php echo _t('当前共有 %d 个缓存文件，占用 %s。库文件更新、更换语言或调整数据源后建议清空，避免读到旧结果。', $cacheStats['count'], ipl_size($cacheStats['size'])); ?>
                        </p>
                        <div class="flex flex-wrap gap-3">
                            <button type="button" id="iplocation-clear-btn" class="flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-medium text-white transition-all hover:opacity-90" style="background: var(--ipl-danger);">
                                <i class="fas fa-trash"></i>
                                <span><?php echo ipl_escape(_t('清空解析缓存')); ?></span>
                            </button>
                            <form method="post" action="<?php echo ipl_escape($requestUrl); ?>">
                                <input type="hidden" name="action" value="refresh_stats">
                                <input type="hidden" name="_" value="<?php echo ipl_escape($token); ?>">
                                <button type="submit" class="flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-medium border transition-all hover:opacity-90" style="background: var(--ipl-surface-2); color: var(--ipl-text); border-color: var(--ipl-border);">
                                    <i class="fas fa-rotate-right"></i>
                                    <span><?php echo ipl_escape(_t('刷新来源分布')); ?></span>
                                </button>
                            </form>
                        </div>
                        <form method="post" action="<?php echo ipl_escape($requestUrl); ?>" id="iplocation-clear-form" class="hidden">
                            <input type="hidden" name="action" value="clear_cache">
                            <input type="hidden" name="_" value="<?php echo ipl_escape($token); ?>">
                        </form>
                    </div>
                </div>
            </div>

            <!-- 评论来源分布 -->
            <div class="border mb-6" style="background: var(--ipl-surface); border-color: var(--ipl-border);">
                <div class="p-4 border-b" style="background: var(--ipl-surface-2); border-color: var(--ipl-border);">
                    <h2 class="text-sm font-semibold" style="color: var(--ipl-text);"><?php echo _t('评论来源分布'); ?></h2>
                </div>
                <div class="p-5">
                    <?php if (!empty($stats['error'])): ?>
                        <div class="ipl-info-box ipl-info-box--yellow">
                            <i class="fas fa-triangle-exclamation"></i>
                            <span><?php echo ipl_escape($stats['error']); ?></span>
                        </div>
                    <?php elseif (empty($stats['counter'])): ?>
                        <div class="py-12 text-center">
                            <div><i class="fas fa-chart-line text-5xl" style="color: var(--ipl-border-strong);"></i></div>
                            <div class="mt-2" style="color: var(--ipl-muted);"><?php echo _t('暂无评论数据'); ?></div>
                        </div>
                    <?php else: ?>
                        <!-- 桌面端表格 -->
                        <div class="overflow-x-auto hidden md:block">
                            <table class="w-full">
                                <thead>
                                    <tr style="background: var(--ipl-surface-2);">
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide border-b" style="color: var(--ipl-muted); border-color: var(--ipl-border);"><?php echo _t('归属地'); ?></th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide border-b" style="color: var(--ipl-muted); border-color: var(--ipl-border);"><?php echo _t('评论数'); ?></th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide border-b" style="color: var(--ipl-muted); border-color: var(--ipl-border);"><?php echo _t('占比'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php
                                $statsTotal = max(1, (int) $stats['total']);

                                foreach ($stats['counter'] as $label => $count): ?>
                                    <tr class="border-b transition-colors hover:opacity-80" style="border-color: var(--ipl-border);">
                                        <td class="px-4 py-3 text-sm" style="color: var(--ipl-text);"><?php echo ipl_escape($label); ?></td>
                                        <td class="px-4 py-3 text-sm" style="color: var(--ipl-text);"><?php echo ipl_escape($count); ?></td>
                                        <td class="px-4 py-3">
                                            <div class="flex items-center gap-3">
                                                <div class="w-32 h-2 border" style="background: var(--ipl-surface-2); border-color: var(--ipl-border);">
                                                    <div style="width: <?php echo ipl_escape(round((int) $count / $statsTotal * 100, 1)); ?>%; height:100%; background: var(--ipl-primary);"></div>
                                                </div>
                                                <span class="text-xs" style="color: var(--ipl-muted);"><?php echo ipl_escape(round((int) $count / $statsTotal * 100, 1)); ?>%</span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <!-- 移动端卡片 -->
                        <div class="md:hidden">
                            <?php foreach ($stats['counter'] as $label => $count): ?>
                                <div class="p-4 border-b" style="border-color: var(--ipl-border); background: var(--ipl-surface);">
                                    <div class="flex justify-between items-center py-2 gap-3">
                                        <span class="text-xs font-medium" style="color: var(--ipl-muted);"><?php echo _t('归属地'); ?></span>
                                        <span class="text-sm text-right" style="color: var(--ipl-text);"><?php echo ipl_escape($label); ?></span>
                                    </div>
                                    <div class="flex justify-between items-center py-2 gap-3">
                                        <span class="text-xs font-medium" style="color: var(--ipl-muted);"><?php echo _t('评论数'); ?></span>
                                        <span class="text-sm text-right" style="color: var(--ipl-text);"><?php echo ipl_escape($count); ?></span>
                                    </div>
                                    <div class="flex justify-between items-center py-2 gap-3">
                                        <span class="text-xs font-medium" style="color: var(--ipl-muted);"><?php echo _t('占比'); ?></span>
                                        <span class="text-sm text-right" style="color: var(--ipl-text);"><?php echo ipl_escape(round((int) $count / $statsTotal * 100, 1)); ?>%</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="text-xs mt-4" style="color: var(--ipl-muted);"><?php echo _t('基于最近 %d 条评论统计，结果缓存 10 分钟', $stats['total']); ?></p>
                        <?php if (!empty($stats['invalid'])): ?>
                            <p class="text-xs mt-1" style="color: var(--ipl-muted);"><?php echo _t('另有 %d 条评论的 IP 为空或格式非法，未纳入统计', (int) $stats['invalid']); ?></p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 使用说明 -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="border" style="background: var(--ipl-surface); border-color: var(--ipl-border);">
                    <div class="p-4 border-b" style="background: var(--ipl-surface-2); border-color: var(--ipl-border);">
                        <h2 class="text-sm font-semibold" style="color: var(--ipl-text);"><?php echo _t('解析链路'); ?></h2>
                    </div>
                    <div class="p-5">
                        <ul class="space-y-3 text-sm" style="color: var(--ipl-muted);">
                            <li class="flex gap-3">
                                <span>自动数据源下优先查询 ip2region（中文省市与运营商，离线毫秒级）</span>
                            </li>
                            <li class="flex gap-3">
                                <span>ip2region 返回「未知 / 0 / Reserved」或字段不全时，自动改查 MaxMind GeoLite2，并用其结果补全国籍代码、经纬度、时区、ASN 等细节</span>
                            </li>
                            <li class="flex gap-3">
                                <span>两个引擎都未命中时输出「<?php echo ipl_escape($config['unknownText']); ?>」；局域网、环回等地址直接给出类型文案，不查库也不暴露细节</span>
                            </li>
                            <li class="flex gap-3">
                                <span>全部查询本地完成，不会向任何第三方服务发送请求</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="border" style="background: var(--ipl-surface); border-color: var(--ipl-border);">
                    <div class="p-4 border-b" style="background: var(--ipl-surface-2); border-color: var(--ipl-border);">
                        <h2 class="text-sm font-semibold" style="color: var(--ipl-text);"><?php echo _t('安全说明'); ?></h2>
                    </div>
                    <div class="p-5">
                        <ul class="space-y-3 text-sm" style="color: var(--ipl-muted);">
                            <li class="flex gap-3">
                                <span><?php
                                if (empty($config['proxyEnabled'])) {
                                    echo _t('当前未启用代理 IP 还原，一律使用直连地址 REMOTE_ADDR，可杜绝转发头伪造');
                                } elseif (!empty($config['proxyCidr'])) {
                                    echo empty($trustedProxies)
                                        ? _t('已启用 CIDR 校验但白名单为空：将一律使用 REMOTE_ADDR，请在设置中补充 CDN 回源 IP 段')
                                        : _t('已启用 CIDR 校验（%d 条）：仅当直连地址命中白名单时才采信转发头，并从右往左跳过可信代理取真实客户端地址', count($trustedProxies));
                                } else {
                                    echo _t('已开启代理还原但关闭了 CIDR 校验：任何直达源站的请求都可能伪造客户端 IP，建议补充白名单或配置 CDN 回源认证');
                                }
                                ?></span>
                            </li>
                            <li class="flex gap-3">
                                <span><?php
                                if (empty($config['showFront'])) {
                                    echo _t('前台评论属地已关闭，仅在后台评论列表展示');
                                } elseif ('masked' === $config['privacy']) {
                                    echo _t('当前为「属地 + 脱敏 IP」模式：IPv4 隐藏最后一段（1.2.3.*），IPv6 仅保留前两段');
                                } else {
                                    echo _t('前台只输出归属地文案，不展示完整 IP；需要排障时可临时切换为「属地 + 脱敏 IP」');
                                }
                                ?></span>
                            </li>
                            <li class="flex gap-3">
                                <span><?php echo empty($config['anonymousStore'])
                                    ? _t('评论 IP 按原始值入库，请知悉你所在地的隐私合规要求')
                                    : _t('评论 IP 匿名化后入库（IPv4 归零最后一段，IPv6 保留前 3 段），按 IP 的封禁与查重精度会受影响'); ?></span>
                            </li>
                            <li class="flex gap-3">
                                <span><?php echo _t('归属地文案一律经过 HTML 转义输出，属地库路径不接受外部输入，缓存键限定为 md5'); ?></span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- 页脚 -->
    <footer class="border-t py-6" style="background: var(--ipl-surface); border-color: var(--ipl-border);">
        <div class="px-6 flex flex-col md:flex-row items-center justify-center gap-4 text-xs">
            <div class="flex items-center gap-3" style="color: var(--ipl-muted);">
                <span>IPLocation 插件</span>
                <span class="hidden sm:inline opacity-50">|</span>
                <span class="hidden sm:inline"><?php echo _t('离线解析，不向任何第三方发送查询请求'); ?></span>
            </div>
            <div class="flex items-center gap-2 px-3 py-1.5 border" style="border-color: var(--ipl-border); background: var(--ipl-surface-2);">
                <span class="font-medium" style="color: var(--ipl-text);">ip2region xdb + MaxMind GeoLite2</span>
            </div>
        </div>
    </footer>

    <!-- 弹窗 -->
    <div id="iplocation-modal-overlay" class="hidden fixed inset-0 z-50" style="background: rgba(0, 0, 0, 0.5);"></div>
    <div id="iplocation-modal" class="hidden fixed top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-11/12 max-w-md border shadow-lg z-50" style="background: var(--ipl-surface); border-color: var(--ipl-border);">
        <div class="flex items-center justify-between p-4 border-b" style="border-color: var(--ipl-border);">
            <h3 id="iplocation-modal-title" class="text-sm font-medium" style="color: var(--ipl-text);"><?php echo _t('确认'); ?></h3>
            <button type="button" id="iplocation-modal-close" class="p-1.5 transition-colors hover:opacity-70" style="color: var(--ipl-muted);" aria-label="<?php echo ipl_escape(_t('关闭')); ?>">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div id="iplocation-modal-body" class="p-5 text-sm" style="color: var(--ipl-muted);"></div>
        <div class="flex justify-end gap-2 p-4">
            <button type="button" id="iplocation-modal-cancel" class="px-4 py-2 text-sm font-medium border transition-colors hover:opacity-80" style="background: var(--ipl-surface); color: var(--ipl-danger); border-color: var(--ipl-border);">
                <?php echo _t('取消'); ?>
            </button>
            <button type="button" id="iplocation-modal-confirm" class="px-4 py-2 text-sm font-medium text-white transition-colors hover:opacity-90" style="background: var(--ipl-primary);">
                <?php echo _t('确定'); ?>
            </button>
        </div>
    </div>

    <script>
    (function() {
        var clearBtn = document.getElementById('iplocation-clear-btn');
        var clearForm = document.getElementById('iplocation-clear-form');
        var modalOverlay = document.getElementById('iplocation-modal-overlay');
        var modal = document.getElementById('iplocation-modal');
        var modalTitle = document.getElementById('iplocation-modal-title');
        var modalBody = document.getElementById('iplocation-modal-body');
        var modalClose = document.getElementById('iplocation-modal-close');
        var modalCancel = document.getElementById('iplocation-modal-cancel');
        var modalConfirm = document.getElementById('iplocation-modal-confirm');
        var currentResolve = null;

        function showModal(title, message, confirmText, cancelText) {
            return new Promise(function(resolve) {
                currentResolve = resolve;
                modalTitle.textContent = title || '确认';
                modalBody.innerHTML = '<p>' + message.replace(/\n\n/g, '</p><p class="mt-3">') + '</p>';
                modalConfirm.textContent = confirmText || '确定';
                modalCancel.textContent = cancelText || '取消';
                modalOverlay.classList.remove('hidden');
                modal.classList.remove('hidden');
            });
        }

        function hideModal(result) {
            modalOverlay.classList.add('hidden');
            modal.classList.add('hidden');
            if (currentResolve) {
                currentResolve(result);
                currentResolve = null;
            }
        }

        modalClose.addEventListener('click', function() { hideModal(false); });
        modalCancel.addEventListener('click', function() { hideModal(false); });
        modalConfirm.addEventListener('click', function() { hideModal(true); });
        modalOverlay.addEventListener('click', function(e) {
            if (e.target === modalOverlay) hideModal(false);
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && !modalOverlay.classList.contains('hidden')) hideModal(false);
        });

        function confirmDialog(message) {
            return showModal('确认', message, '确定', '取消');
        }

        if (clearBtn && clearForm) {
            clearBtn.addEventListener('click', function() {
                confirmDialog('确定要清空全部解析缓存吗？\n\n下次查询将重新读取归属地库，可能短暂变慢。').then(function(result) {
                    if (result) clearForm.submit();
                });
            });
        }
    })();
    </script>
</body>
</html>
