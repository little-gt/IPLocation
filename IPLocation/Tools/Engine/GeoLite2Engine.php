<?php

namespace TypechoPlugin\IPLocation\Engine;

use MaxMind\Db\Reader;
use TypechoPlugin\IPLocation\Ip;
use TypechoPlugin\IPLocation\Location;

/**
 * MaxMind GeoLite2（mmdb）引擎。
 *
 * 使用官方最新 MaxMind\Db\Reader：文件句柄 + 内存映射式随机读取，
 * 支持 IPv4 / IPv6 与多语言名称，可切换 City / Country / ASN 三种库。
 */
final class GeoLite2Engine implements EngineInterface
{
    /**
     * 库文件路径。
     *
     * @var string
     */
    private string $file;

    /**
     * Reader 实例缓存（按库路径，避免重复解析 metadata）。
     *
     * @var array
     */
    private static array $readers = [];

    /**
     * @param string $file mmdb 库路径
     */
    public function __construct(string $file)
    {
        $this->file = $file;
    }

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return 'geoip2';
    }

    /**
     * @inheritDoc
     */
    public function databaseFile(): string
    {
        return $this->file;
    }

    /**
     * @inheritDoc
     */
    public function isAvailable(): bool
    {
        return '' !== $this->file && is_readable($this->file);
    }

    /**
     * @inheritDoc
     */
    public function status(): array
    {
        $exists = '' !== $this->file && is_file($this->file);
        $status = [
            'name'         => 'GeoLite2（MaxMind mmdb）',
            'available'    => $this->isAvailable(),
            'files'        => [
                'mmdb' => [
                    'path'     => $this->file,
                    'exists'   => $exists,
                    'readable' => $exists && is_readable($this->file),
                    'size'     => $exists ? (int) @filesize($this->file) : 0,
                    'modified' => $exists ? (int) @filemtime($this->file) : 0,
                ],
            ],
            'databaseType' => null,
            'buildDate'    => null,
            'ipVersion'    => null,
            'languages'    => [],
            'note'         => '官方每周更新，需定期下载新版 mmdb',
        ];

        if (!$exists) {
            return $status;
        }

        try {
            $metadata = $this->reader()->metadata();
            $status['databaseType'] = $metadata->databaseType;
            $status['ipVersion'] = $metadata->ipVersion;
            $status['languages'] = $metadata->languages;
            $status['buildDate'] = self::formatEpoch($metadata->buildEpoch);
        } catch (\Throwable $e) {
            $status['note'] = '库文件无法解析：' . $e->getMessage();
        }

        return $status;
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

        [$record, $prefixLength] = $this->reader()->getWithPrefixLen($ip);

        if (!is_array($record) || empty($record)) {
            return null;
        }

        $location->raw = $record;
        $location->found = true;

        if ($prefixLength >= 0) {
            $location->network = $ip . '/' . $prefixLength;
        }

        $location->continent = $this->localizedName($record['continent']['names'] ?? null, $language);
        $location->country = $this->localizedName($record['country']['names'] ?? null, $language)
            ?? $this->localizedName($record['registered_country']['names'] ?? null, $language);
        $location->countryCode = strtoupper((string) ($record['country']['iso_code']
            ?? $record['registered_country']['iso_code'] ?? '')) ?: null;

        if (isset($record['subdivisions'][0])) {
            $location->region = $this->localizedName($record['subdivisions'][0]['names'] ?? null, $language);
        }

        $location->city = $this->localizedName($record['city']['names'] ?? null, $language);
        $location->postal = isset($record['postal']['code']) ? (string) $record['postal']['code'] : null;

        if (isset($record['location'])) {
            $location->latitude = isset($record['location']['latitude']) ? (float) $record['location']['latitude'] : null;
            $location->longitude = isset($record['location']['longitude']) ? (float) $record['location']['longitude'] : null;
            $location->timeZone = isset($record['location']['time_zone']) ? (string) $record['location']['time_zone'] : null;
        }

        if (isset($record['autonomous_system_number'])) {
            $location->asn = 'AS' . (int) $record['autonomous_system_number'];
        }

        if (isset($record['autonomous_system_organization'])) {
            $location->asnOrg = (string) $record['autonomous_system_organization'];
            $location->isp = $location->asnOrg;
        }

        return $location->isKnown() ? $location : null;
    }

    /**
     * 获取（并缓存）Reader 实例。
     *
     * @return Reader
     * @throws \Throwable 库文件缺失或损坏时抛出
     */
    private function reader(): Reader
    {
        if (isset(self::$readers[$this->file])) {
            return self::$readers[$this->file];
        }

        if (!$this->isAvailable()) {
            throw new \RuntimeException('GeoLite2 库文件不可用：' . $this->file);
        }

        return self::$readers[$this->file] = new Reader($this->file);
    }

    /**
     * 按语言取本地化名称，缺失时依次回退英语与任意可用语言。
     *
     * @param array|null $names 名称表
     * @param string $language 首选语言
     * @return string|null
     */
    private function localizedName(?array $names, string $language): ?string
    {
        if (null === $names || empty($names)) {
            return null;
        }

        foreach ([$language, 'en'] as $key) {
            if (isset($names[$key]) && '' !== (string) $names[$key]) {
                return (string) $names[$key];
            }
        }

        foreach ($names as $value) {
            if (is_string($value) && '' !== $value) {
                return $value;
            }
        }

        return null;
    }

    /**
     * 格式化 mmdb 构建时间。
     *
     * @param int|string|null $epoch Unix 时间戳
     * @return string|null
     */
    private static function formatEpoch($epoch): ?string
    {
        if (null === $epoch || !is_numeric($epoch)) {
            return null;
        }

        return date('Y-m-d', (int) $epoch);
    }
}
