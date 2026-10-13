# IPLocation

IPLocation 地理位置插件，为 Typecho 设计的 IP 归属地解析和展示插件，基于 IP2Region 和 MindMax 的开源数据库，同时也是 [RoricalTheme](https://github.com/little-gt/RoricalTheme) 所依赖的插件。

[![License](https://img.shields.io/badge/license-GPL--3.0-blue.svg?style=for-the-badge)](https://github.com/little-gt/IPLocation/blob/main/LICENSE)
[![Typecho](https://img.shields.io/badge/Typecho-1.2%2B-brightgreen.svg?style=for-the-badge)](https://typecho.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg?style=for-the-badge)](https://www.php.net)

![IP Location管理页面](https://cnb.cool/little-gt/IPLocation/-/git/raw/main/screenshot.png)

---

## 特性

- **纯离线解析**：内置 ip2region 与 MaxMind GeoLite2 双引擎，全部查询在本地完成，不向任何第三方服务发送请求
- **智能回退与细节补全**：ip2region 返回「未知 / 0 / Reserved」或字段不全时，自动改查 GeoLite2，并用其结果补全国籍代码、洲、行政区、城市、运营商、ASN、邮编、时区、经纬度、命中网段等细节
- **评论属地展示**：评论列表自动在 IP 后追加归属地标签，前台默认只输出属地、不泄露完整 IP
- **灵活的输出粒度**：国内精确到省市、国外到国家级，支持自定义模板（`%continent% %country% %region% %city% %isp% %asn% %ip%`）与多语言（简体中文 / English / 日本語 / Русский 等 8 种）
- **后台管理面板**：数据源与归属地库状态、查询自检、缓存维护、评论来源分布，暗色模式自适应
- **文件缓存**：按「地址 + 引擎 + 库目录 + 库文件名 + 语言」生成缓存键，库升级或改名后旧缓存自动失效
- **安全加固**：可信代理校验（防 X-Forwarded-For 伪造）、评论 IP 匿名化入库、库目录与库文件名均可自定义、路径穿越防护、输出全部转义

## 安装

1. 下载插件并解压，将 `IPLocation` 目录放入 Typecho 的 `usr/plugins/` 下；
2. 进入「后台 → 插件管理」，启用 **IPLocation**；
3. 按下方说明准备归属地库文件，放入插件的 `Database/` 目录；
4. 进入「管理 → IP 属地」面板确认库状态，或前往「设置 → 插件 → IPLocation」调整参数。

## 归属地库

插件使用以下 5 个库文件，需放置在 `Database/` 目录（文件名与目录名均可在设置中自定义，见[安全加固](#安全加固)）：

| 文件 | 引擎 | 用途 |
| --- | --- | --- |
| `ip2region_v4.xdb` | ip2region | IPv4 中文属地（国家 / 省市 / 运营商） |
| `ip2region_v6.xdb` | ip2region | IPv6 中文属地 |
| `GeoLite2-City.mmdb` | MaxMind GeoLite2 | 国家 / 省市 / 经纬度 / 时区（细节最全） |
| `GeoLite2-Country.mmdb` | MaxMind GeoLite2 | 仅国家级 |
| `GeoLite2-ASN.mmdb` | MaxMind GeoLite2 | 自治域与运营商 |

下载地址：

- **ip2region xdb**：<https://github.com/liongo/ip2region>（ Releases 中的 `ip2region_v4.xdb` 与 `ip2region_v6.xdb`，或使用 maker 自行构建）
- **MaxMind GeoLite2**：<https://dev.maxmind.com/geoip/geolite2-free-geolocation-data>（需注册账号并获取 License Key 后下载 mmdb）

> GeoLite2 为免费数据，使用时需保留 MaxMind 的署名。GeoLite2 每周更新，建议定期覆盖同名文件；ip2region 库升级后请在面板一键清空解析缓存。

库目录内已附带 `.htaccess`（Apache）阻止库文件被 Web 直接下载；使用 Nginx 请自行添加：

```nginx
location ~ \.(mmdb|xdb)$ {
    deny all;
}
```

## 配置说明

| 选项 | 说明 |
| --- | --- |
| 解析数据源 | `自动`（ip2region 优先，未命中回退 GeoLite2）/ `仅 ip2region` / `仅 GeoLite2` |
| ip2region 未命中时回退 GeoLite2 | 自动数据源下生效；开启后 ip2region 返回「未知」的地址由 MaxMind 兜底 |
| 用 GeoLite2 补全细节 | ip2region 命中但字段不全时，用 GeoLite2 补全缺失字段（已有值以 ip2region 中文结果优先） |
| GeoLite2 库 | 选用 City / Country / ASN 三种 mmdb，City 细节最全 |
| 展示语言 | 8 种语言；GeoLite2 缺失对应语言时自动回退英文，ip2region 恒为中文 |
| 展示粒度 | 智能（国内到省市、国外到国家）/ 仅国家 / 国家 + 行政区 / 国家 + 行政区 + 城市 / 全部 |
| 自定义模板 | 留空按粒度生成；占位符：`%continent% %country% %region% %city% %isp% %asn% %ip%` |
| 前台 / 后台评论属地 | 分别控制主题评论区与后台评论列表是否显示属地标签 |
| 前台隐私级别 | 只显示属地 / 属地 + 脱敏 IP（IPv4 隐藏末段，IPv6 仅保留前两段） |
| 附加运营商 / 精简后缀 / 未知文案 | 是否追加运营商、是否去掉「市 / 省」后缀、未命中时的占位文案 |
| 查询结果缓存 | 文件缓存开关与有效期（天），库文件变化后自动失效，也可在面板手动清空 |
| 可信代理 CIDR | 每行一条（如 `2606:4700::/32`）；**留空一律使用直连地址 REMOTE_ADDR，可彻底杜绝 X-Forwarded-For 伪造**；站点位于 CDN 后必须填写，否则评论 IP 会记录为代理节点 |
| 评论 IP 入库策略 | 保存原始 IP / 匿名化后入库（IPv4 归零末段 /24，IPv6 保留前 3 段 /48） |
| 归属地库目录名 | 自定义 `Database` 目录名称，见[安全加固](#安全加固) |
| 库文件名 | 分别自定义 5 个库文件的名称，留空使用默认名 |

## 主题接入

插件通过评论钩子自动生效，主题无需改动即可在 `$comments->ip()` 输出后看到属地标签。需要自定义排版时，推荐使用以下方式：

**comments.php 中直接输出标签**

```php
<?php IPLocation_Plugin::render($comments->ip); ?>
```

**取得结构化结果自行排版**

```php
<?php $loc = $comments->ipLocation; ?>
<?php if ($loc->country): ?>
    <span class="ip-location">
        <?php echo htmlspecialchars($loc->country); ?>
        <?php echo htmlspecialchars($loc->region); ?>
        <?php echo htmlspecialchars($loc->city); ?>
    </span>
<?php endif; ?>
```

**仅取文案**

```php
<?php echo htmlspecialchars($comments->ipLocationLabel); ?>
```

**可用 API**

| 方法 | 说明 |
| --- | --- |
| `IPLocation_Plugin::render($ip, $class = '')` | 输出属地标签（受「前台评论属地」开关控制） |
| `IPLocation_Plugin::label($ip)` | 返回展示文案 |
| `IPLocation_Plugin::resolve($ip)` | 返回 `Location` 对象（country / region / city / isp / asn / latitude / longitude / timeZone 等字段） |
| `IPLocation_Plugin::mask($ip)` | 返回脱敏地址 |

虚拟字段：`$comments->ipLocation`（Location 对象）、`$comments->ipLocationLabel`（文案）。
兼容别名：`XQLocation_Plugin` 与 `IPLocation_Plugin` 等价。

## 后台面板

「管理 → IP 属地」提供：

- **统计概览**：当前数据源、可用库数量、解析缓存、评论样本；
- **查询自检**：输入任意 IPv4 / IPv6，查看完整解析结果（含命中引擎、各字段取值、引擎原始返回）；
- **归属地库状态**：每个库的存在性、可读性、大小、构建版本、文件时间；
- **运行配置与缓存维护**：当前生效参数一览、一键清空缓存、刷新来源分布；
- **评论来源分布**：最近 500 条评论的归属地统计（缓存 10 分钟）。

## 安全加固

- **自定义库目录名**：把 `Database/` 改成不易猜测的名称（如 `libs9f3k2m`），再到设置填写同名即可；非法名称（含路径分隔符、`..` 等）自动回落默认目录
- **自定义库文件名**：5 个库文件均可单独改名，同样仅允许字母、数字与 `. _ -`
- **缓存自动失效**：缓存键含库目录名与库文件名，任何改名 / 换库后旧缓存立即失效
- **防 IP 伪造**：可信代理白名单为空时一律使用 REMOTE_ADDR；填写后仅当直连地址命中白名单才采信转发头，并从右向左跳过可信代理
- **隐私最小化**：前台默认不输出完整 IP；评论 IP 可匿名化后入库
- **输出安全**：所有归属地文案经 HTML 转义；配置中的库路径不接受外部输入；面板写操作带 CSRF 令牌校验

## 目录结构

```
IPLocation/
├── Plugin.php              插件入口：钩子注册、配置面板、主题 API
├── Panel.php               后台管理面板（独立页面）
├── assist/
│   ├── css/
│   │   ├── panel.css       Tailwind 产物（编译生成，请勿手改）
│   │   └── style.css       面板主题（CSS 变量 / 暗色模式）
│   └── tailwind/           Tailwind 源与配置
│       ├── input.css
│       └── tailwind.config.js
├── Database/               归属地库目录（可自定义名称）
├── Tools/
│   ├── Bootstrap.php       自动加载注册（插件类 + MaxMind / ip2region SDK）
│   ├── Cache.php           文件缓存（原子写入 + 排他锁）
│   ├── Config.php          配置读取与校验
│   ├── Ip.php              地址校验 / 归一化 / 脱敏 / 可信代理还原
│   ├── Location.php        查询结果值对象与文案生成
│   ├── Resolver.php        解析调度：引擎选择、回退、补全、缓存
│   ├── Engine/             引擎接口与 ip2region / GeoLite2 实现
│   ├── GeoLite/            MaxMind 官方 mmdb Reader
│   └── IP2Region/          ip2region 官方 xdb Searcher
└── tailwindcss-3.4.17.exe  Tailwind CLI（仅重新编译面板样式需要）
```

## 开发

重新编译面板样式（修改 `Panel.php` 或 `assist/tailwind/input.css` 后执行）：

```bash
cd usr/plugins/IPLocation
./tailwindcss-3.4.17.exe -c assist/tailwind/tailwind.config.js \
    -i assist/tailwind/input.css -o assist/css/panel.css --minify
```

## 常见问题

**Q：全部显示「未知」？**
A：依次检查——① `Database/` 内 5 个库文件是否就位且可读；② 设置中的数据源是否正确；③ 若自定义过目录名 / 文件名，是否与磁盘实际一致；④ 在面板「查询自检」输入一个公网地址看引擎命中情况。

**Q：位于 CDN / 反代之后，评论 IP 全是代理节点？**
A：在「可信代理 CIDR」中填写 CDN 的 IP 段（如 Cloudflare 的 `2606:4700::/32`），留空则一律使用 REMOTE_ADDR。

**Q：换库 / 改名后结果没变化？**
A：缓存键已包含库目录与文件名，正常情况下会自动失效；也可在面板「清空解析缓存」。

**Q：支持 IPv6 吗？**
A：支持。ip2region v6 库与 GeoLite2 均可解析 IPv6，内网 / 环回 / 链路本地等地址直接给出类型文案，不查库。

## 开源许可

[GPL-3.0](https://github.com/little-gt/IPLocation/blob/main/LICENSE)

内置第三方组件遵循其各自许可：[ip2region](https://github.com/liongo/ip2region)（Apache-2.0）、[MaxMind GeoLite2](https://dev.maxmind.com/geoip/geolite2-free-geolocation-data)（需署名）。
