# zjmf-v10-hpjpay — 虎皮椒支付插件（智简魔方 V10）

对接 [虎皮椒个人支付](https://www.xunhupay.com/) 的 **智简魔方 V10 `gateway` 接口类插件**，
原生支持 **多账户 / 多供应商并存**——同一个站点可以同时挂多个虎皮椒收款账号。

- 作者：空雨不流泪 · 官方网站 <https://www.kybll.cn/>
- 虎皮椒接口文档 <https://www.xunhupay.com/doc/api/pay.html>
- 开源协议：GPL-3.0

---

## 1. 特性

| 特性 | 说明 |
|---|---|
| 免签约 | 虎皮椒面向个人站长，个人身份即可申请，无需企业资质 |
| 多账户并存 | 每个渠道独立配置 APPID / APPSECRET，可同时挂多个收款账号分流 |
| 一指令加渠道 | `python tools/new_channel.py --dir xunhupay_wx3 --name "虎皮椒-微信③"` |
| 自适应支付页 | 电脑端展示二维码，手机端自动跳转虎皮椒收银台 |
| 安全校验 | MD5 签名验证 + APPID 归属校验，防伪造通知、防跨渠道串单 |
| 零素材依赖 | 渠道图标由脚本生成，不需要自己找图 |
| 可离线校验 | `tools/verify.py` 在没有 PHP 运行时的机器上也能核对插件契约 |

预置三个渠道：

| 目录 | 插件标识 | 后台显示名 | 用途 |
|---|---|---|---|
| `plugins/xunhupay_wx` | `XunhupayWx` | 虎皮椒-微信支付 | 微信渠道（母版） |
| `plugins/xunhupay_ali` | `XunhupayAli` | 虎皮椒-支付宝 | 支付宝渠道 |
| `plugins/xunhupay_wx2` | `XunhupayWx2` | 虎皮椒-微信支付② | 多供应商示例 |

---

## 2. 环境要求

| 项目 | 要求 |
|---|---|
| 系统 | 智简魔方 V10（后端 ThinkPHP 6 / PHP 7.4） |
| PHP 扩展 | `curl`（发起支付请求）、`mbstring`（建议，用于标题截断） |
| 网络 | 服务器需能访问 `api.xunhupay.com`；站点需有可被外网访问的域名（接收异步回调） |
| 协议 | 建议 HTTPS |

---

## 3. 目录结构

```
zjmf-v10-hpjpay/                      ← 仓库根
├── README.md
├── CHANGELOG.md
├── LICENSE                           ← GPL-3.0
├── build.py                          ← 发行打包
├── plugins/                          ← 各渠道插件（每个都是一个独立的 V10 插件）
│   ├── xunhupay_wx/
│   │   ├── XunhupayWx.php            ← 主类：$info / install / uninstall / XunhupayWxHandle
│   │   ├── XunhupayWx.png            ← 支付图标（V10 要求「插件标识.png」）
│   │   ├── config.php                ← 后台配置表单
│   │   ├── controller/
│   │   │   └── IndexController.php   ← 异步回调 notifyHandle / 同步回调 returnHandle
│   │   └── lib/
│   │       └── XunhupayCore.php      ← 虎皮椒 SDK（签名 / 下单 / 查询 / 验签）
│   ├── xunhupay_ali/                 ← 同构
│   └── xunhupay_wx2/                 ← 同构
├── tools/
│   ├── new_channel.py                ← 一键新增渠道
│   ├── make_icon.py                  ← 生成支付图标
│   └── verify.py                     ← 交付前静态校验
└── dist/                             ← 打包产物（不进版本库）
```

---

## 4. 为什么是"多个插件"而不是"一个插件配多个账号"

这不是偷懒，是 V10 的内核结构决定的。

V10 后台「接口管理 → 支付接口」的列表来自 `app/admin/model/PluginModel.php::pluginList()`：

```php
$dirs = array_map('basename', glob(WEB_ROOT . "plugins/{$module}/*", GLOB_ONLYDIR));
...
$plugins[$plugin['name']] = $plugin;   // 以插件标识为键
```

两件事同时成立：

1. 列表是**扫描目录**得到的；
2. 结果数组**以插件标识为键**，同名只能有一条。

而且插件标识还要能反查出类名（`get_plugin_class()` → `gateway\{目录}\{标识}`）。
所以：

> **一个插件目录 = 后台列表里的一个支付方式 = 一套 APPID。**
> 想让站点挂多个虎皮椒账号，就必须有多个插件目录——没有别的口子。

再看虎皮椒一侧：它的 **APPID 在申请时就绑定了收款渠道**（微信渠道、支付宝渠道需分别申请，
API 里也没有 `type` 参数来切换支付方式）。也就是说，一个 APPID 只能发起一种支付方式。

两件事叠加，结论很自然：**"两个微信支付、对接两个供应商" = 两套 APPID = 两个插件目录**。
本插件把这件事的成本压到一条命令（见 §8）。

---

## 5. 安装

### 方式一：用 Release 里的安装包（推荐）

1. 到 [Releases](https://github.com/kybll-cn/zjmf-v10-hpjpay/releases) 下载需要的渠道包，例如 `XunhupayWx-1.0.0.zip`；
2. 解压得到 `xunhupay_wx/` 目录；
3. 把整个目录上传到站点 `public/plugins/gateway/` 下，最终路径为
   `public/plugins/gateway/xunhupay_wx/`；
4. 后台 →「系统 → 接口管理 → 支付接口」→ 点刷新，即可看到「虎皮椒-微信支付」；
5. 点「安装」。

> ⚠️ **目录名不要改**。V10 按 `parse_name($目录名, 1)` 推导类名
> （`xunhupay_wx` → `XunhupayWx`），改名会导致插件加载不到。

### 方式二：clone 仓库

```bash
git clone --depth 1 https://github.com/kybll-cn/zjmf-v10-hpjpay.git xunhupay
cp -r xunhupay/plugins/xunhupay_wx  /path/to/site/public/plugins/gateway/
cp -r xunhupay/plugins/xunhupay_ali /path/to/site/public/plugins/gateway/
```

注意 `xunhupay_wx` 这些**目录名必须保持原样**。

### 方式三：从源码自行打包

```bash
cd xunhupay
python build.py            # 生成 dist/XunhupayWx-1.0.0.zip 等
```

---

## 6. 虎皮椒侧准备

1. 注册 [虎皮椒](https://www.xunhupay.com/)，进入 **支付渠道管理 → 我的支付渠道**；
2. 申请渠道（**微信与支付宝要分别申请**），审核通过后得到该渠道的 **APPID** 与 **APPSECRET**；
3. 记下这两个值，稍后填进插件后台配置。

**回调地址无需在虎皮椒后台填写**——本插件在下单时把 `notify_url` 直接传给虎皮椒，
形如 `https://你的域名/gateway/xunhupay_wx/index/notifyHandle`，每个渠道指向自己的目录。

---

## 7. 后台配置与绑定

进入「系统 → 接口管理 → 支付接口」，找到对应渠道，点「配置」：

| 配置项 | 必填 | 说明 |
|---|---|---|
| 支付方式名称 | 是 | 展示给客户的名称，多账号时请加以区分，如「虎皮椒-微信支付②」 |
| 渠道类型 | 是 | 微信支付 / 支付宝，**仅决定前台提示文案与按钮配色**，实际由 APPID 绑定的渠道决定 |
| APPID | 是 | 虎皮椒「我的支付渠道」中获取 |
| APPSECRET | 是 | 与 APPID 成对出现，请勿泄露 |
| 支付网关地址 | 否 | 默认 `https://api.xunhupay.com/payment/do.html`，主站异常时可改为备用网关 |
| 订单查询地址 | 否 | 默认 `https://api.xunhupay.com/payment/query.html`，用于后台对账 |

保存后回到列表，把该支付方式**启用**，即可在客户结算时出现。

---

## 8. 多账号 / 多供应商

### 场景一：微信、支付宝各用一个虎皮椒账号

直接分别配置 `xunhupay_wx` 和 `xunhupay_ali` 即可，两者互不影响。

### 场景二：两个微信支付，对接两个不同供应商

仓库已内置 `xunhupay_wx` 与 `xunhupay_wx2`，分别填入两套 APPID / APPSECRET 即可。
客户在结算时会看到两个微信支付选项（显示名可在后台分别改）。

### 场景三：还想再加第三个、第四个……

```bash
cd xunhupay
python tools/new_channel.py --dir xunhupay_wx3 --name "虎皮椒-微信支付③" --type wx
```

一条命令自动完成：复制骨架 → 改命名空间 → 改类名 → 改 `$info` → 改回调地址 → 生成图标。
把生成的 `plugins/xunhupay_wx3/` 上传到 `public/plugins/gateway/`，后台刷新即可。

| 参数 | 必填 | 说明 |
|---|---|---|
| `--dir` | 是 | 新插件目录名，小写下划线，如 `xunhupay_wx3` |
| `--name` | 是 | 后台/前台显示的支付方式名 |
| `--type` | 否 | `wx`（默认）/ `ali` / `default`，决定图标与前台文案 |
| `--template` | 否 | 母版目录，默认 `xunhupay_wx` |
| `--size` | 否 | 图标边长，默认 128 |
| `--force` | 否 | 目标目录已存在时先删除再生成 |

> 手工复制也能做，但**务必同步修改回调地址里的目录名**——这是最容易漏的地方，
> 漏了会导致订单永远收不到支付成功通知。

---

## 9. 工作原理

```
客户下单 → POST /console/v1/pay {gateway: "XunhupayWx"}
   │
   ├─ OrderTmpModel::pay() 生成临时订单号（毫秒时间戳+8位随机，20+ 位）
   ├─ plugin_reflection() 反射调用入口方法 XunhupayWxHandle($param)
   │
   ├─ 本插件 POST 虎皮椒 https://api.xunhupay.com/payment/do.html
   │     参数：version / appid / trade_order_id / total_fee / title / time /
   │           notify_url / return_url / nonce_str / hash(签名)
   │     返回：url_qrcode（电脑端二维码）/ url（手机端收银台）
   │
   └─ 渲染支付页 HTML（电脑端展示二维码，手机端自动跳转）
          ↓  客户完成支付
虎皮椒 → POST https://你的域名/gateway/xunhupay_wx/index/notifyHandle
   ├─ 校验签名 + 校验 APPID 归属
   ├─ 判断 status == OD（已支付）
   ├─ order_pay_handle() 入账（临时订单号 / 金额 / 交易号 / 支付时间 / 渠道标识）
   └─ 原样输出 success（否则虎皮椒会重复回调 6 次）
```

前台同时会轮询 `/console/v1/pay/{id}/status` 感知支付结果，无需插件额外处理页面跳转。

**签名算法**（虎皮椒官方规则）：

1. 除 `hash` 外的所有**非空**参数按参数名 ASCII 升序排序；
2. 拼成 `key1=value1&key2=value2…`；
3. 末尾**直接**拼接 APPSECRET（无任何连接符）；
4. MD5 取 32 位小写。

---

## 10. 与同类插件对比

| | 本插件（虎皮椒） | `wx_pay`（微信直连） | `EpayWx`（易支付） |
|---|---|---|---|
| 申请门槛 | 个人可申请 | 需企业资质 | 取决于上级易支付平台 |
| 资金去向 | 微信官方 T+1 结算至个人银行卡 | 对公账户 | 取决于平台 |
| 多账号并存 | 原生支持 | 需手动复制改造 | 需手动复制改造 |
| 依赖 | 仅虎皮椒 | 微信商户平台 | 第三方易支付站点 |
| 适合场景 | 个人站长、小型业务 | 正规企业业务 | 依赖上级平台稳定性 |

---

## 11. 方法一览

| 方法 | 位置 | 作用 |
|---|---|---|
| `XunhupayWxHandle($param)` | 主类 | 支付入口，由内核反射调用；返回支付页 HTML 或错误数组 |
| `install()` / `uninstall()` | 主类 | 安装 / 卸载钩子（本插件无需初始化数据，直接返回 `true`） |
| `notifyHandle()` | IndexController | 异步回调：验签 → 校验状态 → 入账 → 输出 `success` |
| `returnHandle()` | IndexController | 同步回调：把用户送回财务页 |
| `XunhupayCore::makeSign($data)` | lib | 生成 MD5 签名 |
| `XunhupayCore::verifySign($data)` | lib | 校验签名 |
| `XunhupayCore::createOrder($params)` | lib | 发起支付下单 |
| `XunhupayCore::verifyNotify($data)` | lib | 回调数据校验（验签 + APPID 归属） |
| `XunhupayCore::queryOrder($tradeOrderId)` | lib | 查询订单（后台对账用） |

---

## 12. 常见报错 FAQ

**Q：后台接口列表里看不到插件？**
确认目录放在了 `public/plugins/gateway/xunhupay_wx/`（不是 `plugins/xunhupay_wx/`），
且目录名没被改过，然后点列表页的刷新。

**Q：点支付提示「发起虎皮椒支付失败」？**
看提示里的具体原因。常见的是 APPID / APPSECRET 填错，或服务器无法访问 `api.xunhupay.com`。

**Q：支付成功但订单一直不变成已支付？**
说明异步回调没到。排查顺序：
1. 站点域名能否被外网访问（本地环境收不到回调）；
2. 站点日志中是否有 `xunhupay notify verify failed`——有则说明 APPSECRET 填错导致验签失败；
3. 检查 `public` 目录下是否放对了位置。

**Q：二维码扫出来提示订单不存在 / 已过期？**
虎皮椒二维码有效期 5 分钟，过期后重新发起支付即可。

**Q：手机端点支付没有自动跳转？**
手机端靠 UA 判断后跳转，个别内置浏览器 UA 不含 `Mobile` 字样时会停留在二维码页，
此时点页面上的「在手机微信/支付宝中打开」按钮即可。

**Q：能不能一个插件填多个 APPID？**
不能，原因见 §4。请用 `tools/new_channel.py` 生成新渠道。

**Q：想改前台显示的支付方式名？**
后台配置里改「支付方式名称」即可，无需改代码。

---

## 13. 开发与发版

```bash
# 交付前静态校验（无需 PHP 运行时）
python tools/verify.py

# 新增渠道
python tools/new_channel.py --dir xunhupay_wx3 --name "虎皮椒-微信支付③" --type wx

# 单独生成图标
python tools/make_icon.py --plugin-dir plugins/xunhupay_wx --class XunhupayWx --type wx

# 打包
python build.py
```

`tools/verify.py` 会在无 PHP 环境下核对：类名与文件名一致性、命名空间、
`{标识}Handle` 入口方法、回调地址目录、支付图标、`config.php` 的枚举取值、
PHP 括号平衡与 BOM/CRLF。所有渠道全绿后才建议发版。

发版时同步更新 `CHANGELOG.md` 与各插件主类的 `$info['version']`（`build.py` 会自动读取版本号）。

---

## 14. 授权协议

本项目基于 [GNU General Public License v3.0](LICENSE) 开源，
Copyright (C) 2026 空雨不流泪 <https://www.kybll.cn/>。

你可以自由使用、修改、再分发，但**衍生作品必须同样以 GPL-3.0 开源**。

---

## 15. 免责声明

- 本插件为第三方对接实现，与虎皮椒官方无隶属关系。
- 使用前请自行确认你的业务符合虎皮椒的接入条款与相关法律法规。
- 因使用本插件产生的资金风险、业务损失，作者不承担任何责任。
- 支付通道的费率、结算周期、风控政策以虎皮椒官方为准。
