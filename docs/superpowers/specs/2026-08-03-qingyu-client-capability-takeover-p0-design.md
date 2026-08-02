# 轻语客户端能力接管 P0 设计

## 1. 决策

`QingyuIpAgent` 轻语模块逐项接管 `aigc-human-new` 的会员专属能力。

User 平台继续拥有注册、登录、模块会员、VIP、激活码和会话。客户端只使用 User 登录态判断用户是否可以使用轻语能力。

原版 `dapi.qingyu.club` 不受本项目控制，其账号激活校验也不能由客户端修改。P0 不再依赖原版账号、原版激活状态或原版供应商令牌。

P0 只交付已经存在真实实现、可以自动测试和真实验证的两项能力：

- 视频分享链接文案解析
- 平台管理的 LLM 文案改写

ASR、声音克隆和数字人分别进入后续设计，P0 不返回示例音频、原素材视频或伪造成功结果。

## 2. 当前证据

客户端历史上使用过以下原版服务路径：

```text
/api/ai/config
/api/video-parser/parse-content
/api/oss/upload-token
/api/asr/transcribe/raw
/api/voice-clone-v2/task
/api/digital-human/task
```

当前捕获的原版文案解析请求只有 `401/403`，没有成功记录。此前可用的文案解析实际来自：

```text
POST /api/v1/modules/qingyu-ip-agent/content/parse
```

轻语模块目前已有：

- `VideoParserService`：校验 User VIP，解析抖音、快手和小红书分享文本/页面元数据，并限制重定向和目标主机。
- `RewriteService`：通过服务器配置的 OpenAI 兼容 DashScope 服务执行真实云端改写，不向客户端暴露 API Key。
- `module_api_request`：请求 ID、幂等和配额租约基础设施。
- `AuditLogService`：记录脱敏后的模块操作日志。

## 3. P0 范围

### 3.1 文案解析

接管以下 IPC：

```text
video-parser:parse
video-parser:parse-and-extract
```

统一调用：

```text
POST /api/v1/modules/qingyu-ip-agent/content/parse
```

`video-parser:extract-url` 和 `video-parser:validate-url` 保持原客户端本地行为；它们不调用云端供应商，不需要接管。

### 3.2 平台 AI 配置

接管：

```text
cloud:get-ai-config
llm:test-connection-with-config
```

客户端只得到平台托管标记、能力状态和显示模型名：

```text
provider: platform-managed
apiKey: member-session
apiUrl: takeover://member-ai
```

这些字段只用于兼容原 Renderer，不代表真实供应商地址或密钥。

新增受保护状态接口：

```text
GET /api/v1/modules/qingyu-ip-agent/content/status
```

状态接口验证模块、会员、VIP、LLM 配置完整性和允许主机，但不提交生成请求。它返回 `parse_available`、`rewrite_available` 和公开模型显示名。

### 3.3 文案改写

接管原工作流中用于“改写文案”的 LLM 请求。桌面主进程从原 IPC 参数中提取待改写正文并调用：

```text
POST /api/v1/modules/qingyu-ip-agent/content/rewrite
```

P0 只接管明确属于文案改写的调用，不把 IP 大脑、选题、营销文案、法律审核或带工具调用的通用 LLM 请求错误路由到 `RewriteService`。

需要接管的 IPC 表面包括：

```text
llm:send-message
llm:send-stream-message
```

是否属于改写请求由纯函数根据工作流模式、系统提示和载荷字段判定。无法明确判定时返回稳定的“该平台能力尚未接管”错误，不调用原版服务，也不生成本地假文案。

流式 IPC 在 P0 中可以把一次服务器非流式结果转换为一个 `delta` 和一个 `complete` 事件，保持 Renderer 协议兼容；P1 再建设真正的服务端流式输出。

## 4. 权限边界

所有 P0 后端接口必须执行：

1. `auth:sanctum`
2. `api.active`
3. `api.module_active`
4. `api.module_context`
5. 对应的能力校验
6. 有效 `qingyu_ip_agent` 模块会员关系
7. 有效 User VIP

继续使用现有能力：

```text
content:parse
content:rewrite
module:qingyu_ip_agent
```

`content/status` 使用 `content:rewrite`，不新增可扩大访问范围的通用 AI 能力。

非 VIP 返回 `403 vip_required` 或现有兼容错误信封。User 会话过期与模块业务拒绝必须保持可区分，供应商或 LLM 配置错误不能触发客户端退出登录。

## 5. 后端设计

### 5.1 复用现有服务

- `ApiController::parseContent` 继续委托 `ClientApiService` 和 `VideoParserService`。
- `ApiController::rewrite` 继续委托 `ClientApiService` 和 `RewriteService`。
- 不复制第二套解析或改写实现。

### 5.2 增加能力状态

