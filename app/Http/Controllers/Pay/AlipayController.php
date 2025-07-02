<?php

namespace App\Http\Controllers\Pay;

use App\Exceptions\RuleValidationException;
use App\Http\Controllers\PayController;
use Illuminate\Http\Request;
use Yansongda\Pay\Pay;

class AlipayController extends PayController
{

    /**
     * 支付宝支付网关
     *
     * @param string $payway
     * @param string $orderSN
     */
    public function gateway(string $payway, string $orderSN)
    {
        try {
            // 加载网关
            $this->loadGateWay($orderSN, $payway);
            $config = [
                'app_id' => $this->payGateway->merchant_id,
                'ali_public_key' => $this->payGateway->merchant_key,
                'private_key' => $this->payGateway->merchant_pem,
                'notify_url' => url($this->payGateway->pay_handleroute . '/notify_url'),
                'return_url' => url('detail-order-sn', ['orderSN' => $this->order->order_sn]),
                'http' => [ // optional
                    'timeout' => 10.0,
                    'connect_timeout' => 10.0,
                ],
            ];
            $order = [
                'out_trade_no' => $this->order->order_sn,
                'total_amount' => (float)$this->order->actual_price,
                'subject' => $this->order->order_sn
            ];
            switch ($payway){
                case 'zfbf2f':
                case 'alipayscan':
                    try{
                        $result = Pay::alipay($config)->scan($order)->toArray();
                        $result['payname'] = $this->order->order_sn;
                        $result['actual_price'] = (float)$this->order->actual_price;
                        $result['orderid'] = $this->order->order_sn;
                        $result['jump_payuri'] = $result['qr_code'];
                        return $this->render('static_pages/qrpay', $result, __('dujiaoka.scan_qrcode_to_pay'));
                    } catch (\Exception $e) {
                        return $this->err(__('dujiaoka.prompt.abnormal_payment_channel') . $e->getMessage());
                    }
                case 'aliweb':
                    try{
                        $result = Pay::alipay($config)->web($order);
                        return $result;
                    } catch (\Exception $e) {
                        return $this->err(__('dujiaoka.prompt.abnormal_payment_channel') . $e->getMessage());
                    }
                case 'aliwap':
                    try{
                        $result = Pay::alipay($config)->wap($order);
                        return $result;
                    } catch (\Exception $e) {
                        return $this->err(__('dujiaoka.prompt.abnormal_payment_channel') . $e->getMessage());
                    }
            }
        } catch (RuleValidationException $exception) {
            return $this->err($exception->getMessage());
        }
    }


    /**
     * 异步通知
     */
    public function notifyUrl(Request $request)
    {
        $orderSN = $request->input('out_trade_no');
        $order = $this->orderService->detailOrderSN($orderSN);
        if (!$order) {
            return 'error';
        }
        $payGateway = $this->payService->detail($order->pay_id);
        if (!$payGateway) {
            return 'error';
        }
        if($payGateway->pay_handleroute != '/pay/alipay'){
            return 'fail';
        }
        $config = [
            'app_id' => $payGateway->merchant_id,
            'ali_public_key' => $payGateway->merchant_key,
            'private_key' => $payGateway->merchant_pem,
        ];
        $pay = Pay::alipay($config);
        try{
            // 验证签名
            $result = $pay->verify();
            if ($result->trade_status == 'TRADE_SUCCESS' || $result->trade_status == 'TRADE_FINISHED') {
                $this->orderProcessService->completedOrder($result->out_trade_no, $result->total_amount, $result->trade_no);
                
                // 支付成功后处理网关模式通知
                $this->notifyNextJSIfNeeded($order);
            }
            return 'success';
        } catch (\Exception $exception) {
            return 'fail';
        }
    }

    /**
     * 通知NextJS或其他业务系统（如果是网关模式订单）
     * 
     * @param \App\Models\Order $order
     * @return void
     */
    private function notifyNextJSIfNeeded($order)
    {
        try {
            $userInfo = json_decode($order->info, true);
            
            // 检查是否是网关模式订单
            if (isset($userInfo['gateway_mode']) && $userInfo['gateway_mode']) {
                // 网关模式：调用PaymentGatewayController的处理方法
                $gatewayController = app('App\Http\Controllers\Api\PaymentGatewayController');
                $gatewayController->handlePaymentSuccess($order);
            } else {
                // 原有逻辑：NextJS集成模式（保持向后兼容）
                if (isset($userInfo['notify_url'])) {
                    $this->sendNotificationToNextJS($userInfo['notify_url'], [
                        'order_id' => $userInfo['order_id'],
                        'payment_status' => 'completed',
                        'trade_no' => $order->trade_no,
                        'amount' => $order->actual_price,
                        'timestamp' => time()
                    ]);
                }
            }
        } catch (\Exception $e) {
            \Log::error('支付宝通知处理失败', [
                'order_sn' => $order->order_sn,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 发送通知到NextJS系统（向后兼容方法）
     * 
     * @param string $notifyUrl
     * @param array $data
     * @return void
     */
    private function sendNotificationToNextJS(string $notifyUrl, array $data)
    {
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $notifyUrl);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'User-Agent: Dujiaoka-Payment-Notification/1.0'
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            \Log::info('NextJS通知发送成功', [
                'notify_url' => $notifyUrl,
                'order_id' => $data['order_id'],
                'response' => $response,
                'http_code' => $httpCode
            ]);

        } catch (\Exception $e) {
            \Log::error('NextJS通知发送失败', [
                'notify_url' => $notifyUrl,
                'order_id' => $data['order_id'] ?? '',
                'error' => $e->getMessage()
            ]);
        }
    }



}
