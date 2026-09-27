#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
虎皮椒渠道插件图标生成器

为每个渠道插件生成 V10 要求的图标文件：plugins/gateway/<目录名>/<插件标识>.png

用法：
    python make_icon.py --plugin-dir ../xunhupay_wx --class XunhupayWx --type wx
    python make_icon.py --plugin-dir ../xunhupay_wx --class XunhupayWx --type wx --size 128

@author 空雨不流泪
@link   https://www.kybll.cn/
"""

import argparse
import os
import sys

try:
    from PIL import Image, ImageDraw, ImageFont
except ImportError:
    sys.exit('缺少 Pillow，请先执行：pip install Pillow')

FONT_CANDIDATES = [
    r'C:\Windows\Fonts\msyhbd.ttc',
    r'C:\Windows\Fonts\msyh.ttc',
    r'C:\Windows\Fonts\simhei.ttf',
    '/usr/share/fonts/truetype/wqy/wqy-zenhei.ttc',
    '/System/Library/Fonts/PingFang.ttc',
]

# 渠道预设：背景色 + 图标文字
PRESETS = {
    'wx':      {'bg': (7, 193, 96),   'text': '微'},
    'ali':     {'bg': (22, 119, 255), 'text': '支'},
    'default': {'bg': (255, 122, 0),  'text': '虎'},
}


def load_font(size):
    for path in FONT_CANDIDATES:
        if os.path.exists(path):
            try:
                return ImageFont.truetype(path, size)
            except Exception:
                continue
    return ImageFont.load_default()


def make_icon(preset, out_path, size=64):
    scale = 4
    s = size * scale
    img = Image.new('RGBA', (s, s), (0, 0, 0, 0))
    draw = ImageDraw.Draw(img)

    radius = int(s * 0.22)
    draw.rounded_rectangle([0, 0, s - 1, s - 1], radius=radius, fill=preset['bg'] + (255,))

    text = preset['text']
    font = load_font(int(s * 0.58))
    bbox = draw.textbbox((0, 0), text, font=font)
    tw, th = bbox[2] - bbox[0], bbox[3] - bbox[1]
    draw.text(
        ((s - tw) / 2 - bbox[0], (s - th) / 2 - bbox[1]),
        text, font=font, fill=(255, 255, 255, 255)
    )

    img = img.resize((size, size), Image.LANCZOS)
    img.save(out_path, 'PNG', optimize=True)
    return out_path


def main():
    parser = argparse.ArgumentParser(description='生成虎皮椒渠道插件图标')
    parser.add_argument('--plugin-dir', required=True, help='插件目录路径')
    parser.add_argument('--class', dest='cls', required=True, help='插件标识(类名)，即图标文件名')
    parser.add_argument('--type', default='default', choices=list(PRESETS.keys()), help='渠道类型')
    parser.add_argument('--size', type=int, default=64, help='图标边长(px)，默认 64')
    args = parser.parse_args()

    plugin_dir = os.path.abspath(args.plugin_dir)
    if not os.path.isdir(plugin_dir):
        sys.exit('插件目录不存在：%s' % plugin_dir)

    out_path = os.path.join(plugin_dir, args.cls + '.png')
    make_icon(PRESETS[args.type], out_path, args.size)
    print('已生成图标：%s (%dx%d)' % (out_path, args.size, args.size))


if __name__ == '__main__':
    main()
