# 更新日志

本项目遵循 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
版本号遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

## [1.0.0] - 2026-09-27

### 新增

- 虎皮椒个人支付接入（智简魔方 V10 `gateway` 接口类插件）
  - `xunhupay_wx` —— 微信支付渠道（标识 `XunhupayWx`）
  - `xunhupay_ali` —— 支付宝渠道（标识 `XunhupayAli`）
  - `xunhupay_wx2` —— 微信支付②渠道（标识 `XunhupayWx2`），多供应商并存示例
- **多账户 / 多供应商并存**：每个渠道独立配置 APPID / APPSECRET，各走各的收款账号，互不影响
- 支付页自适应：电脑端展示二维码，手机端按 UA 自动跳转虎皮椒收银台
- 安全校验：MD5 签名验证 + 回调 APPID 归属校验，防止伪造通知与跨渠道串单
- 图标随渠道生成（微信绿 / 支付宝蓝），无需自备素材
- 工具链：
  - `tools/new_channel.py` —— 一条命令新增渠道（复制骨架 + 改标识 + 改类名 + 换图标）
  - `tools/make_icon.py` —— 生成 128×128 渠道图标
  - `tools/verify.py` —— 交付前静态校验，在无 PHP 运行时环境下核对全部插件契约
- `build.py` —— 发行打包，一插件一 zip，zip 内保留 V10 要求的安装目录名

### 说明

- 虎皮椒的 APPID 在申请时即绑定收款渠道（微信、支付宝需分别申请），
  因此「接入多个收款账号」在 V10 中对应「多个插件目录」——这是 V10 内核结构决定的，详见 README §4。

[1.0.0]: https://github.com/kybll-cn/zjmf-v10-hpjpay/releases/tag/v1.0.0
