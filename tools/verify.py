#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
虎皮椒支付插件 —— 交付前静态校验

本机没有 PHP 运行时，用 Python 复刻 ThinkPHP 的 parse_name 规则与 V10 插件契约，
对每个渠道目录做结构体检：

  1. 目录名 -> 标识 -> 类名 -> 文件名 的一致性（等价 parse_name($dir, 1)）
  2. 命名空间、基类、Handle 方法名（等价 plugin_reflection 的拼接规则）
  3. 回调 URL 中的插件目录与实际目录是否一致
  4. config.php 配置项键值合规（radio 的 value 必须落在 options 的键里）
  5. PHP 语法粗检：括号平衡、字符串/注释剥离、BOM 与换行符

@author 空雨不流泪
@link   https://www.kybll.cn/
"""

import re
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
REPO_ROOT = HERE.parent
PLUGINS_DIR = REPO_ROOT / 'plugins'

OK = '  [OK]   '
FAIL = '  [FAIL] '
WARN = '  [WARN] '

errors = []
warnings = []


def report(level, msg):
    print(level + msg)
    if level == FAIL:
        errors.append(msg)
    elif level == WARN:
        warnings.append(msg)


# ---------------------------------------------------------------- 内核规则复刻

def parse_name_studly(name):
    """等价 PHP: parse_name($name, 1) —— 下划线后首字母大写 + ucfirst"""
    name = re.sub(r'_([a-zA-Z])', lambda m: m.group(1).upper(), name)
    if not name:
        return name
    return name[0].upper() + name[1:]


def parse_name_snake(name):
    """等价 PHP: parse_name($name) —— 大写字母前插下划线后转小写"""
    s = re.sub(r'[A-Z]', lambda m: '_' + m.group(0), name)
    return s.strip('_').lower()


def expect_handle_method(plugin_name):
    """等价 PHP: plugin_reflection 中的 $action 计算"""
    return parse_name_studly(parse_name_snake(plugin_name) + '_handle')


# ---------------------------------------------------------------- PHP 粗检

def strip_php_literals(code):
    """去掉字符串、注释、heredoc，只留下结构字符，用于括号平衡检查"""
    out = []
    i, n = 0, len(code)
    while i < n:
        c = code[i]
        if code.startswith('//', i) or c == '#':
            j = code.find('\n', i)
            j = n if j == -1 else j
            out.append('\n')
            i = j
            continue
        if code.startswith('/*', i):
            j = code.find('*/', i + 2)
            j = n if j == -1 else j + 2
            out.append(' ')
            i = j
            continue
        if c == "'" or c == '"':
            quote = c
            j = i + 1
            while j < n:
                if code[j] == '\\':
                    j += 2
                    continue
                if code[j] == quote:
                    break
                j += 1
            i = min(j + 1, n)
            out.append(quote * 2)
            continue
        out.append(c)
        i += 1
    return ''.join(out)


def check_php_syntax(path):
    raw = path.read_bytes()
    problems = []

    if raw.startswith(b'\xef\xbb\xbf'):
        problems.append('文件带 UTF-8 BOM，会导致命名空间失效')

    code = raw.decode('utf-8', errors='replace')
    if '\r\n' in code:
        problems.append('存在 CRLF 换行')

    stripped = strip_php_literals(code)
    for open_ch, close_ch, label in [('{', '}', '花括号'), ('(', ')', '圆括号'), ('[', ']', '方括号')]:
        diff = stripped.count(open_ch) - stripped.count(close_ch)
        if diff != 0:
            problems.append('%s不平衡(差 %+d)' % (label, diff))

    if '<?php' not in code[:30]:
        problems.append('缺少 <?php 起始标记')

    return problems


# ---------------------------------------------------------------- 单渠道体检

def check_channel(plugin_dir):
    dir_name = plugin_dir.name
    print('\n▶ %s' % dir_name)

    if plugin_dir.name.startswith('_'):
        print('  [SKIP] 工具目录')
        return

    cls = parse_name_studly(dir_name)
    expect_method = expect_handle_method(cls)

    # --- 主类文件
    main_file = plugin_dir / ('%s.php' % cls)
    if not main_file.exists():
        report(FAIL, '缺少主类文件 %s.php' % cls)
        return
    report(OK, '主类文件 %s.php' % cls)

    main_code = main_file.read_text(encoding='utf-8')

    # 命名空间
    ns = 'gateway\\%s' % dir_name
    if re.search(r'namespace\s+%s\s*;' % re.escape(ns), main_code):
        report(OK, '命名空间 %s' % ns)
    else:
        report(FAIL, '命名空间应为 %s' % ns)

    # 类名与继承
    if re.search(r'class\s+%s\s+extends\s+Plugin' % re.escape(cls), main_code):
        report(OK, 'class %s extends Plugin' % cls)
    else:
        report(FAIL, '类定义应为 class %s extends Plugin' % cls)

    # $info['name'] 必须等于类名（V10 用于反查插件类）
    m = re.search(r"'name'\s*=>\s*'([^']+)'", main_code)
    if m and m.group(1) == cls:
        report(OK, "$info['name'] = %s" % m.group(1))
    else:
        report(FAIL, "$info['name'] 应为 %s，实际 %s" % (cls, m.group(1) if m else '未找到'))

    # 作者与链接约定
    if '空雨不流泪' in main_code and 'kybll.cn' in main_code:
        report(OK, '作者署名与官方链接')
    else:
        report(WARN, '作者署名/官方链接缺失')

    # Handle 方法
    if re.search(r'function\s+%s\s*\(' % re.escape(expect_method), main_code):
        report(OK, '支付入口方法 %s()' % expect_method)
    else:
        report(FAIL, '支付入口方法应为 %s()' % expect_method)

    # install / uninstall
    for fn in ('install', 'uninstall'):
        if re.search(r'function\s+%s\s*\(' % fn, main_code):
            report(OK, '实现 %s()' % fn)
        else:
            report(FAIL, '缺少 %s()' % fn)

    # 回调 URL
    urls = re.findall(r"/gateway/([a-z0-9_]+)/index/", main_code)
    bad = [u for u in urls if u != dir_name]
    if urls and not bad:
        report(OK, '回调地址指向 /gateway/%s/index/' % dir_name)
    elif bad:
        report(FAIL, '回调地址目录不匹配：%s' % ', '.join(sorted(set(bad))))
    else:
        report(WARN, '未找到回调地址定义')

    # --- 图标
    if (plugin_dir / ('%s.png' % cls)).exists():
        report(OK, '支付图标 %s.png' % cls)
    else:
        report(FAIL, '缺少支付图标 %s.png' % cls)

    # --- 控制器
    ctrl = plugin_dir / 'controller' / 'IndexController.php'
    if not ctrl.exists():
        report(FAIL, '缺少 controller/IndexController.php')
    else:
        cctrl = ctrl.read_text(encoding='utf-8')
        ns_ctrl = 'gateway\\%s\\controller' % dir_name
        if re.search(r'namespace\s+%s\s*;' % re.escape(ns_ctrl), cctrl):
            report(OK, '控制器命名空间 %s' % ns_ctrl)
        else:
            report(FAIL, '控制器命名空间应为 %s' % ns_ctrl)
        if re.search(r'class\s+IndexController\s+extends\s+BaseController', cctrl):
            report(OK, 'IndexController extends BaseController')
        else:
            report(FAIL, '控制器应继承 BaseController')
        if re.search(r'function\s+notifyHandle\s*\(', cctrl):
            report(OK, '异步回调 notifyHandle()')
        else:
            report(FAIL, '缺少 notifyHandle()')
        if 'order_pay_handle' not in cctrl:
            report(FAIL, '回调未调用 order_pay_handle() 入账')

    # --- SDK
    core = plugin_dir / 'lib' / 'XunhupayCore.php'
    if not core.exists():
        report(FAIL, '缺少 lib/XunhupayCore.php')
    else:
        ccore = core.read_text(encoding='utf-8')
        if re.search(r'namespace\s+%s\\lib\s*;' % re.escape('gateway\\%s' % dir_name), ccore):
            report(OK, 'SDK 命名空间 gateway\\%s\\lib' % dir_name)
        else:
            report(FAIL, 'SDK 命名空间错误')
        for fn in ('makeSign', 'createOrder', 'verifyNotify', 'queryOrder'):
            if not re.search(r'function\s+%s\s*\(' % fn, ccore):
                report(FAIL, 'SDK 缺少 %s()' % fn)
        report(OK, 'SDK 方法齐备 makeSign/createOrder/verifyNotify/queryOrder')

    # --- config.php
    cfg = plugin_dir / 'config.php'
    if not cfg.exists():
        report(FAIL, '缺少 config.php')
    else:
        ccfg = cfg.read_text(encoding='utf-8')
        mm = re.search(r"'module_name'\s*=>\s*\[(.*?)\]", ccfg, re.S)
        if mm and re.search(r"'value'\s*=>\s*'([^']+)'", mm.group(1)):
            report(OK, "默认支付方式名：%s" % re.search(r"'value'\s*=>\s*'([^']+)'", mm.group(1)).group(1))
        else:
            report(FAIL, 'config.php 缺少 module_name')

        # radio 的 value 必须落在 options 的键里
        for block in re.finditer(r"'(\w+)'\s*=>\s*\[(.*?)\n\s*\],", ccfg, re.S):
            key, body = block.group(1), block.group(2)
            if "type" in body and "'radio'" in body:
                val = re.search(r"'value'\s*=>\s*'([^']*)'", body)
                opts = re.findall(r"'([^']+)'\s*=>\s*'[^']*'", body.split('options')[1]) if 'options' in body else []
                if val and opts and val.group(1) not in opts:
                    report(FAIL, 'config.php 中 %s 的 value(%s) 不在 options 键内 %s' % (key, val.group(1), opts))
                elif val and opts:
                    report(OK, 'radio 配置项 %s：value=%s ∈ %s' % (key, val.group(1), opts))

    # --- 语法粗检
    for php in sorted(plugin_dir.rglob('*.php')):
        problems = check_php_syntax(php)
        if problems:
            report(FAIL, '%s：%s' % (php.relative_to(plugin_dir), '；'.join(problems)))
    report(OK, 'PHP 结构检查（括号平衡 / BOM / 换行符）')


def main():
    print('=' * 62)
    print('虎皮椒支付插件 交付前静态校验')
    print('=' * 62)

    dirs = sorted([d for d in PLUGINS_DIR.iterdir() if d.is_dir() and d.name.startswith('xunhupay')])
    if not dirs:
        sys.exit('未找到任何 xunhupay* 渠道目录')

    for d in dirs:
        check_channel(d)

    print('\n' + '=' * 62)
    if errors:
        print('❌ 校验未通过：%d 项错误，%d 项警告' % (len(errors), len(warnings)))
        for e in errors:
            print('   - ' + e)
        sys.exit(1)

    print('✅ 全部校验通过（%d 个渠道，%d 项警告）' % (len(dirs), len(warnings)))
    for w in warnings:
        print('   - ' + w)


if __name__ == '__main__':
    main()
