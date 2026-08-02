# 轻语模块原版 AI 网关设计

> 状态：已被 `2026-08-03-qingyu-client-capability-takeover-p0-design.md` 取代。原版服务不受本项目控制，本文中的“代理原版 AI”方案不进入实施。

## 1. 目标

`QingyuIpAgent` 轻语模块统一承接轻语桌面客户端的 AI 能力。

用户只登录 User 平台账号。具备有效轻语模块会员关系和有效 VIP 的用户，可以通过轻语模块调用原版轻语 AI 服务，不再登录或激活原版供应商个人账号。

调用链固定为：

```text
aigc-human-new
  -> user.qingyouai.com
  -> QingyuIpAgent 模块 AI 网关
  -> 原版轻语 AI 服务
```

原版供应商凭据只保存在服务器。桌面客户端、Renderer、本地日志和 User API 响应均不得获得供应商令牌、签名密钥或服务账号密码。

## 2. 范围

首个完整版本覆盖以下能力：

- 视频分享链接文案解析
- LLM 配置读取、连接状态和文案生成/改写
- 音频或视频 ASR 转写
- 声音克隆任务提交和状态查询
- 数字人任务提交和状态查询
- AI 请求日志、任务记录、使用量记录和运营查询

以下能力不属于本设计：

- 在 User 核心中实现轻语专属 AI 业务
- 向客户端下发原版供应商令牌
- 暴露可指定任意 URL、方法或请求头的通用反向代理
- 复用某个终端用户的个人供应商会话为其他用户服务；轻语模块统一使用的已激活平台服务账号不属于个人会话
- 修改原版桌面 Renderer 或原版 AI 算法
- 在第一阶段建立按次扣费规则；第一阶段只记录使用量并执行可配置限流

## 3. 所有权边界

### 3.1 User 宿主负责

User 核心继续提供稳定、与具体模块无关的宿主能力：

- User API 身份认证和令牌刷新
- 模块启停与模块执行上下文
- 模块会员归属校验
- VIP 权益查询
- 余额、流水和后续额度结算接口
- 审计日志接口
- 通知接口
- 模块请求租约、幂等和操作恢复基础设施

宿主不包含原版轻语供应商地址、供应商协议、请求映射和返回值兼容逻辑。

### 3.2 QingyuIpAgent 模块负责

轻语模块拥有完整 AI 业务边界：

- AI API 路由和能力权限
- VIP AI 权益判定策略
- 原版供应商适配器
- 供应商凭据使用和刷新
- 请求、响应和错误归一化
- 文件上传与临时媒体管理
- 异步任务提交、轮询、回调和所有权校验
- AI 使用量与供应商调用日志
- 桌面 IPC 兼容契约
- 管理后台中的供应商状态、能力开关和任务查询

模块被禁用、停用、吊销或运行资格失效时，所有轻语 AI API 必须立即拒绝请求。

### 3.3 模块隔离规则

- 所有用户请求都必须带有 `module:qingyu_ip_agent` 能力。
- 所有数据记录都必须包含 `module = qingyu_ip_agent`。
- 模块只能通过宿主 Gateway/Contract 访问会员、VIP、余额、审计和通知能力。
- 模块不得直接修改其他模块的数据。
- 第三方模块不能读取轻语供应商配置、任务和使用量。

## 4. API 契约

所有接口位于：

```text
/api/v1/modules/qingyu-ip-agent/ai
```

### 4.1 同步能力

```text
GET  /config
POST /video/parse
POST /llm/chat
POST /llm/rewrite
```

`/config` 只返回客户端可见的能力状态、限制和模型显示信息，不返回供应商地址或凭据。

现有接口继续兼容：

```text
POST /api/v1/modules/qingyu-ip-agent/content/parse
POST /api/v1/modules/qingyu-ip-agent/content/rewrite
```

兼容接口必须调用同一 AI Gateway Service，不保留第二套实现。

### 4.2 文件上传

```text
POST /uploads
DELETE /uploads/{uploadId}
```

上传记录必须绑定当前用户和轻语模块。仅允许配置中的 MIME 类型、扩展名和最大文件大小。临时文件采用随机对象键，不使用原始文件名作为存储路径，并在到期后自动清理。

### 4.3 异步能力

```text
POST /asr/tasks
GET  /asr/tasks/{taskId}
POST /voice/tasks
GET  /voice/tasks/{taskId}
POST /digital-human/tasks
GET  /digital-human/tasks/{taskId}
```

任务创建接口接受模块上传 ID 或经过验证的平台媒体 URL。任务查询只接受轻语模块生成的本地任务 ID，不向客户端暴露供应商查询凭据。

### 4.4 LLM 流式输出

