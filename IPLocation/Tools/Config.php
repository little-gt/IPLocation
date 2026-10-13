<?php

namespace TypechoPlugin\IPLocation;

/**
 * 插件配置读取与校验。
 *
 * 所有外部可配置项都在此集中定义默认值与取值白名单，
 * 运行时读到的值一律经过 sanitize()，避免非法配置影响查询路径或输出。
 */
final class Config
{
    /** 插件目录名，与 Options::plugin() 的键保持一致。 */
    public const NAME = 'IPLocation';

    /** 引擎标识：ip2region 离线库。 */
    public const SOURCE_IP2REGION = 'ip2region';

    /** 引擎标识：MaxMind GeoLite2 mmdb 库。 */
    public const SOURCE_GEOIP2 = 'geoip2';

    /** 引擎标识：自动（先 ip2region，按配置回退 GeoLite2）。 */
    public const SOURCE_AUTO = 'auto';

    /**
     * 默认配置。
     *
     * @var array
     */
    private const DEFAULTS = [
        'dataSource'     => self::SOURCE_AUTO,
        'geoipDatabase'  => 'city',
        'language'       => 'zh-CN',
        'detail'         => 'smart',
        'format'         => '',
        'fallback'       => 1,
        'enrich'         => 1,
        'showFront'      => 1,
        'showAdmin'      => 1,
        'privacy'        => 'label',
        'cache'          => 1,
        'cacheTtl'       => 30,
        'trustedProxies' => '',
        'proxyEnabled'   => 0,
        'proxyCidr'      => 1,
        'proxyHeader'    => 0,
        'proxyHeaderName' => '',
        'anonymousStore' => 0,
        'unknownText'    => '未知',
        'trimSuffix'     => 1,
        'showIsp'        => 0,
        'fileIp2RegionV4'   => '',
        'fileIp2RegionV6'   => '',
        'fileGeoipCity'     => '',
        'fileGeoipCountry'  => '',
        'fileGeoipAsn'      => '',
        'dataDir'           => '',
    ];

    /**
     * 枚举型配置的取值白名单。
     *
     * @var array
     */
    private const ENUMS = [
        'dataSource'    => [self::SOURCE_AUTO, self::SOURCE_IP2REGION, self::SOURCE_GEOIP2],
        'geoipDatabase' => ['city', 'country', 'asn'],
        'language'      => ['zh-CN', 'en', 'ja', 'ru', 'de', 'fr', 'es', 'pt-BR'],
        'detail'        => ['smart', 'country', 'region', 'city', 'isp'],
        'privacy'       => ['label', 'masked'],
    ];

    /**
     * 已解析的配置缓存。
     *
     * @var array|null
     */
    private static ?array $resolved = null;

    /**
     * 不允许实例化。
     */
    private function __construct()
    {
    }

    /**
     * 读取全部配置（已校验）。
     *
     * @return array
     */
    public static function all(): array
    {
        if (null !== self::$resolved) {
            return self::$resolved;
        }

        $stored = [];

        try {
            $pluginConfig = \Widget\Options::alloc()->plugin(self::NAME);
            $stored = $pluginConfig->toArray();
        } catch (\Throwable $e) {
            /** 插件未配置或上下文不可用时，全部回落默认值 */
            $stored = [];
        }

        return self::$resolved = self::sanitize($stored);
    }

    /**
     * 读取单个配置项。
     *
     * @param string $key 配置键
     * @return mixed
     */
    public static function get(string $key)
    {
        $config = self::all();

        return $config[$key] ?? null;
    }

    /**
     * 重置配置缓存（配置更新后调用）。
     *
     * @return void
     */
    public static function flush(): void
    {
        self::$resolved = null;
    }

