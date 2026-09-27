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
 * 虎皮椒支付插件主文件（微信渠道）
 *
 * @author  空雨不流泪
 * @link    https://www.kybll.cn/
 * @see     https://www.xunhupay.com/doc/api/pay.html 虎皮椒官方接口文档
 * @version 1.0.0
 */

namespace gateway\xunhupay_ali;

use app\common\lib\Plugin;
use gateway\xunhupay_ali\lib\XunhupayCore;

/**
 * @desc 虎皮椒-支付宝插件主文件
 */
class XunhupayAli extends Plugin
{
    // 插件基础信息
    public $info = array(
        'name'        => 'XunhupayAli', // 必填 插件标识(唯一)
        'title'       => '虎皮椒-支付宝', // 必填 插件显示名称
        'description' => '虎皮椒-支付宝（个人支付渠道，支持多账户/多供应商并存）', // 必填 插件功能描述
        'author'      => '空雨不流泪', // 必填 插件作者
        'version'     => '1.0.0',  // 必填 插件版本
        'help_url'    => 'https://www.kybll.cn/', // 选填 申请链接
        'author_url'  => 'https://www.kybll.cn/', // 选填 作者链接
        'url'         => '', // 选填 图标地址(可以自定义支付图片地址)
    );

    // 临时订单生成规则,1:毫秒时间戳+8位随机数(21-22位长度订单号,默认规则),2:时间戳+8位随机数(18位长度订单号),3:10位随机数(10位长度订单号)
    public $orderRule = 1;

    // 默认渠道类型(wx 微信 / ali 支付宝),仅用于前台提示文案与按钮配色
    public $payType = 'ali';

    // 插件安装
    public function install()
    {
        return true;//安装成功返回true，失败false
    }

    // 插件卸载
    public function uninstall()
    {
        return true;//卸载成功返回true，失败false
    }

    /**
     * 发起支付（系统反射调用：插件标识 + Handle）
     *
     * @param array $param 订单数据
     * @return string|array 支付页 HTML，或 ['status'=>400,'msg'=>...] 表示失败
     */
    public function XunhupayAliHandle($param)
    {
        $config = $this->getConfig();

        if (empty($config['appid']) || empty($config['appsecret'])) {
            return ['status' => 400, 'msg' => '虎皮椒支付接口尚未配置 APPID / APPSECRET，请先在后台「接口管理」中完善配置'];
        }

        $core = new XunhupayCore($config);

        $outTradeNo = isset($param['out_trade_no']) ? (string)$param['out_trade_no'] : '';
        $totalFee   = isset($param['finance']['total']) ? $param['finance']['total'] : '0.00';

        if ($outTradeNo === '') {
            return ['status' => 400, 'msg' => '订单号缺失，无法发起支付'];
        }

        $subject = !empty($param['product'][0]) ? $param['product'][0] : configuration('website_name');

        $domain = rtrim((string)configuration('website_url'), '/');
        $notifyUrl = $domain . '/gateway/xunhupay_ali/index/notifyHandle';
        $returnUrl = $domain . '/gateway/xunhupay_ali/index/returnHandle';

        $result = $core->createOrder([
            'trade_order_id' => $outTradeNo,
            'total_fee'      => $totalFee,
            'title'          => $subject,
            'time'           => time(),
            'notify_url'     => $notifyUrl,
            'return_url'     => $returnUrl,
            'callback_url'   => $returnUrl,
            'plugins'        => 'idcsmart-v10-xunhupay',
        ]);

        if (!empty($result['errcode'])) {
            $msg = isset($result['errmsg']) && $result['errmsg'] !== '' ? $result['errmsg'] : '未知错误';
            return ['status' => 400, 'msg' => '发起虎皮椒支付失败：' . $msg];
        }

        $qrcode = isset($result['url_qrcode']) ? (string)$result['url_qrcode'] : '';
        $mobileUrl = isset($result['url']) ? (string)$result['url'] : '';

        if ($qrcode === '' && $mobileUrl === '') {
            return ['status' => 400, 'msg' => '发起虎皮椒支付失败：接口未返回支付链接'];
        }

        $payType = !empty($config['pay_type']) ? $config['pay_type'] : $this->payType;

        return $this->payHtml($qrcode, $mobileUrl, $totalFee, $outTradeNo, $payType);
    }

    /**
     * 生成支付输出页 HTML
     *
     * 电脑端展示二维码，手机端自动跳转到虎皮椒收银台。
     *
     * @param string $qrcode     二维码图片地址
     * @param string $mobileUrl  手机端跳转地址
     * @param mixed  $amount     应付金额
     * @param string $outTradeNo 商户订单号
     * @param string $payType    渠道类型 wx|ali
     * @return string
     */
    private function payHtml($qrcode, $mobileUrl, $amount, $outTradeNo, $payType)
    {
        $isAli = ($payType === 'ali');

        $tip      = $isAli ? '请使用支付宝扫描下方二维码完成支付' : '请使用微信扫描下方二维码完成支付';
        $btnText  = $isAli ? '在手机支付宝中打开' : '在手机微信中打开';
        $btnColor = $isAli ? '#1677ff' : '#07c160';

        $qrcodeSafe = htmlspecialchars($qrcode, ENT_QUOTES, 'UTF-8');
        $urlSafe    = htmlspecialchars($mobileUrl, ENT_QUOTES, 'UTF-8');
        $amountSafe = htmlspecialchars(number_format((float)$amount, 2, '.', ''), ENT_QUOTES, 'UTF-8');
        $noSafe     = htmlspecialchars($outTradeNo, ENT_QUOTES, 'UTF-8');

        // 供 JS 使用，避免手工拼接引发的转义问题
        $urlJson = json_encode($mobileUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $qrcodeBlock = '';
        if ($qrcode !== '') {
            $qrcodeBlock = '<img src="' . $qrcodeSafe . '" alt="支付二维码" style="width:220px;height:220px;max-width:90%;padding:6px;background:#fff;border:1px solid #e8e8e8;border-radius:8px;">';
        }

        $btnBlock = '';
        if ($mobileUrl !== '') {
            $btnBlock = '<div style="margin-top:14px;"><a href="' . $urlSafe . '" target="_blank" rel="noopener" '
                . 'style="display:inline-block;padding:9px 24px;background:' . $btnColor . ';color:#fff;border-radius:4px;text-decoration:none;font-size:14px;">'
                . $btnText . '</a></div>';
        }

        $html = '<div class="xunhupay-pay" style="padding:16px 8px;text-align:center;font-size:14px;color:#333;line-height:1.7;">'
            . '<div style="margin-bottom:14px;">' . $tip . '</div>'
            . $qrcodeBlock
            . '<div style="margin-top:12px;color:#666;">订单号：' . $noSafe . '</div>'
            . '<div style="margin-top:4px;color:#666;">应付金额：<b style="color:#e64340;font-size:16px;">¥' . $amountSafe . '</b></div>'
            . $btnBlock
            . '<div style="margin-top:12px;color:#999;font-size:12px;">二维码 5 分钟内有效，支付完成后请稍候，页面会自动刷新</div>'
            . '</div>';

        // 手机端直接跳转收银台
        $html .= '<script>(function(){try{var u=' . $urlJson . ';'
            . 'if(u&&/Android|iPhone|iPod|iPad|Windows Phone|HarmonyOS|Mobile/i.test(navigator.userAgent||"")){window.location.href=u;}}catch(e){}})();</script>';

        return $html;
    }
}
