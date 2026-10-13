<?php

namespace TypechoPlugin\IPLocation;

use TypechoPlugin\IPLocation\Engine\EngineInterface;
use TypechoPlugin\IPLocation\Engine\GeoLite2Engine;
use TypechoPlugin\IPLocation\Engine\Ip2RegionEngine;

/** 确保 MaxMind / ip2region SDK 的按需加载已注册（后台面板上下文不会加载 Plugin.php） */
require_once __DIR__ . '/Bootstrap.php';

/**
 * 归属地解析调度器。
 *
 * 负责：地址预检（非公网地址直接短路）、引擎选择与回退、两级缓存（内存 + 本地文件）、
 * 异常降级（库损坏时不影响页面渲染）。所有对外入口都在此收敛。
 */
final class Resolver
{
    /**
     * 单次请求内的结果缓存。
     *
     * @var array
     */
    private static array $memory = [];

    /**
     * 不允许实例化。
     */
    private function __construct()
    {
    }

    /**
     * 解析单个地址。
     *
     * @param string $ip 任意输入（会先归一化）
     * @param bool $withRaw 是否保留引擎原始数据（仅后台自检使用，且跳过缓存）
     * @return Location
     */
    public static function resolve(string $ip, bool $withRaw = false): Location
    {
        $config = Config::all();
        $normalized = Ip::normalize($ip);

        $location = new Location();

        if (null === $normalized) {
            $location->kind = Ip::KIND_INVALID;
            $location->error = '地址格式非法';
            return $location;
        }

        $location->ip = $normalized;
        $location->version = Ip::version($normalized);
        $location->kind = Ip::kind($normalized);

        /** 内网、保留、环回等地址不进库查询，直接给出类型文案 */
        if (Ip::KIND_PUBLIC !== $location->kind) {
            return $location;
        }

        $cacheKey = self::cacheKey($normalized, $config);

        if (!$withRaw && isset(self::$memory[$cacheKey])) {
            return self::$memory[$cacheKey];
        }

        if (!$withRaw && !empty($config['cache'])) {
            $cached = Cache::get($cacheKey);

            if (null !== $cached) {
                $cached = Location::fromArray($cached);

                return self::$memory[$cacheKey] = $cached;
            }
        }

        $result = self::query($normalized, $config, $withRaw);

        if (!$withRaw && !empty($config['cache'])) {
            Cache::set($cacheKey, $result->toArray(), (int) $config['cacheTtl'] * 86400);
        }

        return self::$memory[$cacheKey] = $result;
    }

    /**
     * 批量解析（用于后台统计，自动去重并复用缓存）。
     *
     * @param array $ips 地址列表
     * @return array 键为归一化地址，值为 Location
     */
    public static function resolveMany(array $ips): array
    {
        $result = [];

        foreach ($ips as $ip) {
            $normalized = Ip::normalize((string) $ip);

            if (null === $normalized || isset($result[$normalized])) {
                continue;
            }

            $result[$normalized] = self::resolve($normalized);
        }

        return $result;
    }

    /**
     * 生成展示文案。
     *
     * @param Location $location 结果对象
     * @return string
     */
    public static function label(Location $location): string
    {
        return $location->label(Config::all());
    }

    /**
     * 当前配置下参与解析的引擎列表。
     *
     * @return array 引擎对象数组
     */
    public static function engines(): array
    {
        $config = Config::all();
        $files = Config::databaseFiles();
        $engines = [];

        if (Config::SOURCE_GEOIP2 !== $config['dataSource']) {
            $engines[] = new Ip2RegionEngine($files['ip2region_v4'], $files['ip2region_v6']);
        }

        if (Config::SOURCE_IP2REGION !== $config['dataSource']) {
            $engines[] = new GeoLite2Engine(Config::databaseFile(Config::geoipDatabaseKey()));
        }

        return $engines;
    }

