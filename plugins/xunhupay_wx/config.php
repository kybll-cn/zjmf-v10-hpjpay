<?php
/*
 * Copyright (C) 2026 空雨不流泪 <https://www.kybll.cn/>
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation, either version 3 of the License, or (at your option) any later
 * version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See the GNU General Public License for more
 * details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * 虎皮椒支付插件后台配置
 *
 * ⚠ 说明：V10 插件配置项中，type=select 因模板渲染缺陷不可用，
 *          枚举项请统一使用 radio（提交值为 options 的键）。
 *
 * @author  空雨不流泪
 * @link    https://www.kybll.cn/
 */

return [
    'module_name' => [
        'title' => '支付方式名称',
        'type'  => 'text',
        'value' => '虎皮椒-微信支付',
        'tip'   => '显示给客户看的支付方式名称；同一家虎皮椒开多个渠道时，请在此区分命名',
        'size'  => 200,
    ],
    'pay_type' => [
        'title'   => '渠道类型',
        'type'    => 'radio',
        'value'   => 'wx',
        'tip'     => '仅用于前台提示文案与按钮配色，实际支付方式由虎皮椒 APPID 绑定的渠道决定',
        'options' => [
            'wx'  => '微信支付',
            'ali' => '支付宝',
        ],
    ],
    'appid' => [
        'title' => 'APPID',
        'type'  => 'text',
        'value' => '',
        'tip'   => '虎皮椒后台「支付渠道管理 - 我的支付渠道」中获取；注意不是微信/支付宝的 APPID',
        'size'  => 200,
    ],
    'appsecret' => [
        'title' => 'APPSECRET',
        'type'  => 'password',
        'value' => '',
        'tip'   => '与 APPID 成对出现，请勿泄露',
        'size'  => 200,
    ],
    'api_url' => [
        'title' => '支付网关地址',
        'type'  => 'text',
        'value' => 'https://api.xunhupay.com/payment/do.html',
        'tip'   => '一般无需修改；主站异常时可改为备用网关 https://api.dpweixin.com/payment/do.html',
        'size'  => 200,
    ],
    'query_url' => [
        'title' => '订单查询地址',
        'type'  => 'text',
        'value' => 'https://api.xunhupay.com/payment/query.html',
        'tip'   => '用于后台对账查询，一般无需修改',
        'size'  => 200,
    ],
];
