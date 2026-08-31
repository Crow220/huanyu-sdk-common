# huanyu-sdk-common

- 本仓为四语言 SDK（PHP / Java / Go / Python）的共享规格与测试向量真源，**不发布包**。
- 引用方式为 **git submodule**（各 SDK 仓锚定 revision，CI 拉同一份向量跑参数化测试）。
- 规格变更流程：更新本仓 spec + 向量 → 各 SDK 仓 bump submodule → 四仓测试同步红/绿。

## 目录

| 路径 | 内容 |
|---|---|
| `spec/signature.md` | 签名算法规范（v1，语言无关） |
| `spec/api.md` | 商户 API 规格（v1，6 端点 + 回调） |
| `vectors/` | 测试向量（`signature_vectors.json` / `callback_vectors.json`，后续任务生成） |
| `tools/` | 向量生成脚本（`generate-vectors.php`，调后端参考实现；由向量生成任务创建） |
| `docs/specs/` | 设计文档（[2026-08-31-merchant-sdk-design.md](./docs/specs/2026-08-31-merchant-sdk-design.md)） |
