<?php
/**
 * Paytaro 弹窗显码支付接口（V2Board）—— 无收银台模式
 * 由 Paytaro 一键脚本 install-qr.sh 生成。可与收银台模式的 Paytaro.php 共存。
 * 后台「支付配置」添加时接口选择 PaytaroQR，每个 Paytaro 支付方式（支付宝 / USDT-TRC20 …）各添加一条，填对应的支付方式 UUID。
 * 文档：https://v3.paytaro.com/#/docs/install-script   客服：https://t.me/paytaro
 */

namespace App\Payments;

class PaytaroQR
{
    const API = 'https://v3.paytaro.com';
    const PAGE_PATH = '/paytaro-qr/pay.php'; // 面板域名下的弹窗页（加密货币用）

    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'pid' => [
                'label' => 'App ID',
                'description' => __('The App ID of the Paytaro application;'),
                'type' => 'input',
            ],
            'key' => [
                'label' => 'App Secret',
                'description' => __('The App Secret of the Paytaro application;'),
                'type' => 'input',
            ],
            'method_uuid' => [
                'label' => __('Payment method UUID'),
                'description' => __('Paytaro merchant dashboard -> App Management -> Application -> Payment Methods -> copy the UUID of the payment method (add one payment config per method)'),
                'type' => 'input',
            ],
            'alert1' => [
                'type' => 'alert',
                'content' => __('QR-in-dialog mode: the customer scans or transfers directly on this page instead of at the Paytaro checkout. To open an account or enable a payment method, contact <a href="https://t.me/paytaro" target="_blank">@paytaro</a>'),
            ],
        ];
    }

    private function appId()
    {
        return !empty($this->config['pid']) ? trim($this->config['pid']) : '';
    }

    private function appSecret()
    {
        return !empty($this->config['key']) ? trim($this->config['key']) : '';
    }

    public function pay($order)
    {
        $methodUuid = trim((string) ($this->config['method_uuid'] ?? ''));
        if ($methodUuid === '') {
            abort(500, __('Paytaro: please enter the payment method UUID in the payment config'));
        }
        $payload = [
            'merchant_no' => (string) $order['trade_no'],
            'order_amount' => round(((int) $order['total_amount']) / 100, 2), // V2Board 以分计
            'notify_url' => (string) $order['notify_url'],
            'method_uuid' => $methodUuid,
        ];
        if (!empty($order['return_url'])) {
            $payload['return_url'] = (string) $order['return_url'];
        }

        $res = $this->request('/v1/invoice/pay', $payload);
        $payment = isset($res['payment']) && is_array($res['payment']) ? $res['payment'] : null;
        if ($payment === null || empty($payment['data']) || empty($res['uuid'])) {
            abort(500, __('The Paytaro response is incomplete'));
        }

        $linkType = strtolower((string) ($payment['link_type'] ?? ''));
        $isAlipay = $linkType === 'h5' || $linkType === 'pc' || strtolower((string) ($payment['type'] ?? '')) === 'alipay';

        // V2Board：type 0 = 面板弹窗显示二维码，1 = 跳转 data
        if ($isAlipay) {
            if ($this->isMobile()) {
                return ['type' => 1, 'data' => !empty($payment['mobile_url']) ? $payment['mobile_url'] : $payment['data']];
            }
            if ($linkType === 'pc') {
                return ['type' => 1, 'data' => $payment['data']]; // 电脑网站支付：直接进支付宝 PC 收银台
            }
            return ['type' => 0, 'data' => $payment['data']]; // 手机网站支付链接：PC 上画码，支付宝扫一扫即可
        }

        // 加密货币：面板原生弹窗放不下数量与复制按钮，跳到本站域名下的弹窗页
        return ['type' => 1, 'data' => $this->siteBase((string) $order['notify_url']) . self::PAGE_PATH . '?uuid=' . rawurlencode($res['uuid'])];
    }

    public function notify($params)
    {
        $secret = isset($_SERVER['HTTP_X_APP_SECRET']) ? (string) $_SERVER['HTTP_X_APP_SECRET'] : '';
        if ($secret === '' || !hash_equals($this->appSecret(), $secret)) {
            return false;
        }
        if (!is_array($params) || !isset($params['merchant_no'])) {
            $raw = json_decode((string) file_get_contents('php://input'), true);
            if (is_array($raw)) {
                $params = $raw;
            }
        }
        if (!is_array($params)) {
            return false;
        }
        if (!in_array($params['status'] ?? '', ['PAID', 'SUCCESS'], true)) {
            return false;
        }
        $tradeNo = (string) ($params['merchant_no'] ?? '');
        $callbackNo = (string) ($params['callback_no'] ?? '');
        if ($callbackNo === '') {
            $callbackNo = (string) ($params['transaction_no'] ?? '');
        }
        if ($tradeNo === '' || $callbackNo === '') {
            return false;
        }
        return [
            'trade_no' => $tradeNo,
            'callback_no' => $callbackNo,
            'custom_result' => 'success',
        ];
    }

    private function request($path, array $payload)
    {
        $ch = curl_init(self::API . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-App-Secret: ' . $this->appSecret(),
                'User-Agent: PaytaroQR-V2Board/' . $this->appId(),
            ],
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno || $body === false) {
            abort(500, __('A Paytaro network error occurred, please try again later'));
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            abort(500, sprintf(__('Paytaro responded abnormally (HTTP %s)'), $status));
        }
        if ($status < 200 || $status >= 300) {
            abort(500, sprintf(__('Paytaro: %s'), (string) ($data['error'] ?? $data['message'] ?? ('HTTP ' . $status))));
        }
        return $data;
    }

    private function siteBase($url)
    {
        $u = parse_url($url);
        if (empty($u['scheme']) || empty($u['host'])) {
            return '';
        }
        return $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
    }

    private function isMobile()
    {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        return preg_match('/Android|iPhone|iPad|iPod|Mobile|HarmonyOS/i', $ua) === 1;
    }
}