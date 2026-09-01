# 签名算法规范（v1）

本文件是PISCES商户平台签名算法的**语言无关真源**。四个官方 SDK（PHP / Java / Go / Python）的 `Signature` 实现必须与本规范逐步对应——下文算法步骤的**编号即契约**：SDK 实现注释、测试向量说明均引用这些编号，调整步骤或编号必须四仓同步并更新测试向量。

- 提炼来源（权威参考实现）：`huanyu-backend/addons/huanyu/library/MerchantAuth.php`（请求签名/验签）、`huanyu-backend/addons/huanyu/service/Callback.php`（回调签名）。
- 期望值真源：`vectors/signature_vectors.json`（请求签名）与 `vectors/callback_vectors.json`（回调验签），由 `tools/generate-vectors.php` 直接调用后端参考实现生成。本文示例仅为说明用途，若与向量冲突，以向量为准。

## 请求签名（商户 → 平台）

输入：全部请求参数（含通用参数）+ api_secret。

1. 移除 `signature` 字段本身（若存在）。
2. 值为数组/对象的参数：JSON 序列化为字符串。要求：
   - 键按**插入顺序**输出（不按键排序）；
   - 分隔符无空格（`{"a":1}` 而非 `{"a": 1}`）；
   - 非 ASCII 字符（中文等）不转义为 \uXXXX；
   - 转义集规范：正斜杠 `/` 输出为 `\/`；`< > &` 输出原始字符（不做 HTML 转义）；引号与反斜杠按 JSON 标准转义；U+2028（行分隔符）与 U+2029（段分隔符）输出为 `\u2028`/`\u2029`（PHP json_encode 固有行为，即使传 JSON_UNESCAPED_UNICODE 也不放行——这类字符常见于粘贴的收款人名等合法输入）；
   - 数字建议以字符串形式传递（与 HTTP form 传输后的真实形态一致，规避各语言浮点表示差异）。
3. 顶层按键名升序排序（ASCII 序）。
4. 拼接：跳过值为空字符串与 null 的参数，其余拼 `key=value&`，去掉末尾 `&`。
5. 追加 `&api_secret=<SECRET>`。
6. 取 MD5 并转大写。

### 拼接示例

以下输入（含一个数组参数、一个空字符串参数）：

| 参数 | 值 |
|---|---|
| api_key | `mk_test_123` |
| timestamp | `1756617600` |
| nonce | `Ab3dEf7hIj9kLm2n` |
| order_type | `2` |
| cny_amount | `100.50` |
| payment_method | `{"bank":"中国银行","sub_bank":"深圳分行","card_number":"6222021234567890","real_name":"张三"}` |
| remark | `""`（空字符串，**跳过不参与签名**） |
| api_secret | `sk_test_456` |

待签名字符串（按键名 ASCII 升序，`remark` 被跳过）：

```text
api_key=mk_test_123&cny_amount=100.50&nonce=Ab3dEf7hIj9kLm2n&order_type=2&payment_method={"bank":"中国银行","sub_bank":"深圳分行","card_number":"6222021234567890","real_name":"张三"}&timestamp=1756617600&api_secret=sk_test_456
```

签名：`09F7AD2E01E729A28E88E39D58C0B74C`

注意 `cny_amount` 以字符串 `100.50` 参与——若以浮点数传入，部分语言会序列化成 `100.5`，导致签名不一致（见第 2 步最后一条要求）。拼接一律使用**原始值**，不做 URL 编码（HTTP 传输层的编码行为不影响签名计算）。

## 回调验签（平台 → 商户）

回调数据（全标量）用同一算法验签。两份后端实现的历史差异均属不可达边界：全部值为空时拼串差一个 `&`（回调数据必含非空字段，不可达）；Callback 版无数组 JSON 化分支（数组参数不在回调数据中，同样不可达）。SDK 单一实现（带数组 JSON 化）在请求签名与回调验签两个可达域内均与两份后端实现一致（由测试向量锁定）。

验签流程：从 `application/x-www-form-urlencoded` 请求体解析出全部键值对（含 `signature`），按上文第 1–6 步重新计算签名，与收到的 `signature` 比对（建议恒时比较），一致即通过。

## 跨语言实现注意（二期 Java/Go/Python 必读）

- Python：`json.dumps(v, ensure_ascii=False, separators=(',', ':'))`；dict 3.7+ 保插入顺序；默认序列化不转义 `/`，需显式将 `/` 替换为 `\/`。
- Go：`encoding/json` 对 map 按键排序输出，必须自写保序序列化（结构体字段顺序或有序键切片）；且默认把 `< > &` 转义为 `\u003c` 等，必须 `enc.SetEscapeHTML(false)`，同时显式将 `/` 替换为 `\/`。手写序列化器需特判 U+2028/U+2029 的 UTF-8 三字节形态 `E2 80 A8`/`E2 80 A9`，输出 `\u2028`/`\u2029`（其余 `E2` 开头的合法序列不受影响，检测时须确认后两字节）。
- Java：用 `LinkedHashMap` + Jackson（`JsonWriteFeature.ESCAPE_NON_ASCII` 禁用）；默认序列化不转义 `/`，需显式将 `/` 替换为 `\/`；Jackson 默认也不转义 U+2028/U+2029，需在序列化输出上替换为 `\u2028`/`\u2029` 字面（替换安全：Jackson 输出中不存在这两类字符的转义字面形态）。Python 的 `json.dumps` 同样默认不转义，处理方式同 Java（在输出上替换）。
- 排序比较是字节/ASCII 序，不是 locale 序。
- PHP 参考实现的 `ksort` 为默认 `SORT_REGULAR`：纯数字键会被转为 int 按数值比较而非字节序；后端同此行为，可达参数域内等价，移植时保持与后端相同的排序语义即可。

## 已知限制

- **数组参数的叶子值不得为 null**。表单线路（`application/x-www-form-urlencoded` 的括号记法）无法承载 null——空值键 `payment_method[sub_bank]=` 会被服务端 `parse_str` 解析为空字符串，而签名时该叶子 JSON 化为 `null` 形态，两条路径无法对齐，验签必败。四个官方 SDK（PHP / Java / Go / Python）在此行为一致；商户对"未填"的叶子字段应传空字符串替代 null——嵌套空串在签名 JSON 化时原样保留（`"sub_bank":""`），上行线路也能以空值键形态到达，服务端重嵌套后两者对齐。顶层参数的 null / 空串按第 4 步跳过、不参与签名与上行，不受此限制。
