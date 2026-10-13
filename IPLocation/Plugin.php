<?php

/**
 * IPLocation 插件
 * 为 Typecho 设计的 IP 归属地解析和展示插件，基于 IP2Region 和 MindMax 的开源数据库。
 * 
 * @package IPLocation
 * @author GARFIELDTOM
 * @version 1.2.0
 * @link https://www.garfieldtom.cool
 */

namespace TypechoPlugin\IPLocation;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Number;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Select;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Textarea;
use Widget\Base\Comments;
use Widget\Feedback;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/** 注册插件自身类与第三方 SDK 的按需加载（后台面板等不加载本文件的场景由 Resolver.php 兜底引入） */
require_once __DIR__ . '/Tools/Bootstrap.php';

class Plugin implements PluginInterface
{
    /** 插件版本（同时用作面板静态资源缓存后缀）。 */
    public const VERSION = '1.2.0';

    /** 后台面板文件（相对插件目录）。 */
    public const PANEL_FILE = 'IPLocation/Panel.php';

    /** 后台面板挂载的父菜单索引：3 = 管理。 */
    public const PANEL_MENU_INDEX = 3;

    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     *
     * @return string|void
     * @throws \Typecho\Plugin\Exception
     */
    public static function activate()
    {
        if (version_compare(PHP_VERSION, '7.4.0', '<')) {
            throw new \Typecho\Plugin\Exception(_t('IPLocation 需要 PHP 7.4 及以上版本'));
        }

        /** 评论入库前校正 IP（防 X-Forwarded-For 伪造，按配置可匿名化） */
        \Typecho\Plugin::factory(Feedback::class)->comment = [__CLASS__, 'filterComment'];

        /** 评论列表中的 $comments->ip() 调用，追加属地标签 */
        \Typecho\Plugin::factory(Comments::class)->callIp = [__CLASS__, 'callIp'];

        /** 主题可用的虚拟字段：$comments->ipLocation / $comments->ipLocationLabel */
        \Typecho\Plugin::factory(Comments::class)->___ipLocation = [__CLASS__, 'fieldLocation'];
        \Typecho\Plugin::factory(Comments::class)->___ipLocationLabel = [__CLASS__, 'fieldLocationLabel'];

        /** 面板登记幂等：重复启用不会产生重复菜单项 */
        $panelTable = \Widget\Options::alloc()->panelTable;
        $panelFile = urlencode(self::PANEL_FILE);
        $registered = !empty($panelTable['file']) && in_array($panelFile, $panelTable['file'], true);

        if (!$registered) {
            \Utils\Helper::addPanel(
                self::PANEL_MENU_INDEX,
                self::PANEL_FILE,
                _t('IP 归属地面板'),
                _t('归属地库状态、查询自检与缓存维护'),
                'administrator'
            );
        }

        /** 预建缓存目录并写入保护文件 */
        Cache::prepare();

        return _t('IPLocation 已启用，请在「管理 → IP 属地」确认归属地库状态');
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     *
     * @return void
     */
    public static function deactivate()
    {
        \Utils\Helper::removePanel(self::PANEL_MENU_INDEX, self::PANEL_FILE);
        Config::flush();
    }

    /**
     * 获取插件配置面板。
     *
     * 说明区采用与 Passkey 一致的设计语言：info-box 卡片 + 直角边框 +
     * 暗色模式媒体查询，随后台主题自动适配。
     *
     * @param Form $form 配置面板
     * @return void
     */
    public static function config(Form $form)
    {
        $options = \Widget\Options::alloc();
        $pluginUrl = $options->pluginUrl . '/IPLocation';
        $files = Config::databaseFiles();
        $missing = [];

        foreach ($files as $file) {
            if (!is_readable($file)) {
                $missing[] = basename($file);
            }
        }

        $styles = '<style>
        /* IPLocation 插件 - 设置页样式与暗色模式适配 */
        .ipl-info-box { background: #f0f9ff; border-left: 3px solid #0ea5e9; }
        .ipl-info-box--gray { background: #f5f5f5; border-left: 3px solid #467B96; }
        .ipl-info-box--yellow { background: #fff3cd; border-left: 3px solid #ffc107; }
        .ipl-text-muted { color: #666; }
        .ipl-text-dim { color: #555; }
        .ipl-text-success { color: #155724; }
        .ipl-text-danger { color: #721c24; }
        .ipl-textarea-code { border: 1px solid #ddd; background: #fff; color: inherit; }
        .ipl-warn-danger { color: #dc2626; }
        @media (prefers-color-scheme: dark) {
            .ipl-info-box { background: #0c2d48; border-left-color: #0ea5e9; }
            .ipl-info-box--gray { background: #1a1a2e; border-left-color: #6ba3be; }
            .ipl-info-box--yellow { background: #332b00; border-left-color: #fbbf24; }
            .ipl-text-muted { color: #9ca3af; }
            .ipl-text-dim { color: #a3a3a3; }
            .ipl-text-success { color: #4ade80; }
            .ipl-text-danger { color: #f87171; }
            .ipl-textarea-code { border-color: #444; background: #1e1e1e; color: #e5e5e5; }
            .ipl-warn-danger { color: #f87171; }
        }
        </style>';

        $dataSourceDescription = $styles . _t('选择归属地数据的解析链路。');
        $dataSourceDescription .= '<div class="ipl-info-box" style="padding:12px 15px;margin-top:8px;">';
        $dataSourceDescription .= '<strong>' . _t('自动（推荐）') . '</strong><br>';
        $dataSourceDescription .= '<span class="ipl-text-muted">' . _t('优先查询 ip2region（中文省市与运营商，离线毫秒级）；') . '</span><br>';
        $dataSourceDescription .= '<span class="ipl-text-muted">' . _t('当 ip2region 返回「未知 / 0」或字段不全时，自动改查 MaxMind GeoLite2，并用其结果补全国籍代码、经纬度、时区、ASN 等细节。') . '</span>';
        $dataSourceDescription .= '</div>';

        if (!empty($missing)) {
            $dataSourceDescription .= '<div class="ipl-info-box--yellow" style="padding:10px;margin-top:8px;">';
            $dataSourceDescription .= '<strong class="ipl-warn-danger">' . _t('注意：') . '</strong>';
            $dataSourceDescription .= '<span class="ipl-text-muted">' . _t(
                '以下库文件缺失或不可读：%s，请放置到插件 Database 目录后再使用。',
                implode('、', $missing)
            ) . '</span>';
            $dataSourceDescription .= '</div>';
        }

        $dataSource = new Select(
            'dataSource',
            [
                Config::SOURCE_AUTO      => _t('自动（ip2region 优先，未知或字段不全时回退 GeoLite2 并补全细节）'),
                Config::SOURCE_IP2REGION => _t('仅 ip2region（中文省市与运营商）'),
                Config::SOURCE_GEOIP2    => _t('仅 GeoLite2（多语言 / 经纬度 / ASN）'),
            ],
            Config::SOURCE_AUTO,
            _t('解析数据源'),
            $dataSourceDescription
        );
        $form->addInput($dataSource);

        $fallback = new Radio(
            'fallback',
            [1 => _t('开启'), 0 => _t('关闭')],
            1,
            _t('ip2region 未命中时回退 GeoLite2'),
            _t('仅自动数据源下生效。开启后 ip2region 返回「未知」的地址会继续由 MaxMind 兜底，'
                . '两个引擎都未命中才输出下方「未知归属地文案」。')
        );
        $form->addInput($fallback);

        $enrichDescription = $styles . _t('ip2region 命中但字段不全时，用 GeoLite2 的结果补全缺失字段（不改动已有值）。');
        $enrichDescription .= '<div class="ipl-info-box--gray" style="padding:10px;margin-top:8px;">';
        $enrichDescription .= '<strong>' . _t('补全内容：') . '</strong>';
        $enrichDescription .= '<span class="ipl-text-dim">' . _t(
            '国家代码、洲、行政区、城市、运营商、ASN、邮编、时区、经纬度、命中网段。'
        ) . '</span>';
        $enrichDescription .= '</div>';

        $enrich = new Radio(
            'enrich',
            [1 => _t('开启'), 0 => _t('关闭')],
            1,
            _t('用 GeoLite2 补全细节'),
            $enrichDescription
        );
        $form->addInput($enrich);

        $geoipDatabase = new Select(
            'geoipDatabase',
            [
                'city'    => _t('GeoLite2-City（国家 / 省市 / 经纬度）'),
                'country' => _t('GeoLite2-Country（仅国家）'),
                'asn'     => _t('GeoLite2-ASN（自治域与运营商）'),
            ],
            'city',
            _t('GeoLite2 库'),
            _t('数据源选择 GeoLite2、或自动回退 / 补全时生效；选 City 可获得最完整的细节')
        );
        $form->addInput($geoipDatabase);

        /** ── 自定义库目录与库文件名（安全加固：避免默认路径被探测） ── */
        $defaultDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Database';
        $dir = Config::databaseDir();
        $present = [];
        $dirWarning = '';

        if (is_dir($dir)) {
            foreach (scandir($dir) ?: [] as $name) {
                if ('.' !== $name && '..' !== $name && is_file($dir . DIRECTORY_SEPARATOR . $name)) {
                    $present[] = $name;
                }
            }
        } elseif (is_dir($defaultDir)) {
            $dirWarning = _t('当前配置的库目录不存在，以下显示的是默认 Database 目录的内容；如已改名，请把文件夹同步改名为上方填写名称。');

            foreach (scandir($defaultDir) ?: [] as $name) {
                if ('.' !== $name && '..' !== $name && is_file($defaultDir . DIRECTORY_SEPARATOR . $name)) {
                    $present[] = $name;
                }
            }
        }

        $form->addInput(new Text(
            'dataDir',
            null,
            '',
            _t('归属地库目录名'),
            $styles . _t('默认 Database。为安全起见，可修改该文件夹的名称（连同内部库文件一起移动），再把新名称填在这里。')
            . '<div class="ipl-info-box--yellow" style="padding:10px;margin-top:8px;">'
            . '<strong class="ipl-warn-danger">' . _t('注意：') . '</strong>'
            . '<span class="ipl-text-muted">' . _t(
                '仅允许字母、数字与 . _ - ，不含路径分隔符；留空或非法时使用默认目录。'
                . '改名后解析缓存随目录名自动失效，无需手动清空；Apache 下随文件夹迁移的 .htaccess 会继续生效。'
            ) . '</span></div>'
        ));

        $filesDescription = $styles . _t('为安全起见，还可自行修改库文件的名称，再把新名称填在下方对应输入框。');
        $filesDescription .= '<div class="ipl-info-box--yellow" style="padding:10px;margin-top:8px;">';
        $filesDescription .= '<strong class="ipl-warn-danger">' . _t('注意：') . '</strong>';
        $filesDescription .= '<span class="ipl-text-muted">' . _t(
            '留空使用默认名；仅允许字母、数字与 . _ - ，且不含路径分隔符，非法输入自动回落默认名。'
            . '解析缓存键含库文件名，改名后旧缓存自动失效，无需手动清空。'
        ) . '</span>';
        $filesDescription .= '</div>';

        if ('' !== $dirWarning) {
            $filesDescription .= '<div class="ipl-info-box--yellow" style="padding:10px;margin-top:8px;">';
            $filesDescription .= '<strong class="ipl-warn-danger">' . _t('注意：') . '</strong>';
            $filesDescription .= '<span class="ipl-text-muted">' . $dirWarning . '</span>';
            $filesDescription .= '</div>';
        }

        if (!empty($present)) {
            $scanned = is_dir($dir) ? $dir : $defaultDir;
            $filesDescription .= '<div class="ipl-info-box--gray" style="padding:10px;margin-top:8px;">';
            $filesDescription .= '<strong>' . _t('目录 %s 下的文件：', basename($scanned)) . '</strong><br>';
            $filesDescription .= '<span class="ipl-text-dim">'
                . implode('、', array_map('htmlspecialchars', $present)) . '</span>';
            $filesDescription .= '</div>';
        }

        $fileFields = [
            'fileIp2RegionV4'  => [_t('ip2region IPv4 库文件名'), 'ip2region_v4.xdb'],
            'fileIp2RegionV6'  => [_t('ip2region IPv6 库文件名'), 'ip2region_v6.xdb'],
            'fileGeoipCity'    => [_t('GeoLite2-City 文件名'), 'GeoLite2-City.mmdb'],
            'fileGeoipCountry' => [_t('GeoLite2-Country 文件名'), 'GeoLite2-Country.mmdb'],
            'fileGeoipAsn'     => [_t('GeoLite2-ASN 文件名'), 'GeoLite2-ASN.mmdb'],
        ];

        $first = true;

        foreach ($fileFields as $key => [$label, $default]) {
            $form->addInput(new Text(
                $key,
                null,
                '',
                $label,
                $first ? $filesDescription : _t('留空使用默认名 %s', $default)
            ));

            $first = false;
        }

        $language = new Select(
            'language',
            [
                'zh-CN'  => _t('简体中文'),
                'en'     => _t('English'),
                'ja'     => _t('日本語'),
                'ru'     => _t('Русский'),
                'de'     => _t('Deutsch'),
                'fr'     => _t('Français'),
                'es'     => _t('Español'),
                'pt-BR'  => _t('Português (BR)'),
            ],
            'zh-CN',
            _t('展示语言'),
            _t('GeoLite2 缺失对应语言时自动回退英文；ip2region 恒为中文')
        );
        $form->addInput($language);

        $detail = new Select(
            'detail',
            [
                'smart'   => _t('智能（国内到省市，国外到国家）'),
                'country' => _t('仅国家'),
                'region'  => _t('国家 + 一级行政区'),
                'city'    => _t('国家 + 行政区 + 城市'),
                'isp'     => _t('全部（含运营商 / ASN）'),
            ],
            'smart',
            _t('展示粒度')
        );
        $form->addInput($detail);

        $format = new Text(
            'format',
            null,
            '',
            _t('自定义模板'),
            _t('留空使用上面的粒度。占位符：%continent% %country% %region% %city% %isp% %asn% %ip%')
        );
        $form->addInput($format);

        $showFrontDescription = $styles . _t('在前台评论列表中显示归属地。');
        $showFrontDescription .= '<div class="ipl-info-box--gray" style="padding:10px;margin-top:8px;">';
        $showFrontDescription .= '<strong>' . _t('主题调用方式（二选一）：') . '</strong><br>';
        $showFrontDescription .= '<span class="ipl-text-dim">' . _t('直接输出标签：') . '</span>';
        $showFrontDescription .= '<textarea readonly onclick="this.select()" class="ipl-textarea-code" style="width:100%;height:70px;font-family:Consolas,Monaco,monospace;font-size:11px;margin-top:6px;padding:10px;">';
        $showFrontDescription .= htmlspecialchars("<?php echo IPLocation_Plugin::render(\$comments->ip); ?>");
        $showFrontDescription .= '</textarea>';
        $showFrontDescription .= '<span class="ipl-text-dim">' . _t('或取原始值自行排版：') . '</span>';
        $showFrontDescription .= '<textarea readonly onclick="this.select()" class="ipl-textarea-code" style="width:100%;height:40px;font-family:Consolas,Monaco,monospace;font-size:11px;margin-top:6px;padding:10px;">';
        $showFrontDescription .= htmlspecialchars('<?php $loc = $comments->ipLocation; echo $loc->country, $loc->region, $loc->city; ?>');
        $showFrontDescription .= '</textarea>';
        $showFrontDescription .= '</div>';

        $showFront = new Radio(
            'showFront',
            [1 => _t('显示'), 0 => _t('隐藏')],
            1,
            _t('前台评论属地'),
            $showFrontDescription
        );
        $form->addInput($showFront);

        $showAdmin = new Radio(
            'showAdmin',
            [1 => _t('显示'), 0 => _t('隐藏')],
            1,
            _t('后台评论列表属地'),
            _t('在「管理 → 评论」的 IP 后追加属地标签')
        );
        $form->addInput($showAdmin);

        $privacy = new Radio(
            'privacy',
            ['label' => _t('只显示属地'), 'masked' => _t('属地 + 脱敏 IP')],
            'label',
            _t('前台隐私级别'),
            _t('脱敏形式：IPv4 隐藏最后一段（1.2.3.*），IPv6 仅保留前两段')
        );
        $form->addInput($privacy);

        $showIsp = new Radio(
            'showIsp',
            [1 => _t('显示'), 0 => _t('隐藏')],
            0,
            _t('附加运营商信息'),
            _t('在智能粒度基础上追加运营商 / ASN 名称')
        );
        $form->addInput($showIsp);

        $trimSuffix = new Radio(
            'trimSuffix',
            [1 => _t('开启'), 0 => _t('关闭')],
            1,
            _t('精简行政区后缀'),
            _t('例如「北京市」显示为「北京」')
        );
        $form->addInput($trimSuffix);

        $unknownText = new Text(
            'unknownText',
            null,
            '未知',
            _t('未知归属地文案'),
            _t('所有引擎都未命中、以及内网 / 环回等特殊地址使用的占位文案')
        );
        $form->addInput($unknownText);

        $cacheDescription = $styles . _t('解析结果写入插件 cache 目录，按地址 + 引擎组合 + 库版本生成缓存键。');
        $cacheDescription .= '<div class="ipl-info-box--gray" style="padding:10px;margin-top:8px;">';
        $cacheDescription .= '<span class="ipl-text-dim">' . _t(
            '库文件更新、更换语言或调整数据源后，可到「管理 → IP 属地」面板一键清空。'
        ) . '</span>';
        $cacheDescription .= '</div>';

        $cache = new Radio(
            'cache',
            [1 => _t('开启'), 0 => _t('关闭')],
            1,
            _t('查询结果缓存'),
            $cacheDescription
        );
        $form->addInput($cache);

        $cacheTtl = new Number(
            'cacheTtl',
            null,
            30,
            _t('缓存有效期（天）'),
            _t('取值 0 ~ 3650，0 表示不缓存')
        );
        $form->addInput($cacheTtl);

        $proxyEnabledDescription = $styles . _t('是否从反向代理的转发头中还原评论者真实 IP。');
        $proxyEnabledDescription .= '<div class="ipl-info-box--gray" style="padding:10px;margin-top:8px;">';
        $proxyEnabledDescription .= '<span class="ipl-text-dim">' . _t(
            '关闭：一律使用直连地址 REMOTE_ADDR，任何转发头均不可信（最安全，推荐直连部署使用）。'
            . '开启：按下方「CIDR 白名单校验 / 自定义请求头」的规则还原真实 IP。'
        ) . '</span>';
        $proxyEnabledDescription .= '</div>';

        $form->addInput(new Radio(
            'proxyEnabled',
            [1 => _t('开启'), 0 => _t('关闭')],
            0,
            _t('代理 IP 还原'),
            $proxyEnabledDescription
        ));

        $proxyCidrDescription = $styles . _t('启用后，仅当直连地址命中下方白名单时才采信转发头。');
        $proxyCidrDescription .= '<div class="ipl-info-box--yellow" style="padding:10px;margin-top:8px;">';
        $proxyCidrDescription .= '<strong class="ipl-warn-danger">' . _t('安全提示：') . '</strong>';
        $proxyCidrDescription .= '<span class="ipl-text-muted">' . _t(
            '启用 CIDR 需要确保当前 CDN 回源 IP 固定，且已全部填入下方白名单，且定期更新；'
          . '关闭 CIDR 需要确保当前 CDN 双向验证，并避免源站 IP 被直接访问，以避免伪造攻击。'
        ) . '</span>';
        $proxyCidrDescription .= '</div>';

        $form->addInput(new Radio(
            'proxyCidr',
            [1 => _t('开启'), 0 => _t('关闭')],
            1,
            _t('CIDR 白名单校验'),
            $proxyCidrDescription
        ));

        $proxyHeaderDescription = $styles . _t('启用后，优先读取你在下方填写的请求头获取客户端 IP，其次才是内置转发头。');
        $proxyHeaderDescription .= '<div class="ipl-info-box--gray" style="padding:10px;margin-top:8px;">';
        $proxyHeaderDescription .= '<span class="ipl-text-dim">' . _t(
            '适用于 CDN 回源时写入自定义头的场景（如自建网关、边缘函数）；'
            . '该头取值必须是合法 IP，否则自动回退到内置转发头。'
        ) . '</span>';
        $proxyHeaderDescription .= '</div>';

        $form->addInput(new Radio(
            'proxyHeader',
            [1 => _t('开启'), 0 => _t('关闭')],
            0,
            _t('自定义请求头'),
            $proxyHeaderDescription
        ));

        $form->addInput(new Text(
            'proxyHeaderName',
            null,
            '',
            _t('自定义请求头名称'),
            _t('如 X-Client-IP；仅允许字母、数字与 - _ . ，开启「自定义请求头」后生效')
        ));

        $trustedProxiesDescription = $styles . _t('每行一条 CIDR 或单个地址，# 开头为注释；「CIDR 白名单校验」开启时生效。');
        $trustedProxiesDescription .= '<div class="ipl-info-box--gray" style="padding:10px;margin-top:8px;">';
        $trustedProxiesDescription .= '<strong>' . _t('支持的转发头：') . '</strong>';
        $trustedProxiesDescription .= '<span class="ipl-text-dim">' . _t(
            'EO-Connecting-IP（EdgeOne）、CF-Connecting-IP（Cloudflare）、X-Forwarded-For、'
            . 'Forwarded、X-Real-IP、X-Client-IP、Client-IP。'
        ) . '</span>';
        $trustedProxiesDescription .= '</div>';
        $trustedProxiesDescription .= '<div class="ipl-info-box--yellow" style="padding:10px;margin-top:8px;">';
        $trustedProxiesDescription .= '<strong class="ipl-warn-danger">' . _t('安全提示：') . '</strong>';
        $trustedProxiesDescription .= '<span class="ipl-text-muted">' . _t(
            '留空则一律使用直连地址 REMOTE_ADDR，可彻底杜绝 X-Forwarded-For 伪造；'
            . '站点位于 CDN / 反向代理后时必须填写，否则评论 IP 会记录为代理节点地址。'
        ) . '</span>';
        $trustedProxiesDescription .= '</div>';

        $trustedProxies = new Textarea(
            'trustedProxies',
            null,
            '',
            _t('可信代理 CIDR'),
            $trustedProxiesDescription
        );
        $form->addInput($trustedProxies);

        $anonymousStore = new Radio(
            'anonymousStore',
            [1 => _t('匿名化后入库'), 0 => _t('保存原始 IP')],
            0,
            _t('评论 IP 入库策略'),
            _t('匿名化：IPv4 归零最后一段（/24），IPv6 仅保留前 3 段（/48）。开启后将影响按 IP 的封禁与查重')
        );
        $form->addInput($anonymousStore);
    }

    /**
     * 个人用户的配置面板
     *
     * @param Form $form
     * @return void
     */
    public static function personalConfig(Form $form)
    {
    }

    // ── 钩子实现 ──

    /**
     * 评论入库前校正 IP。
     *
     * 仅当直连地址命中可信代理列表时才采信转发头，并按配置决定是否匿名化入库。
     *
     * @param array $comment 评论数据
     * @param mixed $content 当前内容对象（Typecho 传入，未使用）
     * @return array
     */
    public static function filterComment(array $comment, $content = null)
    {
        $config = Config::all();
        $request = \Typecho\Request::getInstance();
        $headers = [
            'x-forwarded-for'   => (string) $request->getHeader('X-Forwarded-For'),
            'forwarded'         => (string) $request->getHeader('Forwarded'),
            'eo-connecting-ip'  => (string) $request->getHeader('EO-Connecting-IP'),
            'cf-connecting-ip'  => (string) $request->getHeader('CF-Connecting-IP'),
            'x-real-ip'         => (string) $request->getHeader('X-Real-Ip'),
            'x-client-ip'       => (string) $request->getHeader('X-Client-Ip'),
            'client-ip'         => (string) $request->getHeader('Client-Ip'),
        ];

        /** 自定义请求头（「自定义请求头」开关启用时优先采信） */
        $customName = trim((string) $config['proxyHeaderName']);

        if ('' !== $customName) {
            $headers['custom'] = (string) $request->getHeader($customName);
        }

        $ip = Ip::clientIp(
            $headers,
            (string) $request->getServer('REMOTE_ADDR'),
            Config::proxyList(),
            $config
        );

        if ('' === $ip) {
            return $comment;
        }

        $comment['ip'] = empty($config['anonymousStore']) ? $ip : Ip::anonymize($ip);

        return $comment;
    }

    /**
     * 评论列表中的 IP 输出（$comments->ip()）。
     *
     * 后台：输出完整 IP + 属地标签；前台：默认只输出属地，避免泄露访客 IP。
     *
     * @param Comments $comments 评论对象
     * @param array|null $args 调用参数（未使用）
     * @return void
     */
    public static function callIp($comments, $args = null)
    {
        $config = Config::all();
        $ip = (string) ($comments->ip ?? '');

        if (defined('__TYPECHO_ADMIN__')) {
            echo self::escape($ip);

            if (!empty($config['showAdmin'])) {
                $label = Resolver::label(Resolver::resolve($ip));

                if ('' !== $label) {
                    echo '<span class="ip-location-tag">' . self::escape($label) . '</span>';
                }
            }

            return;
        }

        if (empty($config['showFront'])) {
            echo self::escape($ip);
            return;
        }

        $label = Resolver::label(Resolver::resolve($ip));
        $parts = [];

        if ('masked' === $config['privacy']) {
            $masked = Ip::mask($ip);

            if ('' !== $masked) {
                $parts[] = $masked;
            }
        }

        if ('' !== $label) {
            $parts[] = $label;
        }

        echo self::escape(implode(' ', $parts));
    }

    /**
     * 虚拟字段：$comments->ipLocation。
     *
     * @param Comments $comments 评论对象
     * @return Location
     */
    public static function fieldLocation($comments)
    {
        return Resolver::resolve((string) ($comments->ip ?? ''));
    }

    /**
     * 虚拟字段：$comments->ipLocationLabel。
     *
     * @param Comments $comments 评论对象
     * @return string
     */
    public static function fieldLocationLabel($comments)
    {
        return Resolver::label(Resolver::resolve((string) ($comments->ip ?? '')));
    }

    // ── 对外 API ──

    /**
     * 前台渲染属地标签。
     *
     * 主题用法：IPLocation_Plugin::render($comments->ip);
     *
     * @param string $ip 评论 IP
     * @param string $class 附加的 CSS 类名（会被转义）
     * @return void
     */
    public static function render($ip, string $class = '')
    {
        $config = Config::all();

        if (empty($config['showFront'])) {
            return;
        }

        $location = Resolver::resolve((string) $ip);
        $label = Resolver::label($location);

        if ('' === $label) {
            return;
        }

        $attributes = '';

        if ('masked' === $config['privacy']) {
            $masked = Ip::mask((string) $location->ip);

            if ('' !== $masked) {
                $attributes .= ' title="' . self::escape($masked) . '"';
            }
        }

        if ('' !== $class) {
            $attributes .= ' class="ip-location ' . self::escape($class) . '"';
        } else {
            $attributes .= ' class="ip-location"';
        }

        echo '<span' . $attributes . '>' . self::escape($label) . '</span>';
    }

    /**
     * 解析地址，返回结构化结果。
     *
     * @param string $ip 地址
     * @return Location
     */
    public static function resolve(string $ip): Location
    {
        return Resolver::resolve($ip);
    }

    /**
     * 解析地址并直接返回展示文案。
     *
     * @param string $ip 地址
     * @return string
     */
    public static function label(string $ip): string
    {
        return Resolver::label(Resolver::resolve($ip));
    }

    /**
     * 展示用脱敏地址。
     *
     * @param string $ip 地址
     * @return string
     */
    public static function mask(string $ip): string
    {
        return Ip::mask($ip);
    }

    /**
     * HTML 转义。
     *
     * @param string $value 原值
     * @return string
     */
    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/**
 * 兼容历史主题写法：IPLocation_Plugin::render() 与 XQLocation_Plugin::render()。
 */
if (!class_exists('IPLocation_Plugin', false)) {
    class_alias(Plugin::class, 'IPLocation_Plugin');
}

if (!class_exists('XQLocation_Plugin', false)) {
    class_alias(Plugin::class, 'XQLocation_Plugin');
}
