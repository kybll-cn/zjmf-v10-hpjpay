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
 * 虎皮椒支付 SDK 核心类
 *
 * 封装虎皮椒开放接口的签名、下单、订单查询与回调验签，供各支付渠道插件复用。
 *
 * @author  空雨不流泪
 * @link    https://www.kybll.cn/
 * @see     https://www.xunhupay.com/doc/api/pay.html 虎皮椒官方接口文档
 * @version 1.0.0
 */

namespace gateway\xunhupay_wx2\lib;

class XunhupayCore
{
    /** 正式环境支付网关 */
    const PAY_URL = 'https://api.xunhupay.com/payment/do.html';

    /** 备用支付网关 */
    const PAY_URL_BACKUP = 'https://api.dpweixin.com/payment/do.html';

    /** 正式环境订单查询网关 */
    const QUERY_URL = 'https://api.xunhupay.com/payment/query.html';

    /** API 版本号 */
    const API_VERSION = '1.1';

    /**
     * 虎皮椒 APPID（支付渠道管理 -> 我的支付渠道）
     * @var string
     */
    private $appid;

    /**
     * 虎皮椒 APPSECRET
     * @var string
     */
    private $appsecret;

    /**
     * 支付下单接口地址
     * @var string
     */
    private $payUrl;

    /**
     * 订单查询接口地址
     * @var string
     */
    private $queryUrl;

    /**
     * 请求超时时间(秒)
     * @var int
     */
    private $timeout = 15;

    /**
     * @param array $config 插件配置数组,可含 appid / appsecret / api_url / query_url
     */
    public function __construct(array $config = [])
    {
        $this->appid     = isset($config['appid']) ? trim((string)$config['appid']) : '';
        $this->appsecret = isset($config['appsecret']) ? trim((string)$config['appsecret']) : '';
        $this->payUrl    = !empty($config['api_url']) ? trim((string)$config['api_url']) : self::PAY_URL;
        $this->queryUrl  = !empty($config['query_url']) ? trim((string)$config['query_url']) : self::QUERY_URL;
    }

    /**
     * 必要配置是否齐全
     *
     * @return bool
     */
    public function isConfigured()
    {
        return $this->appid !== '' && $this->appsecret !== '';
    }

    /**
     * 获取当前 APPID
     *
     * @return string
     */
    public function getAppid()
    {
        return $this->appid;
    }

    /**
     * 生成签名
     *
     * 规则（官方）：
     *  1. 所有非空参数（hash 不参与）按参数名 ASCII 码升序排序；
     *  2. 以 key=value&key2=value2 形式拼接成 stringA；
     *  3. stringA 末尾直接拼接 APPSECRET（无连接符）得到 stringSignTemp；
     *  4. 对 stringSignTemp 做 MD5，取 32 位小写即为 hash。
     *
     * @param array $data 参与签名的参数集合
     * @return string
     */
    public function makeSign(array $data)
    {
        unset($data['hash']);

        $pairs = [];
        foreach ($data as $key => $value) {
            if (is_null($value) || $value === '' || is_array($value)) {
                continue;
            }
            $pairs[$key] = (string)$key . '=' . (string)$value;
        }

        ksort($pairs, SORT_STRING);

        return md5(implode('&', $pairs) . $this->appsecret);
    }

    /**
     * 校验签名（支付响应 / 异步通知通用）
     *
     * @param array       $data 原始数据（含 hash 字段亦可）
     * @param string|null $sign 期望签名，为空时取 $data['hash']
     * @return bool
     */
    public function verifySign(array $data, $sign = null)
    {
        if ($sign === null) {
            $sign = isset($data['hash']) ? (string)$data['hash'] : '';
        }
        $sign = trim((string)$sign);
        if ($sign === '') {
            return false;
        }

        return hash_equals($this->makeSign($data), strtolower($sign));
    }

    /**
     * 发起支付下单
     *
     * @param array $params 业务参数，需含 trade_order_id / total_fee / title / notify_url
     * @return array 虎皮椒响应数组，errcode=0 表示成功
     */
    public function createOrder(array $params)
    {
        if (!$this->isConfigured()) {
            return ['errcode' => 400, 'errmsg' => '虎皮椒 APPID / APPSECRET 未配置'];
        }

        if (empty($params['trade_order_id'])) {
            return ['errcode' => 400, 'errmsg' => '商户订单号不能为空'];
        }
        if (!isset($params['total_fee']) || $params['total_fee'] === '') {
            return ['errcode' => 400, 'errmsg' => '订单金额不能为空'];
        }

        $params['version'] = isset($params['version']) ? $params['version'] : self::API_VERSION;
        $params['appid']   = $this->appid;
        $params['title']   = self::normalizeTitle(isset($params['title']) ? $params['title'] : '订单支付');

        if (empty($params['time'])) {
            $params['time'] = time();
        }
        if (empty($params['nonce_str'])) {
            $params['nonce_str'] = md5(uniqid((string)mt_rand(), true));
        }
        if (!isset($params['plugins'])) {
            $params['plugins'] = 'idcsmart-v10-xunhupay';
        }

        $params['hash'] = $this->makeSign($params);

        $response = $this->postJson($this->payUrl, $params);
        if ($response === false) {
            return ['errcode' => 500, 'errmsg' => '请求虎皮椒支付网关失败，请检查服务器网络或支付网关地址'];
        }

        $result = json_decode($response, true);
        if (!is_array($result)) {
            return ['errcode' => 500, 'errmsg' => '虎皮椒返回数据解析失败：' . self::strLimit($response, 200)];
        }

        return $result;
    }

