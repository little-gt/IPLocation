<?php

namespace TypechoPlugin\IPLocation\Engine;

use ip2region\xdb\IPv4;
use ip2region\xdb\IPv6;
use ip2region\xdb\Searcher;
use ip2region\xdb\Util;
use TypechoPlugin\IPLocation\Ip;
use TypechoPlugin\IPLocation\Location;

/**
 * ip2region 离线库引擎（v2.x / v3.x xdb 结构）。
 *
 * 使用官方最新 PHP 实现：向量索引常驻内存 + 文件句柄按需读取，
 * 单次查询只需 2~3 次随机 I/O，适合评论列表这类逐条解析的场景。
 */
final class Ip2RegionEngine implements EngineInterface
{
    /**
     * IPv4 库路径。
     *
     * @var string
     */
    private string $fileV4;

    /**
     * IPv6 库路径。
     *
     * @var string
     */
    private string $fileV6;

    /**
     * 向量索引缓存（按库路径）。
     *
     * @var array
     */
    private static array $vectorIndex = [];

    /**
     * @param string $fileV4 IPv4 库路径
     * @param string $fileV6 IPv6 库路径
     */
    public function __construct(string $fileV4, string $fileV6)
    {
        $this->fileV4 = $fileV4;
        $this->fileV6 = $fileV6;
    }

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return 'ip2region';
    }

    /**
     * @inheritDoc
     */
    public function databaseFile(): string
    {
        return $this->fileV4;
    }

    /**
     * @inheritDoc
     */
    public function isAvailable(): bool
    {
        return ('' !== $this->fileV4 && is_readable($this->fileV4))
            || ('' !== $this->fileV6 && is_readable($this->fileV6));
    }

    /**
     * @inheritDoc
     */
    public function status(): array
    {
        return [
            'name'         => 'ip2region（离线 xdb）',
            'available'    => $this->isAvailable(),
            'files'        => [
                'IPv4' => $this->describeFile($this->fileV4),
                'IPv6' => $this->describeFile($this->fileV6),
            ],
            'databaseType' => 'ip2region xdb',
            'buildDate'    => null,
            'note'         => '中文属地数据，国内精度到省市与运营商',
        ];
    }

    /**
     * @inheritDoc
     */
    public function lookup(string $ip, string $language): ?Location
    {
        $location = new Location();
        $location->ip = $ip;
        $location->version = Ip::version($ip);
        $location->kind = Ip::kind($ip);
        $location->engine = $this->name();

        $ipv6 = 6 === Ip::version($ip);
        $file = $ipv6 ? $this->fileV6 : $this->fileV4;

        if ('' === $file || !is_readable($file)) {
            /** v4 / v6 库互不通用，缺失时交由上层回退其它引擎 */
            $location->error = 'ip2region ' . ($ipv6 ? 'IPv6' : 'IPv4') . ' 库不可用';
            return null;
        }

        $searcher = null;

        try {
            $version = Ip::version($ip) === 6 ? IPv6::default() : IPv4::default();
            $searcher = Searcher::newWithVectorIndex($version, $file, $this->vectorIndex($file));
            $raw = (string) $searcher->search($ip);

            $location->raw = ['xdb' => $raw, 'file' => basename($file)];

            if ('' === trim($raw)) {
                return null;
            }

            $this->fill($location, $raw);

            return $location->isKnown() ? $location : null;
        } finally {
            if (null !== $searcher) {
                $searcher->close();
            }
        }
    }

    /**
     * 解析 ip2region 的字段串。
     *
     * 现行数据格式（5 段）：国家|省州|城市|运营商|国家代码，
     * 例：中国|浙江省|杭州市|阿里|CN、United States|California|0|Google LLC|US。
     * 早期数据可能只有 3~4 段，这里按段数自适应，缺失段留空。
     *
     * @param Location $location 结果对象
     * @param string $raw 原始字段串
     * @return void
     */
    private function fill(Location $location, string $raw): void
    {
        $parts = array_map('trim', explode('|', $raw));
        $parts = array_map([$this, 'clean'], $parts);
        $count = count($parts);

        if ($count >= 5) {
            [$country, $region, $city, $isp, $code] = [$parts[0], $parts[1], $parts[2], $parts[3], $parts[4]];
        } elseif (4 === $count) {
            [$country, $region, $city, $last] = [$parts[0], $parts[1], $parts[2], $parts[3]];
            /** 末段形如两位字母时视为国家代码，否则视为运营商 */
            $code = preg_match('/^[A-Za-z]{2}$/', $last) ? strtoupper($last) : '';
            $isp = '' === $code ? $last : '';
        } elseif (3 === $count) {
            [$country, $region, $city, $isp, $code] = [$parts[0], $parts[1], $parts[2], '', ''];
        } else {
            [$country, $region, $city, $isp, $code] = [$parts[0] ?? '', '', '', '', ''];
        }

        $location->country = '' === $country ? null : $country;
        $location->region = '' === $region ? null : $region;
        $location->city = '' === $city ? null : $city;
        $location->isp = '' === $isp ? null : $isp;
        $location->countryCode = preg_match('/^[A-Za-z]{2}$/', $code) ? strtoupper($code) : null;
        $location->found = true;

        if (null === $location->countryCode) {
            $location->countryCode = $this->guessCountryCode($location->country);
        }
    }

    /**
     * 归一化单个字段：0、空串、「未知 / Reserved / 保留」一律视为无数据。
     *
     * ip2region 对文档段、保留段会返回 Reserved，这类值不含地理位置，
     * 置空后由上层继续查询 GeoLite2，避免把非地理信息当成归属地。
     *
     * @param string $value 字段值
     * @return string
     */
    private function clean(string $value): string
    {
        $value = trim($value);

        if (in_array($value, ['', '0', '未知', 'unknown', 'Reserved', 'reserved', '保留', '保留地址', 'NULL', 'null'], true)) {
            return '';
        }

        return $value;
    }

    /**
     * 由中文国家名推断 ISO 代码（仅覆盖高频国家，够用于文案分级）。
     *
     * @param string $country 国家名
     * @return string|null
     */
    private function guessCountryCode(?string $country): ?string
    {
        $map = [
            '中国'   => 'CN',
            '香港'   => 'HK',
            '澳门'   => 'MO',
            '台湾'   => 'TW',
            '美国'   => 'US',
            '日本'   => 'JP',
            '韩国'   => 'KR',
            '英国'   => 'GB',
            '德国'   => 'DE',
            '法国'   => 'FR',
            '俄罗斯' => 'RU',
            '新加坡' => 'SG',
            '加拿大' => 'CA',
            '澳大利亚' => 'AU',
            '荷兰'   => 'NL',
            '印度'   => 'IN',
        ];

        if (null === $country) {
            return null;
        }

        return $map[$country] ?? null;
    }

    /**
     * 读取（并缓存）向量索引。
     *
     * @param string $file 库文件
     * @return string|null 索引二进制串，失败返回 null（退化为纯文件查询）
     */
    private function vectorIndex(string $file): ?string
    {
        if (array_key_exists($file, self::$vectorIndex)) {
            return self::$vectorIndex[$file];
        }

        try {
            $index = Util::loadVectorIndexFromFile($file);
        } catch (\Throwable $e) {
            $index = null;
        }

        return self::$vectorIndex[$file] = $index;
    }

    /**
     * 描述库文件状态。
     *
     * @param string $file 文件路径
     * @return array
     */
    private function describeFile(string $file): array
    {
        $exists = '' !== $file && is_file($file);

        return [
            'path'     => $file,
            'exists'   => $exists,
            'readable' => $exists && is_readable($file),
            'size'     => $exists ? (int) @filesize($file) : 0,
            'modified' => $exists ? (int) @filemtime($file) : 0,
        ];
    }
}
