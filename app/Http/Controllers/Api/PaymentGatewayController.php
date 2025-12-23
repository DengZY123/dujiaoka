<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseController;
use App\Service\OrderProcessService;
use App\Service\PayService;
use App\Service\OrderService;
use App\Models\Goods;
use App\Models\Order;
use App\Models\Pay;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * 纯支付网关控制器
 * 将独角兽发卡网作为纯支付处理器，不处理任何业务逻辑
 * 
 * @author Claude Code Assistant
 */
class PaymentGatewayController extends BaseController
{
    protected $orderService;
    protected $payService;
    protected $orderProcessService;

    public function __construct()
    {
        $this->orderService = app('Service\OrderService');
        $this->payService = app('Service\PayService');
        $this->orderProcessService = app('Service\OrderProcessService');
    }

    /**
     * 创建支付订单（纯网关模式）
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function createPayment(Request $request)
    {
        try {
            // 验证请求参数
            $request->validate([
                'amount' => 'required|numeric|min:0.01|max:50000',
                'currency' => 'string|in:CNY',
                'order_id' => 'required|string|max:128',
                'description' => 'required|string|max:255',
                'notify_url' => 'required|url|max:512',
                'return_url' => 'required|url|max:512',
                'pay_method' => 'string|in:wescan,wescan2,aliweb,aliwap', // 支付方式（新增wescan2）
                'metadata' => 'array' // 业务自定义数据
            ]);

            // 检查金额范围
            $amount = floatval($request->amount);
            if ($amount < 0.01 || $amount > 50000) {
                return response()->json([
                    'success' => false,
                    'error' => '支付金额必须在0.01-50000元之间'
                ], 400);
            }

            // 检查外部订单号是否已存在（安全的JSON查询）
            $existingOrder = Order::where(function($query) use ($request) {
                $query->whereRaw("JSON_VALID(info) = 1")
                      ->whereJsonContains('info->external_order_id', $request->order_id)
                      ->whereJsonContains('info->gateway_mode', true);
            })->first();
            
            if ($existingOrder) {
                return response()->json([
                    'success' => false,
                    'error' => '订单号已存在'
                ], 400);
            }

            // 获取支付方式（默认微信扫码）
            $payMethod = $request->pay_method ?? 'wescan';
            $payGateway = Pay::where('pay_check', $payMethod)
                ->where('is_open', Pay::STATUS_OPEN)
                ->first();

            if (!$payGateway) {
                return response()->json([
                    'success' => false,
                    'error' => '支付方式未配置或已关闭'
                ], 400);
            }

            // 创建或获取虚拟商品（用于兼容现有订单系统）
            $virtualGoods = $this->getOrCreateVirtualGoods($request->description, $amount);

            // 生成支付订单号
            $paymentId = 'PAY_' . strtoupper(Str::random(16));

            // 准备网关数据
            $gatewayData = [
                'gateway_mode' => true,
                'external_order_id' => $request->order_id,
                'description' => $request->description,
                'currency' => $request->currency ?? 'CNY',
                'notify_url' => $request->notify_url,
                'return_url' => $request->return_url,
                'metadata' => $request->metadata ?? [],
                'created_at' => Carbon::now()->toISOString()
            ];

            // 创建订单（复用现有表结构）
            $order = new Order();
            $order->order_sn = $paymentId;
            $order->goods_id = $virtualGoods->id;
            $order->title = $request->description;
            $order->type = Order::MANUAL_PROCESSING; // 手动处理
            $order->search_pwd = Str::random(8);
            $order->email = 'gateway@dujiaoka.com'; // 占位邮箱
            $order->pay_id = $payGateway->id;
            $order->goods_price = $amount;
            $order->buy_amount = 1;
            $order->info = json_encode($gatewayData); // 存储网关数据
            $order->buy_ip = $request->ip();
            $order->coupon_discount_price = 0;
            $order->wholesale_discount_price = 0;
            $order->total_price = $amount;
            $order->actual_price = $amount;
            $order->status = Order::STATUS_WAIT_PAY;
            $order->save();

            // 生成支付二维码/跳转链接
            $paymentResult = $this->generatePaymentData($payGateway, $order, $payMethod);
            
            if (!$paymentResult) {
                return response()->json([
                    'success' => false,
                    'error' => '生成支付信息失败'
                ], 500);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'payment_id' => $paymentId,
                    'amount' => $amount,
                    'currency' => $request->currency ?? 'CNY',
                    'description' => $request->description,
                    'payment_data' => $paymentResult,
                    'expire_time' => Carbon::now()->addMinutes(15)->toISOString(),
                    'status' => 'pending'
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('支付网关创建订单失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => '创建支付订单失败: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 查询支付状态
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPaymentStatus(Request $request)
    {
        try {
            $request->validate([
                'payment_id' => 'required|string'
            ]);

            $order = Order::where('order_sn', $request->payment_id)
                ->where(function($query) {
                    $query->whereRaw("JSON_VALID(info) = 1")
                          ->whereJsonContains('info->gateway_mode', true);
                })
                ->first();
            
            if (!$order) {
                return response()->json([
                    'success' => false,
                    'error' => '支付订单不存在'
                ], 404);
            }

            $gatewayData = json_decode($order->info, true);
            
            // 状态映射
            $statusMap = [
                Order::STATUS_WAIT_PAY => 'pending',
                Order::STATUS_PENDING => 'processing',
                Order::STATUS_PROCESSING => 'processing',
                Order::STATUS_COMPLETED => 'completed',
                Order::STATUS_FAILURE => 'failed',
                Order::STATUS_EXPIRED => 'expired',
                Order::STATUS_ABNORMAL => 'failed'
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'payment_id' => $order->order_sn,
                    'external_order_id' => $gatewayData['external_order_id'],
                    'amount' => $order->actual_price,
                    'currency' => $gatewayData['currency'] ?? 'CNY',
                    'description' => $gatewayData['description'],
                    'status' => $statusMap[$order->status] ?? 'unknown',
                    'trade_no' => $order->trade_no,
                    'metadata' => $gatewayData['metadata'] ?? [],
                    'created_at' => $order->created_at->toISOString(),
                    'updated_at' => $order->updated_at->toISOString()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => '查询失败: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 获取支付订单列表（可选功能）
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPayments(Request $request)
    {
        try {
            $page = $request->get('page', 1);
            $limit = min($request->get('limit', 20), 100);
            $externalOrderId = $request->get('external_order_id');

            $query = Order::where(function($q) {
                    $q->whereRaw("JSON_VALID(info) = 1")
                      ->whereJsonContains('info->gateway_mode', true);
                })
                ->orderBy('created_at', 'desc');

            if ($externalOrderId) {
                $query->where(function($q) use ($externalOrderId) {
                    $q->whereRaw("JSON_VALID(info) = 1")
                      ->whereJsonContains('info->external_order_id', $externalOrderId);
                });
            }

            $orders = $query->paginate($limit, ['*'], 'page', $page);

            $data = $orders->items();
            $formattedData = array_map(function ($order) {
                $gatewayData = json_decode($order->info, true);
                $statusMap = [
                    Order::STATUS_WAIT_PAY => 'pending',
                    Order::STATUS_PENDING => 'processing',
                    Order::STATUS_PROCESSING => 'processing',
                    Order::STATUS_COMPLETED => 'completed',
                    Order::STATUS_FAILURE => 'failed',
                    Order::STATUS_EXPIRED => 'expired',
                    Order::STATUS_ABNORMAL => 'failed'
                ];

                return [
                    'payment_id' => $order->order_sn,
                    'external_order_id' => $gatewayData['external_order_id'],
                    'amount' => $order->actual_price,
                    'currency' => $gatewayData['currency'] ?? 'CNY',
                    'description' => $gatewayData['description'],
                    'status' => $statusMap[$order->status] ?? 'unknown',
                    'trade_no' => $order->trade_no,
                    'created_at' => $order->created_at->toISOString()
                ];
            }, $data);

            return response()->json([
                'success' => true,
                'data' => $formattedData,
                'pagination' => [
                    'current_page' => $orders->currentPage(),
                    'per_page' => $orders->perPage(),
                    'total' => $orders->total(),
                    'last_page' => $orders->lastPage()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => '查询失败: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 获取或创建虚拟商品
     * 
     * @param string $description
     * @param float $price
     * @return Goods
     */
    private function getOrCreateVirtualGoods(string $description, float $price): Goods
    {
        // 尝试获取通用虚拟商品
        $virtualGoods = Goods::where('gd_name', 'Gateway Virtual Product')
            ->where('type', Order::MANUAL_PROCESSING)
            ->first();

        if (!$virtualGoods) {
            // 创建虚拟商品
            $virtualGoods = new Goods();
            $virtualGoods->group_id = 1; // 设置默认分组ID
            $virtualGoods->gd_name = 'Gateway Virtual Product';
            $virtualGoods->gd_description = '支付网关虚拟商品，用于处理自定义金额支付';
            $virtualGoods->gd_keywords = '支付网关,虚拟商品,自定义金额'; // 必填字段
            $virtualGoods->picture = ''; // 可选字段
            $virtualGoods->retail_price = 0.00;
            $virtualGoods->actual_price = 0.00; // 虚拟商品价格为0，实际金额由订单控制
            $virtualGoods->in_stock = 999999; // 虚拟无限库存
            $virtualGoods->sales_volume = 0;
            $virtualGoods->ord = 1;
            $virtualGoods->buy_limit_num = 0; // 不限制购买数量
            $virtualGoods->buy_prompt = '';
            $virtualGoods->description = '此商品用于支付网关处理自定义金额支付，请勿手动购买';
            $virtualGoods->type = Order::MANUAL_PROCESSING;
            $virtualGoods->wholesale_price_cnf = '';
            $virtualGoods->other_ipu_cnf = '';
            $virtualGoods->api_hook = '';
            $virtualGoods->is_open = Goods::STATUS_OPEN;
            $virtualGoods->save();
        }

        return $virtualGoods;
    }