```text
POST /llm/stream
```

后端使用 SSE 输出统一事件：

```text
meta -> delta* -> completed
```

失败时输出 `error` 并结束连接。桌面主进程将 SSE 事件转换为原 Renderer 已使用的 `llm:*` IPC 分片事件。

## 5. 认证与 VIP 权限

每个 AI 路由按顺序执行：

1. `auth:sanctum`
2. `api.active`
3. `api.module_active`
4. `api.module_context`
5. 对应的 `api.ability:ai:*`
6. 有效轻语模块会员关系
7. 有效 VIP 权益
8. 能力开关、频率和并发限制

User API 令牌新增能力：

```text
ai:config:read
ai:video:parse
ai:llm:use
ai:asr:use
ai:voice:use
ai:digital-human:use
```

第一阶段权益规则：

- 有效轻语模块会员且 VIP 有效：允许调用已启用能力。
- VIP 过期或不存在：返回 `403 vip_required`。
- 模块会员关系失效：返回 `403 module_membership_required`。
- 模块停用：返回 `403 module_inactive`。
- 超出频率或并发：返回 `429 ai_rate_limited`。

VIP 到期时间以 User 宿主权益快照为事实来源。客户端缓存只能用于显示，不能用于授权。

## 6. 原版供应商适配

模块定义稳定接口，例如：

```text
QingyuAiProvider
  getConfig()
  parseVideo()
  chat()
  streamChat()
  createAsrTask()
  createVoiceTask()
  createDigitalHumanTask()
  getTask()
```

`OriginalQingyuProvider` 是首个实现。控制器只调用模块 AI 服务，不直接使用 Laravel HTTP Client。

供应商配置从服务器环境变量或加密配置存储读取：

- HTTPS 基础地址
- 已激活的平台服务账号或服务令牌
- 登录/刷新配置
- 连接与响应超时
- 能力开关
- 允许的上传和结果域名

供应商主机使用精确允许列表。模块不得接受客户端传入供应商 URL、Authorization、Cookie、签名或任意请求头。

凭据刷新由服务器单飞锁保护，防止并发请求重复登录。刷新失败时保持 User 登录有效，只将当前 AI 请求返回为供应商不可用。

## 7. 请求和任务数据

复用现有 `module_api_request` 记录请求租约、幂等键和请求状态。在此基础上增加轻语模块自有数据表：

### 7.1 `qingyu_ai_task`

- 本地任务 ID
- User 用户 ID
- 模块名
- 能力类型
- 供应商标识
- 供应商任务 ID
- 状态
- 输入对象引用和输入摘要
- 结果对象引用
- 标准错误码
- 供应商状态码
- 创建、开始、完成和过期时间

### 7.2 `qingyu_ai_usage`

- 请求 ID
- User 用户 ID
- 模块名
- 能力类型
- 成功/失败
- 输入大小、音视频秒数、Token 或任务数量
- 调用耗时
- 供应商计量值和成本快照
- 发生时间

提示词、文案正文、音频、视频和人脸素材默认不写入日志。日志只保存长度、哈希、对象 ID 和必要的故障分类。

## 8. 异步任务状态机

统一状态：

```text
pending -> submitted -> processing -> succeeded
                                 \-> failed
                                 \-> cancelled
                                 \-> expired
```

- 创建任务必须携带 `Idempotency-Key`。
- 相同用户、能力和幂等键返回同一任务。
- 供应商支持 Webhook 时校验签名后更新状态。
- 供应商不支持 Webhook 时使用队列任务按退避策略轮询。
- 查询和下载结果必须再次校验 `user_id` 与模块归属。
- 供应商任务 ID 不能作为 User API 的资源授权依据。

## 9. 错误契约

API 使用稳定错误码，不把供应商原始错误直接展示给用户：

```text
vip_required
module_membership_required
module_inactive
ability_denied
ai_rate_limited
ai_upload_invalid
ai_provider_unavailable
ai_provider_timeout
ai_provider_rejected
ai_task_not_found
ai_task_forbidden
ai_result_unavailable
```

供应商 `401/403` 归一化为 `ai_provider_unavailable` 或 `ai_provider_rejected`，不得转换成“User 登录已过期”。只有 User API 自身令牌失效时才返回 `token_expired`。

## 10. 桌面客户端对接

`aigc-human-new` 继续保持原 Renderer 不变。`desktop-shell` 只在主进程中重映射以下 IPC：

- `video-parser:*`
- `cloud:get-ai-config`
- `llm:*`
- ASR 相关 IPC
- `cloud:voice-clone`
- `cloud:voice-clone-v2`
- `cloud:digital-human`

