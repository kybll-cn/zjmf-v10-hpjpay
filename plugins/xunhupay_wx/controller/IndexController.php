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
 * 虎皮椒支付插件回调处理
 *
 * 外部地址：网站地址/gateway/xunhupay_wx/index/notifyHandle
 *
 * @author  空雨不流泪
 * @link    https://www.kybll.cn/
 * @see     https://www.xunhupay.com/doc/api/pay.html 虎皮椒官方接口文档
 * @version 1.0.0
 */

namespace gateway\xunhupay_wx\controller;

use app\home\controller\BaseController;
use gateway\xunhupay_wx\XunhupayWx;
use gateway\xunhupay_wx\lib\XunhupayCore;

/**
 * @desc 虎皮椒支付插件回调处理文件
 */
class IndexController extends BaseController
{
    /**
     * 异步回调（虎皮椒服务器 -> 本站，POST 表单）
     *
     * 处理成功必须原样输出 success，否则虎皮椒会重复回调 6 次。
     *
     * @return void
     */
    public function notifyHandle()
    {
        $plugin = new XunhupayWx();
        $config = $plugin->getConfig();
        $core   = new XunhupayCore($config);

        // 虎皮椒以 form 表单 POST 通知，这里只取表单原文，避免路由变量污染签名
        $data = !empty($_POST) ? $_POST : $_GET;

        if (empty($data)) {
            echo 'fail';
            return;
        }

        $verify = $core->verifyNotify($data);
        if (!$verify['status']) {
            trace('xunhupay notify verify failed: ' . $verify['msg'] . ' | ' . json_encode($data, JSON_UNESCAPED_UNICODE), 'error');
            echo 'fail';
            return;
        }

        // 非「已支付」状态（如已退款 CD）无需入账，但仍需应答 success 停止重试
        if (!XunhupayCore::isPaid(isset($data['status']) ? $data['status'] : '')) {
            echo 'success';
            return;
        }

        $payTime = !empty($data['time']) && is_numeric($data['time'])
            ? date('Y-m-d H:i:s', (int)$data['time'])
            : date('Y-m-d H:i:s');

        $result = order_pay_handle([
            'tmp_order_id' => isset($data['trade_order_id']) ? $data['trade_order_id'] : '', // 商户订单号（即系统临时订单号）
            'amount'       => isset($data['total_fee']) ? $data['total_fee'] : '',          // 订单总价
            'trans_id'     => isset($data['transaction_id']) ? $data['transaction_id'] : '', // 支付平台交易号
            'currency'     => 'CNY',
            'paid_time'    => $payTime,
            'gateway'      => $plugin->info['name'],                                        // 支付网关标识
        ]);

        echo $result === false ? 'fail' : 'success';
    }

    /**
     * 同步回调（用户支付完成后浏览器跳转回本站）
     *
     * @return \think\response\Redirect
     */
    public function returnHandle()
    {
        // 以异步通知为准，这里只负责把用户送回订单/财务页面
        return redirect(url('/finance'));
    }
}