    /**
     * 全部引擎状态（后台自检面板使用）。
     *
     * @return array
     */
    public static function status(): array
    {
        $files = Config::databaseFiles();
        $list = [
            new Ip2RegionEngine($files['ip2region_v4'], $files['ip2region_v6']),
        ];

        foreach (['geoip2_city', 'geoip2_country', 'geoip2_asn'] as $key) {
            $list[] = new GeoLite2Engine($files[$key]);
        }

        $status = [];

        foreach ($list as $engine) {
            $status[] = $engine->status();
        }

        return $status;
    }

    /**
     * 按引擎顺序查询，并把结果尽可能补全。
     *
     * 行为：
     * 1. 数据源选单引擎时，命中即返回、未命中即返回空；
     * 2. 自动数据源下，ip2region 未命中（含「未知 / 0」）继续查 GeoLite2；
     * 3. ip2region 已命中但字段不全时，用 GeoLite2 补全国籍代码、经纬度、
     *    时区、ASN 等细节（enrich 开关），engine 记为 ip2region+geoip2。
     *
     * @param string $ip 已归一化地址
     * @param array $config 插件配置
     * @param bool $withRaw 是否保留原始数据
     * @return Location
     */
    private static function query(string $ip, array $config, bool $withRaw): Location
    {
        $empty = new Location();
        $empty->ip = $ip;
        $empty->version = Ip::version($ip);
        $empty->kind = Ip::kind($ip);

        $allowFallback = Config::SOURCE_AUTO === $config['dataSource'] && !empty($config['fallback']);
        $allowEnrich = $allowFallback && !empty($config['enrich']);

        $primary = null;
        $extra = null;
        $extraName = '';

        foreach (self::engines() as $engine) {
            if (!$engine->isAvailable()) {
                continue;
            }

            try {
                $location = $engine->lookup($ip, (string) $config['language']);
            } catch (\Throwable $e) {
                $empty->error = $engine->name() . '：' . $e->getMessage();
                continue;
            }

            if (null === $location) {
                /** 未命中（含「未知」）：不回退时直接结束 */
                if (!$allowFallback) {
                    return $empty;
                }

                continue;
            }

            if (null === $primary) {
                $primary = $location;

                if (!$allowFallback) {
                    break;
                }

                continue;
            }

            /** 第二个引擎命中：用于补全细节，取到即止 */
            $extra = $location;
            $extraName = $engine->name();
            break;
        }

        if (null === $primary) {
            return $empty;
        }

        $result = $primary;

        if (null !== $extra && $allowEnrich) {
            $result->mergeFrom($extra);
            $result->engine = $primary->engine . '+' . $extraName;
            $result->raw = $withRaw
                ? [(string) $primary->engine => $primary->raw, $extraName => $extra->raw]
                : [];
        } elseif (null !== $extra && !$result->isKnown()) {
            /** 未开启补全且主引擎结果为空：直接采用第二引擎 */
            $result = $extra;
        }

        if (!$withRaw) {
            $result->raw = [];
        }

        return $result;
    }

    /**
     * 缓存键：地址 + 引擎组合 + 库文件与语言。
     *
     * 自动 + 回退模式下结果可能来自两个引擎，键中同时体现两侧库文件，
     * 避免只升级一侧库后命中旧缓存。
     *
     * @param string $ip 已归一化地址
     * @param array $config 插件配置
     * @return string
     */
    private static function cacheKey(string $ip, array $config): string
    {
        $files = Config::databaseFiles();
        $mix = Config::SOURCE_AUTO === $config['dataSource'] && !empty($config['fallback']);

        $database = $mix
            ? 'mix:' . basename($files['ip2region_v4']) . '|' . basename($files['ip2region_v6'])
                . '|' . basename(Config::databaseFile(Config::geoipDatabaseKey()))
            : (Config::SOURCE_IP2REGION === $config['dataSource']
                ? 'ip2region:' . basename($files['ip2region_v4']) . ':' . basename($files['ip2region_v6'])
                : Config::databaseFile(Config::geoipDatabaseKey()));

        return Cache::key(
            $ip,
            $config['dataSource'] . ':' . ($config['fallback'] ? '1' : '0') . ':' . ($config['enrich'] ? '1' : '0'),
            (string) $database,
            (string) $config['language']
        );
    }
}