    /**
     * 校验并补全配置。
     *
     * @param array $input 原始配置
     * @return array
     */
    public static function sanitize(array $input): array
    {
        $result = [];

        foreach (self::DEFAULTS as $key => $default) {
            $value = $input[$key] ?? $default;

            if (isset(self::ENUMS[$key])) {
                $value = in_array((string) $value, self::ENUMS[$key], true) ? (string) $value : $default;
                $result[$key] = $value;
                continue;
            }

            switch ($key) {
                case 'cacheTtl':
                    $result[$key] = max(0, min(3650, (int) $value));
                    break;
                case 'cache':
                case 'fallback':
                case 'enrich':
                case 'showFront':
                case 'showAdmin':
                case 'anonymousStore':
                case 'trimSuffix':
                case 'showIsp':
                case 'proxyCidr':
                case 'proxyHeader':
                    $result[$key] = empty($value) ? 0 : 1;
                    break;
                case 'proxyEnabled':
                    /** 兼容旧版：未显式设置过该开关但配置过可信代理的用户，视为启用 */
                    if (!array_key_exists('proxyEnabled', $input) && '' !== trim((string) ($input['trustedProxies'] ?? ''))) {
                        $result[$key] = 1;
                    } else {
                        $result[$key] = empty($value) ? 0 : 1;
                    }
                    break;
                case 'format':
                    /** 模板只允许占位符与普通文本，长度受限 */
                    $format = trim((string) $value);
                    $result[$key] = self::clip($format, 120);
                    break;
                case 'unknownText':
                    $text = trim((string) $value);
                    $result[$key] = '' === $text ? '未知' : self::clip($text, 30);
                    break;
                case 'trustedProxies':
                    $result[$key] = self::clip(trim((string) $value), 2000);
                    break;
                case 'dataDir':
                case 'proxyHeaderName':
                case 'fileIp2RegionV4':
                case 'fileIp2RegionV6':
                case 'fileGeoipCity':
                case 'fileGeoipCountry':
                case 'fileGeoipAsn':
                    /** 自定义目录名 / 库文件名：仅允许纯名称，非法值回落默认 */
                    $result[$key] = self::safeFileName((string) $value);
                    break;
                default:
                    $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * 解析可信代理列表。
     *
     * 每行一条 CIDR 或单个地址，# 开头为注释；非法条目直接丢弃。
     *
     * @return array
     */
    public static function proxyList(): array
    {
        $config = self::all();
        $list = [];

        foreach (preg_split('/[\r\n,]+/', (string) $config['trustedProxies']) as $line) {
            $line = trim($line);

            if ('' === $line || '#' === $line[0]) {
                continue;
            }

            if (strpos($line, '/') === false) {
                if (null !== Ip::normalize($line)) {
                    $list[] = Ip::normalize($line);
                }

                continue;
            }

            [$subnet, $bits] = explode('/', $line, 2);

            if (null === Ip::normalize($subnet) || !preg_match('/^\d{1,3}$/', trim($bits))) {
                continue;
            }

            $list[] = Ip::normalize($subnet) . '/' . (int) trim($bits);
        }

        return $list;
    }

    /**
     * 按字符数截断（无 mbstring 时退回字节截断）。
     *
     * @param string $value 原值
     * @param int $length 最大长度
     * @return string
     */
    private static function clip(string $value, int $length): string
    {
        return function_exists('mb_substr')
            ? mb_substr($value, 0, $length)
            : substr($value, 0, $length);
    }

    /**
     * 校验自定义目录名 / 库文件名。
     *
     * 仅允许纯名称（字母 / 数字 / 点 / 中划线 / 下划线，且以字母数字开头），
     * 长度不超过 64，不得包含路径分隔符与「..」，从输入层面杜绝路径穿越；
     * 非法或超长一律返回空串，表示回落默认名称。
     *
     * @param string $value 用户输入
     * @return string
     */
    private static function safeFileName(string $value): string
    {
        $value = trim($value);

        if ('' === $value || strlen($value) > 64) {
            return '';
        }

        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $value) || false !== strpos($value, '..')) {
            return '';
        }

        return $value;
    }

    /**
     * 插件根目录。
     *
     * 本文件位于 Tools/ 下，根目录需上退一级。
     *
     * @return string
     */
    public static function baseDir(): string
    {
        return dirname(__DIR__);
    }

    /**
     * 归属地库目录。
     *
     * 支持自定义目录名（安全加固：避免默认 Database 目录名被探测）；
     * 未配置或非法时使用默认名 Database。
     *
     * @return string
     */
    public static function databaseDir(): string
    {
        $config = self::all();
        $custom = trim((string) ($config['dataDir'] ?? ''));

        return dirname(__DIR__) . DIRECTORY_SEPARATOR . ('' !== $custom ? $custom : 'Database');
    }

    /**
     * 缓存目录。
     *
     * @return string
     */
    public static function cacheDir(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cache';
    }

    /**
     * 内置默认库文件名（未自定义时使用）。
     *
     * @var array
     */
    private const DEFAULT_FILES = [
        'ip2region_v4'   => 'ip2region_v4.xdb',
        'ip2region_v6'   => 'ip2region_v6.xdb',
        'geoip2_city'    => 'GeoLite2-City.mmdb',
        'geoip2_country' => 'GeoLite2-Country.mmdb',
        'geoip2_asn'     => 'GeoLite2-ASN.mmdb',
    ];

    /**
     * 库标识到自定义文件名配置键的映射（留空表示使用默认名）。
     *
     * @var array
     */
    private const FILE_KEYS = [
        'ip2region_v4'   => 'fileIp2RegionV4',
        'ip2region_v6'   => 'fileIp2RegionV6',
        'geoip2_city'    => 'fileGeoipCity',
        'geoip2_country' => 'fileGeoipCountry',
        'geoip2_asn'     => 'fileGeoipAsn',
    ];

    /**
     * 可用归属地库文件的绝对路径表。
     *
     * 库目录名与每个库的文件名都支持在设置中自定义（安全加固：避免默认
     * 路径被探测）；自定义值为空或非法时回落默认名。解析结果的缓存键含
     * 目录名与库文件名，改名后旧缓存自动失效，无需手动清空。
     *
     * @return array 键为库标识，值为绝对路径
     */
    public static function databaseFiles(): array
    {
        $dir = self::databaseDir();
        $config = self::all();
        $files = [];

        foreach (self::DEFAULT_FILES as $key => $default) {
            $custom = trim((string) ($config[self::FILE_KEYS[$key]] ?? ''));

            $files[$key] = $dir . DIRECTORY_SEPARATOR . ('' !== $custom ? $custom : $default);
        }

        return $files;
    }

    /**
     * 按标识获取库文件路径。
     *
     * @param string $key 库标识
     * @return string 不存在时返回空串
     */
    public static function databaseFile(string $key): string
    {
        $files = self::databaseFiles();

        return $files[$key] ?? '';
    }

    /**
     * 根据配置得到应选用的 GeoLite2 库标识。
     *
     * @return string
     */
    public static function geoipDatabaseKey(): string
    {
        return 'geoip2_' . (string) self::get('geoipDatabase');
    }
}