    /**
     * 生成支付数据
     * 
     * @param Pay $payGateway
     * @param Order $order
     * @param string $payMethod
     * @return array|null
     */
    private function generatePaymentData(Pay $payGateway, Order $order, string $payMethod): ?array
    {
        try {
            $config = [
                'app_id' => $payGateway->merchant_id,
                'mch_id' => $payGateway->merchant_key,
                'key' => $payGateway->merchant_pem,
                'notify_url' => url('/pay/wepay/notify_url'),
                'return_url' => json_decode($order->info)->return_url,
            ];

            $payOrder = [
                'out_trade_no' => $order->order_sn,
                'total_fee' => bcmul($order->actual_price, 100, 0),
                'body' => $order->title
            ];

            switch ($payMethod) {
                case 'wescan':
                case 'wescan2':  // 🆕 公司商户，使用相同的微信扫码逻辑
                    $result = \Yansongda\Pay\Pay::wechat($config)->scan($payOrder)->toArray();
                    return [
                        'type' => 'qrcode',
                        'qr_code' => $result['code_url'],
                        'method' => 'wechat_scan'
                    ];

                case 'aliweb':
                    // 支付宝网页版配置不同
                    $alipayConfig = [
                        'app_id' => $payGateway->merchant_id,
                        'ali_public_key' => $payGateway->merchant_key,
                        'private_key' => $payGateway->merchant_pem,
                        'notify_url' => url('/pay/alipay/notify_url'),
                        'return_url' => json_decode($order->info)->return_url,
                    ];
                    $alipayOrder = [
                        'out_trade_no' => $order->order_sn,
                        'total_amount' => $order->actual_price,
                        'subject' => $order->title
                    ];
                    $result = \Yansongda\Pay\Pay::alipay($alipayConfig)->web($alipayOrder);
                    return [
                        'type' => 'redirect',
                        'redirect_url' => $result->getTargetUrl(),
                        'method' => 'alipay_web'
                    ];

                case 'aliwap':
                    // 支付宝手机版配置不同
                    $alipayConfig = [
                        'app_id' => $payGateway->merchant_id,
                        'ali_public_key' => $payGateway->merchant_key,
                        'private_key' => $payGateway->merchant_pem,
                        'notify_url' => url('/pay/alipay/notify_url'),
                        'return_url' => json_decode($order->info)->return_url,
                    ];
                    $alipayOrder = [
                        'out_trade_no' => $order->order_sn,
                        'total_amount' => $order->actual_price,
                        'subject' => $order->title
                    ];
                    $result = \Yansongda\Pay\Pay::alipay($alipayConfig)->wap($alipayOrder);
                    return [
                        'type' => 'redirect',
                        'redirect_url' => $result->getTargetUrl(),
                        'method' => 'alipay_wap'
                    ];

                default:
                    return null;
            }

        } catch (\Exception $e) {
            \Log::error('生成支付数据失败', [
                'pay_method' => $payMethod,
                'order_sn' => $order->order_sn,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * 支付成功回调处理（内部方法，由支付控制器调用）
     * 
     * @param Order $order
     * @return void
     */
    public function handlePaymentSuccess(Order $order)
    {
        try {
            $gatewayData = json_decode($order->info, true);
            
            // 检查是否是网关订单
            if (!isset($gatewayData['gateway_mode']) || !$gatewayData['gateway_mode']) {
                return;
            }

            // 发送通知到业务系统
            if (isset($gatewayData['notify_url'])) {
                $this->sendNotificationToBusinessSystem($gatewayData['notify_url'], [
                    'payment_id' => $order->order_sn,
                    'external_order_id' => $gatewayData['external_order_id'],
                    'amount' => $order->actual_price,
                    'currency' => $gatewayData['currency'] ?? 'CNY',
                    'description' => $gatewayData['description'],
                    'trade_no' => $order->trade_no,
                    'status' => 'completed',
                    'metadata' => $gatewayData['metadata'] ?? [],
                    'timestamp' => time(),
                    'key' => env('PAYMENT_GATEWAY_SECRET', 'dujiaoka_gateway_secret_key')
                ]);
            }

        } catch (\Exception $e) {
            \Log::error('网关支付成功处理失败', [
                'order_sn' => $order->order_sn,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 发送通知到业务系统
     * 
     * @param string $notifyUrl
     * @param array $data
     * @return void
     */
    private function sendNotificationToBusinessSystem(string $notifyUrl, array $data)
    {
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $notifyUrl);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'User-Agent: Dujiaoka-Payment-Gateway/1.0'
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            \Log::info('业务系统通知发送', [
                'notify_url' => $notifyUrl,
                'payment_id' => $data['payment_id'],
                'response' => $response,
                'http_code' => $httpCode
            ]);

        } catch (\Exception $e) {
            \Log::error('业务系统通知发送失败', [
                'notify_url' => $notifyUrl,
                'payment_id' => $data['payment_id'] ?? '',
                'error' => $e->getMessage()
            ]);
        }
    }

}