// 简单的环境变量配置测试
console.log('=== 支付网关配置测试 ===');

// 模拟Laravel端的env()函数
function env(key, defaultValue = null) {
  // 这里模拟环境变量，实际使用时从.env文件读取
  const envVars = {
    'PAYMENT_GATEWAY_SECRET': process.env.PAYMENT_GATEWAY_SECRET || 'dujiaoka_gateway_secret_key'
  };

  return envVars[key] || defaultValue;
}

// 测试密钥获取
const secret = env('PAYMENT_GATEWAY_SECRET', 'dujiaoka_gateway_secret_key');
console.log('获取到的密钥:', secret);

if (secret === 'dujiaoka_gateway_secret_key') {
  console.log('⚠️  使用默认密钥，建议生产环境配置自定义密钥');
} else {
  console.log('✅ 使用自定义密钥');
}

// 测试签名生成
const crypto = require('crypto');
function generateSignature(paymentId, externalOrderId, amount, secret) {
  const data = paymentId + externalOrderId + parseFloat(amount).toFixed(2) + secret;
  return crypto.createHash('sha256').update(data).digest('hex');
}

const testSignature = generateSignature('PAY_TEST123', 'EXT_ORDER_456', 29.90, secret);
console.log('测试签名:', testSignature);

console.log('\n=== 配置说明 ===');
console.log('1. 在 .env 文件中添加: PAYMENT_GATEWAY_SECRET=your_secret_key');
console.log('2. 如果不配置，会使用默认值');
console.log('3. 密钥会用于生成和验证签名');
console.log('4. 确保Laravel和Next.js端使用相同的密钥');

console.log('\n=== 测试完成 ===');
