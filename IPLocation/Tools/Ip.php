<?php

namespace TypechoPlugin\IPLocation;

/**
 * IP 地址工具集。
 *
 * 仅提供无副作用的静态方法：地址校验与归一化、地址类型判定、展示脱敏、
 * CIDR 匹配，以及在反向代理（CDN / Nginx）环境下安全地还原客户端真实 IP。
 *
 * 归一化说明：::ffff:1.2.3.4 这类 IPv4 映射地址会折叠为 1.2.3.4，
 * 以便与 IPv4 规则库、IPv4 可信代理列表统一比较。
 */
final class Ip
{
    /** 地址类型：公网地址。 */
    public const KIND_PUBLIC = 'public';

    /** 地址类型：私有地址（RFC 1918 / ULA 等）。 */
    public const KIND_PRIVATE = 'private';

    /** 地址类型：保留地址（含测试网段、文档网段）。 */
    public const KIND_RESERVED = 'reserved';

    /** 地址类型：环回地址。 */
    public const KIND_LOOPBACK = 'loopback';

    /** 地址类型：链路本地地址。 */
    public const KIND_LINK_LOCAL = 'linklocal';

    /** 地址类型：组播地址。 */
    public const KIND_MULTICAST = 'multicast';

    /** 地址类型：未指定地址。 */
    public const KIND_UNSPECIFIED = 'unspecified';

    /** 地址类型：非法输入。 */
    public const KIND_INVALID = 'invalid';

    /**
     * 不允许实例化。
     */
    private function __construct()
    {
    }