    /**
     * 校验异步/同步通知数据是否合法
     *
     * 除验签外，还会校验 appid 是否与当前渠道一致（防止多账户串单）。
     *
     * @param array $data 通知原始数据（$_POST / $_GET）
     * @return array ['status' => bool, 'msg' => string]
     */
    public function verifyNotify(array $data)
    {
        if (empty($data)) {
            return ['status' => false, 'msg' => '通知数据为空'];
        }
        if (empty($data['hash'])) {
            return ['status' => false, 'msg' => '通知数据缺少签名'];
        }
        if (!$this->isConfigured()) {
            return ['status' => false, 'msg' => '渠道未配置 APPID / APPSECRET'];
        }
        if (isset($data['appid']) && (string)$data['appid'] !== $this->appid) {
            return ['status' => false, 'msg' => 'APPID 与本渠道不匹配'];
        }
        if (!$this->verifySign($data)) {
            return ['status' => false, 'msg' => '签名校验失败'];
        }

        return ['status' => true, 'msg' => 'ok'];
    }

    /**
     * 查询订单（用于后台对账，非支付主流程）
     *
     * @param string $tradeOrderId 商户订单号
     * @return array
     */
    public function queryOrder($tradeOrderId)
    {
        if (!$this->isConfigured()) {
            return ['errcode' => 400, 'errmsg' => '虎皮椒 APPID / APPSECRET 未配置'];
        }

        $params = [
            'appid'           => $this->appid,
            'out_trade_order' => $tradeOrderId,
            'time'            => time(),
            'nonce_str'       => md5(uniqid((string)mt_rand(), true)),
        ];
        $params['hash'] = $this->makeSign($params);

        $response = $this->postJson($this->queryUrl, $params);
        if ($response === false) {
            return ['errcode' => 500, 'errmsg' => '请求虎皮椒查询接口失败'];
        }

        $result = json_decode($response, true);

        return is_array($result) ? $result : ['errcode' => 500, 'errmsg' => '查询结果解析失败'];
    }

    /**
     * 订单状态是否为「已支付」
     *
     * @param string $status 虎皮椒状态：OD 已支付 / CD 已退款 / RD 退款中 / UD 退款失败
     * @return bool
     */
    public static function isPaid($status)
    {
        return strtoupper((string)$status) === 'OD';
    }

    /**
     * 规整订单标题
     *
     * 虎皮椒限制：不超过 127 个字符或 42 个汉字，且不能含表情符号与百分号。
     *
     * @param string $title
     * @return string
     */
    public static function normalizeTitle($title)
    {
        $title = (string)$title;
        // 去掉百分号（官方明确禁止）
        $title = str_replace(['%', '％'], '', $title);
        // 去掉 emoji 与杂项符号（四字节 UTF-8 区段）
        $title = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE00}-\x{FE0F}\x{2B00}-\x{2BFF}]/u', '', $title);
        if ($title === null) {
            $title = '';
        }
        $title = trim(preg_replace('/\s+/u', ' ', $title));

        // 42 个汉字约等于 42 个多字节字符，这里保守取 30
        if (function_exists('mb_substr')) {
            $title = mb_substr($title, 0, 30, 'UTF-8');
        } else {
            $title = substr($title, 0, 90);
        }

        return $title === '' ? '订单支付' : $title;
    }

    /**
     * 以 JSON 方式 POST 数据
     *
     * @param string $url  请求地址
     * @param array  $data 请求数据
     * @return string|false 响应内容，失败返回 false
     */
    private function postJson($url, array $data)
    {
        if (!function_exists('curl_init')) {
            return false;
        }

        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json, */*',
            'Connection: close',
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        // 部分国内服务器 CA 证书过期，这里与 V10 内置支付插件保持一致的宽松策略
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $response = curl_exec($ch);
        curl_close($ch);

        return $response;
    }

    /**
     * 字符串截断（避免异常信息过长）
     *
     * @param string $str
     * @param int    $length
     * @return string
     */
    private static function strLimit($str, $length)
    {
        $str = (string)$str;
        if (function_exists('mb_substr')) {
            return mb_substr($str, 0, $length, 'UTF-8');
        }

        return substr($str, 0, $length);
    }
}
