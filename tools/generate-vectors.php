<?php
// tools/generate-vectors.php — 以后端实现为真源生成/校验测试向量，可重复执行（幂等）。
// 用法：php tools/generate-vectors.php <huanyu-backend 绝对路径>
$backend = rtrim($argv[1] ?? '', '/');
require $backend . '/addons/huanyu/library/MerchantAuth.php';
require $backend . '/addons/huanyu/service/Callback.php';

use addons\huanyu\library\MerchantAuth;

const API_SECRET = 'test-secret-0001';

// 等价性审计：反射调 Callback 私有签名，与 MerchantAuth 对比
function callbackSign(array $data, string $secret): string {
    $ref = new ReflectionMethod('\addons\huanyu\service\Callback', 'generateSignature');
    $ref->setAccessible(true);
    return $ref->invoke(null, $data, $secret);
}

function assertImplsAgree(array $params): void {
    $a = MerchantAuth::generateSignature($params, API_SECRET);
    $b = callbackSign($params, API_SECRET);
    if ($a !== $b) {
        fwrite(STDERR, "两实现不一致，中止！params=" . json_encode($params, JSON_UNESCAPED_UNICODE) . "\n");
        exit(1);
    }
}

// 等价性审计域边界：是否含数组参数（数组用例不做双实现对比，原因见 $out 内注释）
function hasArrayValue(array $params): bool {
    foreach ($params as $value) {
        if (is_array($value)) {
            return true;
        }
    }
    return false;
}

$signCases = [
    'basic-scalars' => ['api_key' => 'mk_test_001', 'timestamp' => '1756684800', 'nonce' => 'abcdefgh12345678',
        'order_type' => '1', 'payment_amount' => '100.00', 'merchant_order_no' => 'M20260831001'],
    'chinese-values' => ['api_key' => 'mk_test_001', 'timestamp' => '1756684800', 'nonce' => 'abcdefgh12345678',
        'remark' => '测试订单-加急', 'customer_name' => '张三'],
    'empty-and-null-skipped' => ['api_key' => 'mk_test_001', 'timestamp' => '1756684800', 'nonce' => 'abcdefgh12345678',
        'remark' => '', 'extra' => null, 'order_type' => '2'],
    'array-payment-method-unsorted-keys' => ['api_key' => 'mk_test_001', 'timestamp' => '1756684800',
        'nonce' => 'abcdefgh12345678', 'order_type' => '2', 'payment_amount' => '500.50',
        'payment_method' => ['real_name' => '李四', 'card_number' => '6217000000001234567', 'bank' => '中国银行', 'sub_bank' => '深圳分行']],
    'signature-field-stripped' => ['api_key' => 'mk_test_001', 'timestamp' => '1756684800', 'nonce' => 'abcdefgh12345678',
        'order_type' => '1', 'signature' => 'DEADBEEFShouldBeIgnored'],
    // Task 1 评审确认的坑点：数组值含 /，固化 PHP json_encode 将 / 转义为 \/ 的规范行为
    'array-value-with-slash' => ['api_key' => 'mk_test_001', 'timestamp' => '1756684800', 'nonce' => 'abcdefgh12345678',
        'order_type' => '2', 'payment_amount' => '300.00',
        'payment_method' => ['bank' => '工商银行', 'sub_bank' => 'http测试支行/分行', 'card_number' => '6217000000009999999', 'real_name' => '王五']],
];

$callbackCases = [
    'callback-full' => ['merchant_id' => '9', 'order_id' => '123456', 'order_no' => 'HY20260831000001',
        'merchant_order_no' => 'M20260831001', 'order_type' => '1', 'status' => 'completed',
        'cny_amount' => '100.00', 'merchant_amount' => '99.40', 'merchant_actual_amount' => '99.40',
        'service_amount' => '0.60', 'createtime' => '1756684800', 'updatetime' => '1756684900',
        'timestamp' => '1756684900', 'nonce' => 'zyxwvutsrqponmlk'],
    'callback-empty-merchant-order-no' => ['merchant_id' => '9', 'order_id' => '123457', 'order_no' => 'HY20260831000002',
        'merchant_order_no' => '', 'order_type' => '2', 'status' => 'completed',
        'cny_amount' => '500.50', 'merchant_amount' => '497.70', 'merchant_actual_amount' => '497.70',
        'service_amount' => '2.80', 'createtime' => '1756684800', 'updatetime' => '1756684900',
        'timestamp' => '1756684900', 'nonce' => 'zyxwvutsrqponmlk'],
];

$out = function (string $file, array $cases) use (&$out) {
    $data = ['api_secret' => API_SECRET, 'cases' => []];
    foreach ($cases as $id => $params) {
        // 等价性审计域 = 标量/回调域：Callback::generateSignature 无数组 JSON 化分支
        // （数组会被 stringify 成字面量 "Array"），而生产回调数据全标量，数组参数不在
        // Callback 可达域内；故数组用例不做双实现对比，仅以 MerchantAuth 真源固化行为。
        if (!hasArrayValue($params)) {
            assertImplsAgree($params); // 两后端实现必须一致才允许产出向量
        }
        $data['cases'][] = ['id' => $id, 'params' => $params,
            'expected_signature' => MerchantAuth::generateSignature($params, API_SECRET)];
    }
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    echo "写出 {$file}（" . count($cases) . " 例）\n";
};
chdir(__DIR__ . '/..');
$out('vectors/signature_vectors.json', $signCases);
$out('vectors/callback_vectors.json', $callbackCases);
echo "OK：标量/回调域两实现等价；数组行为以 MerchantAuth 真源固化\n";