    /**
     * 归一化 IP 地址，非法输入返回 null。
     *
     * 支持去除方括号包裹（如 [::1]）与 IPv4 映射/兼容前缀。
     *
     * @param string $ip 原始输入
     * @return string|null 规范化后的地址，非法返回 null
     */
    public static function normalize(string $ip): ?string
    {
        $ip = trim($ip);

        if ($ip === '') {
            return null;
        }

        /** 去掉 IPv6 的方括号包裹 */
        if ($ip[0] === '[') {
            $pos = strpos($ip, ']');
            $ip = false === $pos ? substr($ip, 1) : substr($ip, 1, $pos - 1);
        }

        /** 去掉可能的端口后缀（仅对不带方括号的 IPv4 场景安全） */
        if (substr_count($ip, ':') === 1 && strpos($ip, '.') !== false) {
            $ip = substr($ip, 0, strpos($ip, ':'));
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        $packed = inet_pton($ip);

        if (false === $packed) {
            return null;
        }

        if (strlen($packed) !== 16) {
            return inet_ntop($packed) ?: null;
        }

        /** 前 10 字节全零时，可能是 IPv4 映射（::ffff:x）或 IPv4 兼容（::x）地址 */
        if (ltrim(substr($packed, 0, 10), "\x00") !== '') {
            return inet_ntop($packed) ?: null;
        }

        $bridge = substr($packed, 10, 2);
        $tail = substr($packed, 12, 4);

        /** ::（未指定）与 ::1（环回）保留为 IPv6 语义 */
        if ($bridge === "\x00\x00" && (ltrim($tail, "\x00") === '' || $tail === "\x00\x00\x00\x01")) {
            return inet_ntop($packed) ?: null;
        }

        if ($bridge === "\xff\xff" || $bridge === "\x00\x00") {
            return inet_ntop($tail) ?: null;
        }

        return inet_ntop($packed) ?: null;
    }

    /**
     * 判断地址族，返回 4、6 或 0（非法）。
     *
     * @param string $ip 已归一化或原始的地址
     * @return int
     */
    public static function version(string $ip): int
    {
        $normalized = self::normalize($ip);

        if (null === $normalized) {
            return 0;
        }

        return strpos($normalized, ':') === false ? 4 : 6;
    }

    /**
     * 判定地址类型。
     *
     * @param string $ip 原始地址
     * @return string 见 KIND_* 常量
     */
    public static function kind(string $ip): string
    {
        $normalized = self::normalize($ip);

        if (null === $normalized) {
            return self::KIND_INVALID;
        }

        if (self::version($normalized) === 4) {
            return self::kindV4($normalized);
        }

        return self::kindV6($normalized);
    }

    /**
     * 判定 IPv4 地址类型。
     *
     * @param string $ip 已归一化的 IPv4
     * @return string
     */
    private static function kindV4(string $ip): string
    {
        $parts = array_map('intval', explode('.', $ip));
        [$a, $b] = [$parts[0], $parts[1]];

        if (0 === $a) {
            return self::KIND_UNSPECIFIED;
        }

        if (127 === $a) {
            return self::KIND_LOOPBACK;
        }

        if (169 === $a && 254 === $b) {
            return self::KIND_LINK_LOCAL;
        }

        if ($a >= 224 && $a <= 239) {
            return self::KIND_MULTICAST;
        }

        if ($a >= 240) {
            return self::KIND_RESERVED;
        }

        $isPrivate = (10 === $a)
            || (172 === $a && $b >= 16 && $b <= 31)
            || (192 === $a && 168 === $b)
            || (100 === $a && $b >= 64 && $b <= 127);

        if ($isPrivate) {
            return self::KIND_PRIVATE;
        }

        if (192 === $a && ((0 === $b && in_array($parts[2], [0, 2], true)) || (198 === $b && 51 === $parts[2]) || (203 === $b && 113 === $parts[2]))) {
            return self::KIND_RESERVED;
        }

        return self::KIND_PUBLIC;
    }

    /**
     * 判定 IPv6 地址类型。
     *
     * @param string $ip 已归一化的 IPv6
     * @return string
     */
    private static function kindV6(string $ip): string
    {
        $lower = strtolower($ip);

        if ('::' === $lower) {
            return self::KIND_UNSPECIFIED;
        }

        if ('::1' === $lower) {
            return self::KIND_LOOPBACK;
        }

        $head = substr($lower, 0, 2);

        if ('ff' === $head) {
            return self::KIND_MULTICAST;
        }

        $firstGroup = strtolower(explode(':', $lower)[0]);

        if (strncmp($firstGroup, 'fe8', 3) === 0 || strncmp($firstGroup, 'fe9', 3) === 0
            || strncmp($firstGroup, 'fea', 3) === 0 || strncmp($firstGroup, 'feb', 3) === 0) {
            return self::KIND_LINK_LOCAL;
        }

        if (strncmp($firstGroup, 'fc', 2) === 0 || strncmp($firstGroup, 'fd', 2) === 0) {
            return self::KIND_PRIVATE;
        }

        /** 2001:db8::/32 文档保留、64:ff9b::/96 NAT64 等 */
        if (strncmp($lower, '2001:db8:', 9) === 0 || strncmp($lower, '64:ff9b:', 8) === 0) {
            return self::KIND_RESERVED;
        }

        return self::KIND_PUBLIC;
    }

    /**
     * 地址类型的展示文案。
     *
     * @param string $kind 见 KIND_* 常量
     * @return string
     */
    public static function kindLabel(string $kind): string
    {
        $map = [
            self::KIND_PUBLIC      => '公网',
            self::KIND_PRIVATE     => '局域网',
            self::KIND_RESERVED    => '保留地址',
            self::KIND_LOOPBACK    => '本机',
            self::KIND_LINK_LOCAL  => '链路本地',
            self::KIND_MULTICAST   => '组播地址',
            self::KIND_UNSPECIFIED => '未指定地址',
            self::KIND_INVALID     => '无效地址',
        ];

        return $map[$kind] ?? '未知';
    }

    /**
     * 是否为可查询归属地库的公网地址。
     *
     * @param string $ip 原始地址
     * @return bool
     */
    public static function isPublic(string $ip): bool
    {
        return self::kind($ip) === self::KIND_PUBLIC;
    }

    /**
     * 展示用脱敏：IPv4 隐藏最后一段，IPv6 只保留前两段。
     *
     * @param string $ip 原始地址
     * @return string
     */
    public static function mask(string $ip): string
    {
        $normalized = self::normalize($ip);

        if (null === $normalized) {
            return '';
        }

        if (self::version($normalized) === 4) {
            $parts = explode('.', $normalized);

            return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.*';
        }

        $groups = explode(':', $normalized);

        return $groups[0] . ':' . ($groups[1] ?? '') . ':*:*';
    }

    /**
     * 入库匿名化：IPv4 归零最后一段（/24），IPv6 只保留前 3 段（/48）。
     *
     * 与 mask() 的区别是结果仍为合法地址，可直接写入数据库。
     *
     * @param string $ip 原始地址
     * @return string 匿名化地址，非法输入返回空串
     */
    public static function anonymize(string $ip): string
    {
        $normalized = self::normalize($ip);

        if (null === $normalized) {
            return '';
        }

        if (self::version($normalized) === 4) {
            $parts = explode('.', $normalized);
            $parts[3] = '0';

            return implode('.', $parts);
        }

        $packed = inet_pton($normalized);
        $packed = substr($packed, 0, 6) . str_repeat("\x00", 10);

        return (string) inet_ntop($packed);
    }

    /**
     * 判断 IP 是否落在给定 CIDR（或单个地址）内。
     *
     * @param string $ip 已归一化或原始地址
     * @param string $cidr 形如 10.0.0.0/8、2001:db8::/32 或单个地址
     * @return bool
     */
    public static function cidrMatch(string $ip, string $cidr): bool
    {
        $address = self::normalize($ip);
        $cidr = trim($cidr);

        if (null === $address || '' === $cidr) {
            return false;
        }

        if (strpos($cidr, '/') === false) {
            return $address === self::normalize($cidr);
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        $subnetAddress = self::normalize($subnet);

        if (null === $subnetAddress || !preg_match('/^\d{1,3}$/', trim($bits))) {
            return false;
        }

        $bits = (int) trim($bits);
        $addressBytes = inet_pton($address);
        $subnetBytes = inet_pton($subnetAddress);

        if (false === $addressBytes || false === $subnetBytes
            || strlen($addressBytes) !== strlen($subnetBytes)) {
            return false;
        }

        $maxBits = strlen($addressBytes) * 8;

        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $wholeBytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($wholeBytes > 0 && strncmp($addressBytes, $subnetBytes, $wholeBytes) !== 0) {
            return false;
        }

        if (0 === $remainder) {
            return true;
        }

        $mask = chr((0xFF << (8 - $remainder)) & 0xFF);

        return ($addressBytes[$wholeBytes] & $mask) === ($subnetBytes[$wholeBytes] & $mask);
    }

    /**
     * 判断 IP 是否命中 CIDR 列表中的任意一项。
     *
     * @param string $ip 地址
     * @param array $cidrs CIDR 列表
     * @return bool
     */
    public static function inCidrList(string $ip, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if (self::cidrMatch($ip, (string) $cidr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 解析 RFC 7239 Forwarded 头中的 for= 段。
     *
     * @param string|null $value 头部原文
     * @return array 从左到右的客户端地址列表
     */
    public static function parseForwardedFor(?string $value): array
    {
        if (null === $value || '' === trim($value)) {
            return [];
        }

        $result = [];

        foreach (explode(',', $value) as $element) {
            foreach (explode(';', $element) as $pair) {
                $pair = trim($pair);

                if (stripos($pair, 'for=') !== 0) {
                    continue;
                }

                $host = trim(substr($pair, 4), "\"' ");

                if ('' === $host) {
                    continue;
                }

                /** [2001:db8::1]:8080 形式：取方括号内地址 */
                if ('[' === $host[0]) {
                    $end = strpos($host, ']');
                    $host = false === $end ? substr($host, 1) : substr($host, 1, $end - 1);
                } elseif (substr_count($host, ':') === 1) {
                    /** 1.2.3.4:8080 形式：去掉端口 */
                    $host = substr($host, 0, strpos($host, ':'));
                } elseif (substr_count($host, ':') > 1 && null === self::normalize($host)) {
                    /** 无方括号的 IPv6 带端口：去掉最后一段 */
                    $host = substr($host, 0, (int) strrpos($host, ':'));
                }

                $normalized = self::normalize($host);

                if (null !== $normalized) {
                    $result[] = $normalized;
                }
            }
        }

        return $result;
    }

    /**
     * 在反向代理环境下还原客户端真实 IP。
     *
     * 策略：仅当直连地址（REMOTE_ADDR）位于可信代理列表内时，才采信转发头；
     * 随后从右往左跳过可信代理，取第一个不可信地址作为客户端 IP。
     * 可信代理列表为空时，一律使用直连地址，杜绝 X-Forwarded-For 伪造。
     *
     * @param array $headers 转发头取值，键为小写头名（x-forwarded-for / forwarded / x-real-ip / cf-connecting-ip）
     * @param string $remoteAddr 直连地址
     * @param array $trustedProxies 可信代理 CIDR 列表
     * @return string 还原后的地址，无法判定时返回空串
     */
    public static function clientIp(array $headers, string $remoteAddr, array $trustedProxies): string
    {
        $remote = self::normalize($remoteAddr) ?? '';

        if (empty($trustedProxies)) {
            return $remote;
        }

        if ('' === $remote || !self::inCidrList($remote, $trustedProxies)) {
            return $remote;
        }

        $chain = self::buildForwardChain($headers);
        $chain[] = $remote;

        /** 从右往左跳过可信代理，取第一个不可信地址 */
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            $candidate = self::normalize((string) $chain[$i]);

            if (null === $candidate) {
                continue;
            }

            if (!self::inCidrList($candidate, $trustedProxies)) {
                return $candidate;
            }
        }

        /** 全部链路均为可信代理时，退回链路最左侧的声明值 */
        foreach ($chain as $candidate) {
            $normalized = self::normalize((string) $candidate);

            if (null !== $normalized) {
                return $normalized;
            }
        }

        return $remote;
    }

    /**
     * 构造从左到右的转发链路。
     *
     * @param array $headers 转发头取值
     * @return array
     */
    private static function buildForwardChain(array $headers): array
    {
        $chain = [];

        foreach (['x-forwarded-for', 'forwarded'] as $key) {
            $value = $headers[$key] ?? '';

            if ('' === trim((string) $value)) {
                continue;
            }

            $items = 'forwarded' === $key
                ? self::parseForwardedFor((string) $value)
                : explode(',', (string) $value);

            foreach ($items as $item) {
                $normalized = self::normalize(trim((string) $item));

                if (null !== $normalized) {
                    $chain[] = $normalized;
                }
            }

            if (!empty($chain)) {
                return $chain;
            }
        }

        /** 单值头作为兜底 */
        foreach (['cf-connecting-ip', 'x-real-ip', 'x-client-ip', 'client-ip'] as $key) {
            $normalized = self::normalize(trim((string) ($headers[$key] ?? '')));

            if (null !== $normalized) {
                return [$normalized];
            }
        }

        return $chain;
    }
}