主进程使用加密保存的 User Bearer 会话调用轻语模块 API，并把模块响应归一化为原 Renderer 契约。

每项能力独立受功能开关控制。完成切换并通过真实验证后，移除该能力对原版客户端供应商登录态的依赖。全部能力完成迁移后，删除原版供应商账号桥接和本地供应商令牌存储。

## 11. 安全要求

- 不提供通用 HTTP 代理。
- 所有目标主机、重定向主机和结果下载主机执行允许列表校验。
- 上传执行 MIME、扩展名、大小和内容检查。
- 管理后台不回显完整供应商凭据。
- 日志屏蔽 Authorization、Cookie、Token、签名、激活码和对象签名参数。
- User 用户只能访问自己的上传、任务和结果。
- 任务创建采用幂等键和并发限制，避免重复计费。
- 供应商失败使用短时熔断，避免故障期间放大请求。
- 管理员变更供应商配置、能力开关和限流策略必须写审计日志。

## 12. 运营后台

轻语模块后台增加：

- AI 服务总览：可用性、延迟、错误率和进行中任务
- 能力开关：文案、LLM、ASR、声音、数字人
- 供应商状态：凭据配置状态、最近成功时间和最近错误分类
- AI 任务：按用户、能力、状态和时间查询
- AI 使用量：调用次数、成功率、耗时和供应商计量
- 限流策略：按用户、能力和时间窗口配置

后台只能显示凭据是否已配置和脱敏标识，不显示明文。

## 13. 分阶段迁移

### P0：网关基础与低成本同步能力

- 建立 Provider 接口、凭据管理、VIP Gate、错误契约和使用量记录。
- 接管 `/config`、文案解析和 LLM 非流式接口。
- 让现有 `/content/parse`、`/content/rewrite` 委托新网关。
- 桌面端逐项切换并停止对应原版账号桥接。

### P1：流式与文件能力

- 接管 LLM SSE。
- 建立安全上传和临时对象生命周期。
- 接管 ASR 任务。

### P2：高成本异步任务

- 接管声音克隆。
- 接管数字人生成。
- 建立任务轮询/Webhook、并发限制和运营视图。

### P3：完整切断旧授权依赖

- 删除客户端原版供应商登录、令牌恢复和过期事件桥接。
- 验证所有 AI 网络流量只经 User 轻语模块发起。
- 保留按能力回滚开关，不保留共享供应商令牌客户端方案。

## 14. 测试策略

### 后端自动化

- 未登录、能力不足、模块停用、会员缺失、VIP 过期。
- 有效 VIP 的每项能力成功路径。
- 供应商超时、拒绝、无效 JSON、空结果和凭据刷新。
- SSRF、非法重定向、非法上传和超大响应。
- 幂等任务、重复提交、并发限制和资源归属隔离。
- 日志与 API 响应不包含供应商凭据和用户敏感正文。
- 模块停用后所有 AI 路由立即拒绝。

### 桌面自动化

- 每个原 IPC 映射到唯一轻语模块接口。
- Renderer 参数和返回值兼容。
- User Token 刷新后请求只重试一次。
- User 会话有效时，供应商错误不触发退出登录。
- 客户端代码和日志不包含供应商令牌。
- 非轻语 IPC 保持原行为。

### 真实验证

先使用模拟供应商完成全套回归，再依次执行：

1. 一次真实文案解析。
2. 一次真实 LLM 请求。
3. 一次短音频 ASR。
4. 经明确确认后各执行一次声音克隆和数字人任务。

高成本任务不得为了重试界面问题而重复提交。

## 15. 部署与回滚

部署顺序：

1. 数据库迁移和后台代码，所有 AI 能力开关保持关闭。
2. 配置并验证原版供应商服务凭据。
3. 启用文案和 LLM，完成健康检查与真实请求。
4. 发布桌面 runtime，逐项启用客户端映射。
5. 启用 ASR、声音和数字人。
6. 观察错误率、延迟和供应商用量后切断旧供应商登录桥接。

回滚时只关闭对应模块能力开关并回退桌面 runtime。User 登录、VIP、余额和其他模块继续可用。数据库任务和使用量记录保留，不执行破坏性回滚。

## 16. 验收标准

- 有效轻语 VIP 用户登录后可使用已启用的原版 AI 能力。
- 用户无需原版供应商个人账号、激活码或供应商登录。
- 非 VIP、非轻语会员和模块停用状态均被服务器拒绝。
- 客户端不保存或接收供应商凭据。
- 所有 AI 请求具有请求 ID、使用量记录和脱敏日志。
- 异步任务只能由创建用户访问。
- 原版供应商故障不使 User 会话退出。
- 模块可独立升级、停用和回滚，不修改 User 核心业务实现。