新增模块内 `CapabilityStatusService`，只读取：

- 模块启用状态
- 当前 User 的模块会员和 VIP 状态
- `qingyu_ip_agent.llm` 配置是否完整
- LLM 基础地址是否为 HTTPS 且位于允许主机列表
- 公开模型显示名

状态服务不得返回 API Key、完整供应商 URL 或环境变量值。

### 5.3 文案解析强化

保留当前 SSRF 防护并补齐：

- DNS 解析后的私网/环回地址拒绝
- 每次重定向重新校验主机和解析地址
- 响应体大小上限
- 总超时和连接超时
- 只接受 HTML/JSON 文本响应
- 平台页面解析失败时返回稳定业务错误

### 5.4 改写服务强化

- API Key 只从服务器配置读取。
- 供应商主机必须在精确允许列表。
- 请求正文长度继续限制为 12000 字符。
- 日志只记录字符数、请求 ID、耗时和错误分类。
- 不记录原文、改写结果或 Authorization。
- 对连接失败、超时、非 2xx、空结果分别返回稳定错误。

## 6. 桌面设计

### 6.1 IPC 拦截原则

- 只拦截明确列入 P0 的 IPC。
- 原 Renderer、页面和 preload 不修改。
- 非 P0 IPC 不被意外重写。
- 所有 User 请求使用主进程加密保存的 Bearer 会话。
- Renderer 传入的 token、Authorization 或供应商配置全部忽略。

### 6.2 响应兼容

文案解析至少提供：

```text
content
extractedContent
videoInfo
```

改写至少提供：

```text
content
text
rewrittenContent
```

原 IPC 期望流式事件时，主进程发送原 Renderer 已识别的 chunk/complete 事件，并确保一次请求只完成一次。

### 6.3 错误显示

错误必须区分：

```text
请先登录
会员权限不足
链接无效或平台未返回文案
平台改写服务未配置
平台改写服务暂时不可用
该 AI 能力尚未接管
```

模块业务错误不得显示成“登录已过期或权限不足”。

## 7. 明确禁止

- 不再调用原版 `/api/video-parser/parse-content` 作为 P0 主路径。
- 不把原版 `401/403` 转换为成功。
- 不使用模块示例音频作为声音生成结果。
- 不把头像原视频作为数字人生成结果。
- 不使用固定本地模板伪造 LLM 改写成功。
- 不向客户端下发 DashScope 或其他供应商密钥。
- 不在 P0 顺带实现 ASR、声音或数字人。

## 8. 测试

### 8.1 后端 RED/GREEN

- 未登录、无能力、非模块会员、VIP 过期。
- 分享文本直接提取。
- 支持平台页面元数据提取。
- 私网、环回、恶意重定向和超大响应拒绝。
- 相同请求 ID 幂等复用，不同载荷冲突。
- 配额耗尽返回 `429`。
- DashScope 成功、超时、拒绝、空结果和未配置。
- 状态接口不泄露 URL、API Key 或敏感配置。
- 审计日志不包含输入正文和供应商密钥。

### 8.2 桌面 RED/GREEN

- `video-parser:parse` 和 `parse-and-extract` 只调用模块接口。
- 文案解析响应兼容 Renderer。
- `cloud:get-ai-config` 只为有效 User VIP 返回平台托管配置。
- 只有明确的改写调用进入模块 `content/rewrite`。
- 非改写 LLM 调用返回未接管，不生成假结果。
- User Token 刷新最多一次，请求 ID 在重试中保持不变。
- P0 之外的声音、数字人和 ASR IPC 不被改写。
- 日志不包含 User Token、供应商密钥、提示词或正文。

## 9. 发布顺序

1. 后端测试与模块实现。
2. 部署后端，保留客户端旧版本。
3. 使用测试 VIP 验证 `content/status`、`content/parse` 和 `content/rewrite`。
4. 提交并安装桌面 runtime。
5. 重启 `aigc-human-new`，验证 User 会话恢复。
6. 使用指定抖音链接执行一次真实文案解析。
7. 使用短文本执行一次真实云端改写。
8. 检查日志、请求 ID、VIP 拒绝和退出重登。

回滚时先回退桌面 runtime，再关闭新增状态路由。现有 User 账号、VIP、模块会员和请求日志全部保留。

## 10. 验收标准

- 有效 User VIP 能提取指定视频文案。
- 有效 User VIP 能得到真实云端改写结果。
- 用户无需原版账号或原版激活状态。
- 原版解析和 LLM `401/403` 不再阻塞 P0 工作流。
- 非 VIP 用户无法使用两项能力。
- 没有示例素材或固定模板被报告为生成结果。
- P0 之外的能力保持明确未接管状态。
- 后端、客户端自动化测试和真实验证均通过后才部署为默认路径。
