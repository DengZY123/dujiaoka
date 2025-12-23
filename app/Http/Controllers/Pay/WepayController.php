<?php
namespace App\Http\Controllers\Pay;


use App\Exceptions\RuleValidationException;
use App\Http\Controllers\PayController;
use Yansongda\Pay\Pay;

class WepayController extends PayController
{

    public function gateway(string $payway, string $orderSN)
    {
        try {
            // 加载网关
            $this->loadGateWay($orderSN, $payway);
            $config = [
                'app_id' => $this->payGateway->merchant_id,
                'mch_id' => $this->payGateway->merchant_key,
                'key' => $this->payGateway->merchant_pem,
                'notify_url' => url($this->payGateway->pay_handleroute . '/notify_url'),
                'return_url' => url('detail-order-sn', ['orderSN' => $this->order->order_sn]),
                'http' => [ // optional
                    'timeout' => 10.0,
                    'connect_timeout' => 10.0,
                ],
            ];
            $order = [
                'out_trade_no' => $this->order->order_sn,
                'total_fee' => bcmul($this->order->actual_price, 100, 0),
                'body' => $this->order->order_sn
            ];
            switch ($payway){
                case 'wescan':
                case 'wescan2':  // 支持多个微信扫码商户
                case 'wescan3':  // 预留更多商户号
                case 'wescan4':
                    try{
                        $result = Pay::wechat($config)->scan($order)->toArray();
                        $result['qr_code'] = $result['code_url'];
                        $result['payname'] =$this->payGateway->pay_name;
                        $result['actual_price'] = (float)$this->order->actual_price;
                        $result['orderid'] = $this->order->order_sn;
                        return $this->render('static_pages/qrpay', $result, __('dujiaoka.scan_qrcode_to_pay'));
                    } catch (\Exception $e) {
                        throw new RuleValidationException(__('dujiaoka.prompt.abnormal_payment_channel') . $e->getMessage());
                    }
                    break;

            }
        } catch (RuleValidationException $exception) {
            return $this->err($exception->getMessage());
        }
    }

    /**
     * 异步通知
     */
    public function notifyUrl()
    {
        $xml = file_get_contents('php://input');
        $arr = json_decode(json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
        $oid = $arr['out_trade_no'];
        $order = $this->orderService->detailOrderSN($oid);
        if (!$order) {
            return 'error';
        }
        $payGateway = $this->payService->detail($order->pay_id);
        if (!$payGateway) {
            return 'error';
        }
        if($payGateway->pay_handleroute != '/pay/wepay'){
            return 'error';
        }
        $config = [
            'app_id' => $payGateway->merchant_id,
            'mch_id' => $payGateway->merchant_key,
            'key' => $payGateway->merchant_pem,
        ];
        $pay = Pay::wechat($config);
        try{
            // 验证签名
            $result = $pay->verify();
            $total_fee = bcdiv($result->total_fee, 100, 2);
            $this->orderProcessService->completedOrder($result->out_trade_no, $total_fee, $result->transaction_id);
            
            // 如果是NextJS订单，发送通知
            $this->notifyNextJSIfNeeded($order);
            
            return 'success';
        } catch (\Exception $exception) {
            return 'fail';
        }
    }

    /**
     * 如果是NextJS订单，发送通知
     */
    private function notifyNextJSIfNeeded($order)
    {
        try {
            // 检查是否是NextJS订单（通过info字段判断）
            $userInfo = json_decode($order->info, true);
            
            // 处理传统NextJS套餐订单
            if (is_array($userInfo) && isset($userInfo['notify_url']) && isset($userInfo['user_id'])) {
                // 发送通知到NextJS应用
                $notifyData = [
                    'order_sn' => $order->order_sn,
                    'user_id' => $userInfo['user_id'],
                    'plan_type' => $userInfo['plan_type'] ?? '',
                    'amount' => $order->actual_price,
                    'trade_no' => $order->trade_no,
                    'status' => 'completed',
                    'timestamp' => time(),
                    'signature' => md5($order->order_sn . $order->actual_price . 'dujiaoka_secret_key')
                ];

                $this->sendNotification($userInfo['notify_url'], $notifyData, 'NextJS');
            }
            
            // 处理支付网关订单
            if (is_array($userInfo) && isset($userInfo['gateway_mode']) && $userInfo['gateway_mode']) {
                // 调用支付网关控制器处理
                $gatewayController = app('App\Http\Controllers\Api\PaymentGatewayController');
                $gatewayController->handlePaymentSuccess($order);
            }

        } catch (\Exception $e) {
            \Log::error('支付通知发送失败', [
                'order_sn' => $order->order_sn,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 发送通知的通用方法
     */
    private function sendNotification($notifyUrl, $notifyData, $source = 'Unknown')
    {
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $notifyUrl);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($notifyData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'User-Agent: Dujiaoka-Payment-Notify/1.0'
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            \Log::info($source . '支付通知发送', [
                'order_sn' => $notifyData['order_sn'] ?? $notifyData['payment_id'] ?? '',
                'notify_url' => $notifyUrl,
                'response' => $response,
                'http_code' => $httpCode
            ]);

        } catch (\Exception $e) {
            \Log::error($source . '支付通知发送失败', [
                'notify_url' => $notifyUrl,
                'error' => $e->getMessage()
            ]);
        }
    }

}
