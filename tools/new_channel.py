#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
虎皮椒支付渠道插件 —— 一键新增渠道

背景：
    V10 内核 PluginModel::pluginList() 是「扫描 plugins/gateway/* 目录」并以插件标识为键去重，
    因此一个插件目录 = 后台列表里的一个支付方式 = 一套 APPID。
    想同时挂多个虎皮椒账号（多供应商/多店铺分流），就必须有多个插件目录。
    本脚本以现有渠道为母版，自动完成「复制 + 改标识 + 改类名 + 改显示名 + 换图标」，
    把这件事压缩成一条命令。

用法：
    python new_channel.py --dir xunhupay_wx2 --name "虎皮椒-微信支付②"
    python new_channel.py --dir xunhupay_ali --name "虎皮椒-支付宝" --type ali
    python new_channel.py --dir xunhupay_wx3 --name "虎皮椒-微信③" --size 64

生成后目录结构（以 xunhupay_ali 为例）：
    xunhupay_ali/
      ├── XunhupayAli.php           主类（含 XunhupayAliHandle）
      ├── XunhupayAli.png           后台/前台支付图标
      ├── config.php                后台配置表单
      ├── controller/IndexController.php   回调
      └── lib/XunhupayCore.php      虎皮椒 SDK

@author 空雨不流泪
@link   https://www.kybll.cn/
"""

import argparse
import re
import shutil
import subprocess
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
REPO_ROOT = HERE.parent                 # 插件集仓库根
PLUGINS_DIR = REPO_ROOT / 'plugins'     # 各渠道插件所在目录
SKIP_SUFFIX = {'.png', '.jpg', '.jpeg', '.gif', '.ico', '.zip', '.pyc'}
SKIP_DIRS = {'__pycache__', 'dist', '.git'}


def to_studly(dir_name):
    """目录名 -> 大驼峰标识，等价于 ThinkPHP 的 parse_name($name, 1)"""
    parts = re.split(r'[_\-]+', dir_name)
    return ''.join(p[:1].upper() + p[1:] for p in parts if p)


def read_template_title(tpl_dir):
    """从模板 config.php 中读取 module_name 默认值，作为待替换的显示名"""
    config_file = tpl_dir / 'config.php'
    if not config_file.exists():
        sys.exit('模板目录缺少 config.php：%s' % tpl_dir)
    text = config_file.read_text(encoding='utf-8')
    match = re.search(r"'module_name'\s*=>\s*\[(.*?)\]", text, re.S)
    if not match:
        sys.exit('无法从模板 config.php 解析 module_name 配置块')
    value = re.search(r"'value'\s*=>\s*'([^']*)'", match.group(1))
    if not value:
        sys.exit('无法从模板 config.php 解析 module_name 默认值')
    return value.group(1)


def read_template_pay_type(tpl_dir):
    """读取模板 config.php 中 pay_type 的默认值（wx / ali）"""
    text = (tpl_dir / 'config.php').read_text(encoding='utf-8')
    match = re.search(r"渠道类型',.*?'value'\s*=>\s*'([^']*)'", text, re.S)
    return match.group(1) if match else 'wx'


def main():
    parser = argparse.ArgumentParser(description='虎皮椒支付渠道插件 - 一键新增渠道')
    parser.add_argument('--dir', required=True, help='新插件目录名(小写下划线)，也是命名空间片段，如 xunhupay_ali')
    parser.add_argument('--name', required=True, help='新渠道显示名，如 虎皮椒-支付宝')
    parser.add_argument('--type', default='wx', choices=['wx', 'ali', 'default'], help='渠道类型，决定图标与前台文案')
    parser.add_argument('--template', default='xunhupay_wx', help='母版目录名，默认 xunhupay_wx')
    parser.add_argument('--size', type=int, default=128, help='图标边长(px)，默认 128')
    parser.add_argument('--force', action='store_true', help='目标目录已存在时先删除再生成')
    args = parser.parse_args()

    new_dir = args.dir.strip()
    if not re.fullmatch(r'[a-z][a-z0-9_]*', new_dir):
        sys.exit('目录名不合法：%s（要求小写字母开头，仅含小写字母/数字/下划线）' % new_dir)

    tpl_dir = PLUGINS_DIR / args.template
    if not tpl_dir.is_dir():
        sys.exit('母版目录不存在：%s' % tpl_dir)

    dst_dir = PLUGINS_DIR / new_dir
    if dst_dir.exists():
        if not args.force:
            sys.exit('目标目录已存在：%s（如需覆盖请加 --force）' % dst_dir)
        shutil.rmtree(dst_dir)

    old_dir = args.template
    old_class = to_studly(old_dir)
    new_class = to_studly(new_dir)
    old_title = read_template_title(tpl_dir)
    old_pay_type = read_template_pay_type(tpl_dir)

    if old_dir == new_dir:
        sys.exit('新目录名不能与母版相同')

    # 1. 复制骨架（图标另行生成）
    def _ignore(_dir, names):
        return [n for n in names if n in SKIP_DIRS or Path(n).suffix.lower() == '.png']

    shutil.copytree(tpl_dir, dst_dir, ignore=_ignore)

    # 2. 文本替换
    replaced_files = 0
    for path in sorted(dst_dir.rglob('*')):
        if not path.is_file():
            continue
        if path.suffix.lower() in SKIP_SUFFIX:
            continue
        text = path.read_text(encoding='utf-8')
        original = text
        text = text.replace(old_class, new_class)
        text = text.replace(old_dir, new_dir)
        text = text.replace(old_title, args.name)

        # 主类中的渠道类型兜底值
        if path.suffix.lower() == '.php':
            text = text.replace("public $payType = '%s';" % old_pay_type,
                                "public $payType = '%s';" % args.type)

        if path.name == 'config.php' and old_pay_type != args.type:
            text = re.sub(
                r"(渠道类型',.*?'value'\s*=>\s*')[^']*(')",
                lambda m: m.group(1) + args.type + m.group(2),
                text, count=1, flags=re.S
            )

        if text != original:
            path.write_text(text, encoding='utf-8', newline='\n')
            replaced_files += 1

    # 3. 重命名主类文件
    src_class_file = dst_dir / (old_class + '.php')
    if src_class_file.exists():
        src_class_file.rename(dst_dir / (new_class + '.php'))

    # 4. 生成图标
    icon_script = HERE / 'make_icon.py'
    code = subprocess.call([
        sys.executable, str(icon_script),
        '--plugin-dir', str(dst_dir),
        '--class', new_class,
        '--type', args.type,
        '--size', str(args.size),
    ])
    if code != 0:
        print('⚠ 图标生成失败，请手动补充 %s.png' % new_class, file=sys.stderr)

    print('')
    print('✅ 渠道插件已生成')
    print('   目录      : %s' % dst_dir)
    print('   插件标识  : %s' % new_class)
    print('   显示名    : %s' % args.name)
    print('   替换文件  : %d 个' % replaced_files)
    print('')
    print('   下一步：把整个 %s 目录复制到 public/plugins/gateway/ ，' % new_dir)
    print('          再到后台「系统 - 接口管理 - 支付接口」刷新，即可看到新渠道。')


if __name__ == '__main__':
    main()
