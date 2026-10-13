<?php

namespace TypechoPlugin\IPLocation;

/**
 * 归属地查询结果值对象。
 *
 * 只承载数据，不做任何 I/O；展示文案由 label() 按插件配置生成。
 */
class Location
{
    /** 查询地址（已归一化）。 */
    public string $ip = '';

    /** 地址族：4 或 6，非法为 0。 */
    public int $version = 0;

    /** 地址类型，见 Ip::KIND_*。 */
    public string $kind = 'unknown';

    /** 是否在归属地库中命中。 */
    public bool $found = false;

    /** 命中的引擎名（ip2region / geoip2），未命中为 null。 */
    public ?string $engine = null;

    /** 洲。 */
    public ?string $continent = null;

    /** 国家（已按配置语言本地化）。 */
    public ?string $country = null;

    /** 国家 ISO 3166-1 代码。 */
    public ?string $countryCode = null;

    /** 一级行政区（省 / 州）。 */
    public ?string $region = null;

    /** 城市。 */
    public ?string $city = null;

    /** 运营商或组织。 */
    public ?string $isp = null;

    /** ASN 展示形式，如 AS15169。 */
    public ?string $asn = null;

    /** ASN 所属组织。 */
    public ?string $asnOrg = null;

    /** 邮政编码。 */
    public ?string $postal = null;

    /** 纬度。 */
    public ?float $latitude = null;

    /** 经度。 */
    public ?float $longitude = null;

    /** 时区。 */
    public ?string $timeZone = null;

    /** 命中网段（仅 GeoLite2 提供前缀长度时可用）。 */
    public ?string $network = null;

    /** 引擎原始返回，仅用于后台自检，不进缓存。 */
    public array $raw = [];

    /** 引擎内部错误信息，仅管理员可见。 */
    public ?string $error = null;

    /**
     * 从数组还原对象。
     *
     * @param array $data 字段数组
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $location = new self();

        foreach (['ip', 'country', 'countryCode', 'region', 'city', 'isp', 'asn', 'asnOrg',
                     'postal', 'timeZone', 'network', 'continent', 'engine', 'error', 'kind'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                $location->{$key} = (string) $data[$key];
            }
        }

        $location->version = isset($data['version']) ? (int) $data['version'] : 0;
        $location->found = !empty($data['found']);

        foreach (['latitude', 'longitude'] as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                $location->{$key} = (float) $data[$key];
            }
        }

        return $location;
    }

    /**
     * 序列化为数组（不含 raw 与 error，便于缓存）。
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'ip'          => $this->ip,
            'version'     => $this->version,
            'kind'        => $this->kind,
            'found'       => $this->found,
            'engine'      => $this->engine,
            'continent'   => $this->continent,
            'country'     => $this->country,
            'countryCode' => $this->countryCode,
            'region'      => $this->region,
            'city'        => $this->city,
            'isp'         => $this->isp,
            'asn'         => $this->asn,
            'asnOrg'      => $this->asnOrg,
            'postal'      => $this->postal,
            'latitude'    => $this->latitude,
            'longitude'   => $this->longitude,
            'timeZone'    => $this->timeZone,
            'network'     => $this->network,
        ];
    }

    /**
     * 是否拿到可用的归属地信息。
     *
     * @return bool
     */
    public function isKnown(): bool
    {
        return $this->found
            && (null !== $this->country || null !== $this->region || null !== $this->city || null !== $this->isp);
    }

    /**
     * 用另一引擎的结果补全缺失字段（已有值优先，不动 ip/version/kind）。
     *
     * 用于「ip2region 命中但字段不全」时叠加 GeoLite2 的经纬度、时区、
     * ASN、邮编等细节，让最终结果尽可能完整。
     *
     * @param Location $other 另一引擎的查询结果
     * @return void
     */
    public function mergeFrom(Location $other): void
    {
        $fields = [
            'continent', 'country', 'countryCode', 'region', 'city',
            'isp', 'asn', 'asnOrg', 'postal', 'timeZone', 'network',
        ];

        foreach ($fields as $field) {
            if (null === $this->{$field} && null !== $other->{$field} && '' !== (string) $other->{$field}) {
                $this->{$field} = $other->{$field};
            }
        }

        foreach (['latitude', 'longitude'] as $field) {
            if (null === $this->{$field} && null !== $other->{$field}) {
                $this->{$field} = $other->{$field};
            }
        }

        $this->found = $this->found || $other->found;
    }

