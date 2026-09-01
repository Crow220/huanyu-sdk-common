# 商户 API 规格（v1）

基础 URL：https://api.pisces-pay.cn/addons/huanyu（联调可换测试环境）
通用参数（所有请求）：api_key / timestamp(秒，±300 秒有效窗口，服务端校验) / nonce(16位；时间窗内一次性，重复即拒绝"重复的请求，请更换 nonce 后重试") / signature
响应信封：{code, msg, data, time}。code 为数字（1=成功）；time **恒为字符串形式的秒级时间戳**（如 `"1788224754"`，TP5 服务端行为，见 `huanyu-backend/application/common/controller/Api.php` 的 result() 经 input() 输出），SDK 解析须同时兼容字符串形态（数字形态可作宽容兜底）；失败信封的 data 为 PHP 空数组序列化的 `[]`（非对象），SDK 不得因 data 非对象而解签失败。

签名算法见 [signature.md](./signature.md)；本规格为四个官方 SDK（PHP / Java / Go / Python）端点方法的唯一依据，参数名与语义逐字对齐后端 `huanyu-backend/addons/huanyu/controller/Merchant.php`。

## POST /merchant/createOrder

创建订单（唯一下单端点，SDK 对外只暴露 `createOrder`）。

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| order_type | int | 必填 | 1=买入 2=卖出 |
| cny_amount | number | 必填 | CNY 金额，大于 0 |
| payment_method | object | 卖单必填 | `{bank, sub_bank, card_number, real_name}` |
| customer_name | string | 视配置 | 三要素之一，是否必填由商户 identity_required 配置决定 |
| id_card | string | 视配置 | 三要素之一，同上 |
| mobile | string | 视配置 | 三要素之一，同上 |
| remark | string | 选填 | 备注 |
| merchant_order_no | string | 选填 | 限长255；**商户内唯一**（同商户重复单号建单返回错误，不同商户间可重复） |
| callback_url | string | 选填 | 本单回调地址（http/https 合法 URL，限长255）；未设置时该订单回调发到商户默认回调地址。订单详情回显该字段 |

成功 data 含 result_status: `success` | `pending_identity`（后者附 identity_url，用于引导补全客户身份信息）。

## POST /merchant/createOrderSimple —— deprecated 别名，行为同上，SDK 不暴露

保留兼容存量对接方的别名端点，与 createOrder 完全同一逻辑；仅在此记录，四语言 SDK 均不封装该方法。

## GET /merchant/orderListApi

分页查询订单列表。

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| page | int | 选填 | 页码，默认 1 |
| limit | int | 选填 | 每页条数，默认 20 |
| status | string | 选填 | 订单状态，支持逗号分隔多状态 |
| order_type | int | 选填 | 1=买入 2=卖出 |
| start_time | string | 选填 | 开始日期（Y-m-d） |
| end_time | string | 选填 | 结束日期（Y-m-d） |
| order_no | string | 选填 | 系统订单号 |
| merchant_order_no | string | 选填 | 商户单号（模糊） |
| min_cny_amount | number | 选填 | 最小 CNY 金额 |
| max_cny_amount | number | 选填 | 最大 CNY 金额 |

响应 data：`{list, total, page, limit, status_counts}`。

## GET /merchant/orderDetailApi —— id / order_no / merchant_order_no 三选一

查询订单详情。

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| id | int | 三选一 | 订单 ID |
| order_no | string | 三选一 | 系统订单号 |
| merchant_order_no | string | 三选一 | 商户单号 |

## POST /merchant/uploadPaymentProof —— order_no 必填，proof_image_url 必填

上传支付凭证。

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| order_no | string | 必填 | 订单编号 |
| proof_image_url | string | 必填 | 支付凭证图片 URL |

## POST /merchant/confirmPayment —— order_no 必填，payment_proof 选填

确认付款（卖单场景：商户向客户付款后确认）。

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| order_no | string | 必填 | 订单编号 |
| payment_proof | string | 选填 | 付款凭证 |

## 回调通知（平台 → 商户 callback_url）

订单完成时平台向商户配置的 callback_url 发送 `application/x-www-form-urlencoded` POST，字段：

merchant_id, order_id, order_no, merchant_order_no, order_type, status(=completed),
cny_amount, merchant_amount, merchant_actual_amount, service_amount,
createtime, updatetime, timestamp, nonce, signature。

- 仅订单 completed 时通知；
- 成功判定 = HTTP 200 且响应体含 "success"/"SUCCESS"；
- 重试间隔 5/30/120/600s 共 5 次（首次 + 4 次重试）。

验签方式见 [signature.md](./signature.md) 的"回调验签"一节。
