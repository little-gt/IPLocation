<?php

namespace TypechoPlugin\IPLocation\Engine;

use TypechoPlugin\IPLocation\Location;

/**
 * 归属地查询引擎接口。
 *
 * 引擎只负责「一个地址 → 一条归属地记录」的本地查询，
 * 缓存、调度、文案生成与异常处理统一由 Resolver 负责。
 */
interface EngineInterface
{
    /**
     * 引擎标识，如 ip2region / geoip2。
     *
     * @return string
     */
    public function name(): string;

    /**
     * 当前使用的库文件绝对路径。
     *
     * @return string
     */
    public function databaseFile(): string;

    /**
     * 库文件是否可用（存在且可读）。
     *
     * @return bool
     */
    public function isAvailable(): bool;

    /**
     * 引擎状态，供后台自检面板展示。
     *
     * @return array 至少包含 available / file / size / modified / databaseType / buildDate
     */
    public function status(): array;

    /**
     * 查询单个地址。
     *
     * @param string $ip 已归一化的地址
     * @param string $language 展示语言（如 zh-CN）
     * @return Location|null 未命中返回 null
     * @throws \Throwable 库损坏或读取失败时抛出，由上层降级处理
     */
    public function lookup(string $ip, string $language): ?Location;
}
