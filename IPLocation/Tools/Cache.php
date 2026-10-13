<?php

namespace TypechoPlugin\IPLocation;

/**
 * 归属地查询的本地文件缓存。
 *
 * 设计要点：
 * 1. 缓存键必须是 32 位十六进制串（由调用方 md5 生成），杜绝路径穿越。
 * 2. 写入采用「临时文件 + 原子改名 + 排他锁」，避免并发读到半截内容。
 * 3. 值只保存数组，读取时校验结构，损坏或过期一律按未命中处理。
 */
final class Cache
{
    /**
     * 缓存目录。
     *
     * @var string|null
     */
    private static ?string $dir = null;

    /**
     * 不允许实例化。
     */
    private function __construct()
    {
    }

    /**
     * 读取缓存。
     *
     * @param string $key 缓存键（32 位十六进制）
     * @return array|null 命中返回数据数组，否则 null
     */
    public static function get(string $key): ?array
    {
        if (!self::validKey($key)) {
            return null;
        }

        $file = self::file($key);

        if (!is_file($file)) {
            return null;
        }

        $contents = @file_get_contents($file);

        if (false === $contents || '' === $contents) {
            return null;
        }

        $payload = json_decode($contents, true);

        if (!is_array($payload) || !isset($payload['expire'], $payload['data'])
            || !is_array($payload['data'])) {
            @unlink($file);
            return null;
        }

        if ((int) $payload['expire'] < time()) {
            @unlink($file);
            return null;
        }

        return $payload['data'];
    }

    /**
     * 写入缓存。
     *
     * @param string $key 缓存键
     * @param array $data 数据
     * @param int $ttl 有效期（秒），<=0 表示不缓存
     * @return bool
     */
    public static function set(string $key, array $data, int $ttl): bool
    {
        if ($ttl <= 0 || !self::validKey($key)) {
            return false;
        }

        $dir = self::directory();

        if ('' === $dir) {
            return false;
        }

        $file = self::file($key);
        $temp = $file . '.' . getmypid() . '.tmp';
        $payload = json_encode(
            ['expire' => time() + $ttl, 'data' => $data],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $handle = @fopen($temp, 'wb');

        if (false === $handle) {
            return false;
        }

        $locked = flock($handle, LOCK_EX);

        if ($locked) {
            fwrite($handle, $payload);
            fflush($handle);
            flock($handle, LOCK_UN);
        }

        fclose($handle);

        if (!$locked) {
            @unlink($temp);
            return false;
        }

        if (!@rename($temp, $file)) {
            @unlink($temp);
            return false;
        }

        return true;
    }

    /**
     * 清空全部缓存。
     *
     * @return int 删除的文件数
     */
    public static function clear(): int
    {
        $dir = self::directory();
        $removed = 0;

        if ('' === $dir || !is_dir($dir)) {
            return 0;
        }

        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            if (@unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * 删除单条缓存。
     *
     * @param string $key 缓存键
     * @return bool
     */
    public static function forget(string $key): bool
    {
        if (!self::validKey($key)) {
            return false;
        }

        $file = self::file($key);

        return !is_file($file) || @unlink($file);
    }

    /**
     * 缓存统计。
     *
     * @return array{count:int,size:int}
     */
    public static function stats(): array
    {
        $dir = self::directory();
        $count = 0;
        $size = 0;

        if ('' === $dir || !is_dir($dir)) {
            return ['count' => 0, 'size' => 0];
        }

        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            $count++;
            $size += (int) @filesize($file);
        }

        return ['count' => $count, 'size' => $size];
    }

    /**
     * 确保缓存目录可用（创建目录并写入保护文件）。
     *
     * @return bool
     */
    public static function prepare(): bool
    {
        return '' !== self::directory();
    }

    /**
     * 生成缓存键。
     *
     * @param string $ip 已归一化地址
     * @param string $engine 引擎标识
     * @param string $database 库文件路径
     * @param string $language 语言
     * @return string
     */
    public static function key(string $ip, string $engine, string $database, string $language): string
    {
        return md5($ip . '|' . $engine . '|' . $database . '|' . $language);
    }

    /**
     * 缓存目录（不存在时创建并写入保护文件）。
     *
     * @return string 目录不可用时返回空串
     */
    private static function directory(): string
    {
        if (null !== self::$dir) {
            return self::$dir;
        }

        $dir = Config::cacheDir();
        self::$dir = '';

        if (is_dir($dir) || @mkdir($dir, 0755, true)) {
            self::guard($dir);
            self::$dir = $dir;
        }

        return self::$dir;
    }

    /**
     * 写入目录保护文件，避免缓存被直接列举或通过 Web 访问。
     *
     * @param string $dir 缓存目录
     * @return void
     */
    private static function guard(string $dir): void
    {
        $indexHtml = $dir . DIRECTORY_SEPARATOR . 'index.html';

        if (!is_file($indexHtml)) {
            @file_put_contents($indexHtml, '<!DOCTYPE html><meta charset="utf-8"><title>403</title>');
        }

        $htaccess = $dir . DIRECTORY_SEPARATOR . '.htaccess';

        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "# Generated by IPLocation plugin\nRequire all denied\n");
        }
    }

    /**
     * 缓存文件路径。
     *
     * @param string $key 缓存键
     * @return string
     */
    private static function file(string $key): string
    {
        return self::directory() . DIRECTORY_SEPARATOR . $key . '.json';
    }

    /**
     * 校验缓存键，只允许 md5 形式的十六进制串。
     *
     * @param string $key 缓存键
     * @return bool
     */
    private static function validKey(string $key): bool
    {
        return (bool) preg_match('/^[0-9a-f]{32}$/', $key);
    }
}
