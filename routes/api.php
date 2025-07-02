<?php

use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});

// 支付网关接口（纯支付处理）
Route::group(['prefix' => 'gateway', 'namespace' => 'Api'], function () {
    // 创建支付订单
    Route::post('/create-payment', 'PaymentGatewayController@createPayment');
    
    // 查询支付状态
    Route::get('/payment-status', 'PaymentGatewayController@getPaymentStatus');
    
    // 获取支付订单列表
    Route::get('/payments', 'PaymentGatewayController@getPayments');
});

// CORS预检请求支持
Route::options('{any}', function () {
    return response('', 200)
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
})->where('any', '.*');