    /**
     * 按插件配置生成展示文案（纯文本，不含 HTML）。
     *
     * 模板占位符：%continent% %country% %region% %city% %isp% %asn% %ip%。
     *
     * @param array $config 插件配置
     * @return string
     */
    public function label(array $config): string
    {
        /** 非公网地址直接给出类型文案，不查询也不暴露细节 */
        if (Ip::KIND_PUBLIC !== $this->kind) {
            return Ip::kindLabel($this->kind);
        }

        if (!$this->isKnown()) {
            return (string) ($config['unknownText'] ?? '未知');
        }

        $template = trim((string) ($config['format'] ?? ''));

        if ('' !== $template) {
            return $this->applyTemplate($template, $config);
        }

        return $this->buildSmartLabel($config);
    }

    /**
     * 智能文案：国内精确到省市，国外只到国家级。
     *
     * @param array $config 插件配置
     * @return string
     */
    private function buildSmartLabel(array $config): string
    {
        $country = $this->country;
        $region = $this->region;
        $city = $this->city;

        if (!empty($config['trimSuffix'])) {
            $region = self::trimAdminSuffix((string) $region);
            $city = self::trimCitySuffix((string) $city);
        }

        $isChina = 'CN' === strtoupper((string) $this->countryCode) || '中国' === $country;

        if ($isChina) {
            $parts = [];

            if ('' !== $region) {
                $parts[] = $region;
            }

            if ('' !== $city && $city !== $region) {
                $parts[] = $city;
            }

            if (empty($parts) && '' !== (string) $country) {
                $parts[] = (string) $country;
            }

            return implode(' ', $parts);
        }

        $parts = [];

        if ('' !== (string) $country) {
            $parts[] = (string) $country;
        }

        $detail = (string) ($config['detail'] ?? 'smart');

        if ('region' === $detail || 'city' === $detail || 'isp' === $detail) {
            if ('' !== $region && $region !== $country) {
                $parts[] = $region;
            }
        }

        if ('city' === $detail || 'isp' === $detail) {
            if ('' !== $city && $city !== $region) {
                $parts[] = $city;
            }
        }

        if ('isp' === $detail || !empty($config['showIsp'])) {
            if ('' !== (string) $this->isp) {
                $parts[] = (string) $this->isp;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * 按自定义模板填充占位符。
     *
     * @param string $template 模板
     * @param array $config 插件配置
     * @return string
     */
    private function applyTemplate(string $template, array $config): string
    {
        $region = (string) $this->region;
        $city = (string) $this->city;

        if (!empty($config['trimSuffix'])) {
            $region = self::trimAdminSuffix($region);
            $city = self::trimCitySuffix($city);
        }

        $replace = [
            '%continent%' => (string) $this->continent,
            '%country%'   => (string) $this->country,
            '%region%'    => $region,
            '%city%'      => $city,
            '%isp%'       => (string) $this->isp,
            '%asn%'       => (string) $this->asn,
            '%ip%'        => (string) $this->ip,
        ];

        $result = strtr($template, $replace);

        /** 折叠连续空白与残留分隔符 */
        $result = trim(preg_replace('/\s+/', ' ', $result));
        $result = trim($result, ' -|/,');

        return '' === $result ? (string) ($config['unknownText'] ?? '未知') : $result;
    }

    /**
     * 去掉行政区后缀，如「北京市」→「北京」。
     *
     * @param string $name 行政区名
     * @return string
     */
    public static function trimAdminSuffix(string $name): string
    {
        $name = trim($name);

        if ('' === $name) {
            return '';
        }

        $pattern = '/(省|自治区|特别行政区|自治州|地区|市|盟|州)$/u';
        $trimmed = preg_replace($pattern, '', $name);

        return trim(null === $trimmed ? '' : $trimmed);
    }

    /**
     * 去掉城市后缀，如「深圳市」→「深圳」。
     *
     * @param string $name 城市名
     * @return string
     */
    public static function trimCitySuffix(string $name): string
    {
        $name = trim($name);

        if ('' === $name) {
            return '';
        }

        $trimmed = preg_replace('/(市|县|区)$/u', '', $name);

        return trim(null === $trimmed ? '' : $trimmed);
    }
}
