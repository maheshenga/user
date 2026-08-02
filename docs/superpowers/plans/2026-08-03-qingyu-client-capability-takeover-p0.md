# 轻语客户端能力接管 P0 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans inline. This task must not dispatch subagents.

**Goal:** 让 `aigc-human-new` 的文案解析、平台 AI 配置检查和明确文案改写只通过 User 的 `QingyuIpAgent` 模块执行。

**Architecture:** 后端继续复用 `VideoParserService` 与 `RewriteService`，新增只返回公开能力状态的服务，并通过现有 Sanctum、模块会员、VIP、能力和请求租约链路暴露。桌面主进程保持 Renderer/preload 契约不变，将明确列入 P0 的 IPC 直接映射到模块 API；不再先调用原版文案接口，非改写 LLM 请求明确拒绝。

**Tech Stack:** Laravel 13/PHP 8.3、Sanctum、Laravel HTTP Client、Electron CommonJS、Node `node:test`。

## Global Constraints

- 唯一安装客户端是 `E:\code\轻语智能体\aigc-human-new`。
- 不修改 Renderer、preload、会员/VIP/激活码数据模型。
- 不向客户端返回供应商 URL、API Key、Authorization 或提示词正文日志。
- 不实现 ASR、声音克隆或数字人；这些能力保持原行为或明确未接管。
- 不暂存后端已有的七个保护文件，也不暂存客户端测试报告。
- 每项生产代码必须先有失败测试，再做最小实现。

---

### Task 1: 后端公开能力状态与稳定改写错误

**Files:**
- Create: `modules/QingyuIpAgent/src/Exceptions/ContentCapabilityException.php`
- Create: `modules/QingyuIpAgent/src/Services/CapabilityStatusService.php`
- Modify: `modules/QingyuIpAgent/src/Services/RewriteService.php`
- Modify: `modules/QingyuIpAgent/src/Controllers/ApiController.php`
- Modify: `modules/QingyuIpAgent/routes/api.php`
- Test: `tests/Feature/Modules/QingyuIpAgentCapabilityP0Test.php`

**Interfaces:**
- `CapabilityStatusService::forUser(UserAccount $user): array`
- `RewriteService::publicStatus(): array{available: bool, provider: string, model: string}`
- `GET /api/v1/modules/qingyu-ip-agent/content/status`
- `ContentCapabilityException::__construct(string $message, int $httpStatus, string $errorCode)`

- [ ] **Step 1: 写后端 RED 测试**

覆盖：未登录 `401`、缺少 `content:rewrite` 能力 `403`、VIP 过期 `403 vip_required`、有效 VIP 状态成功且响应不含 URL/API Key、配置缺失时 `rewrite_available=false`、改写超时/拒绝/空结果返回稳定错误码、审计不含正文和密钥。

- [ ] **Step 2: 运行 RED**

```powershell
php artisan test --filter=QingyuIpAgentCapabilityP0Test
```

预期：状态路由、状态服务和稳定错误码尚不存在而失败。

- [ ] **Step 3: 实现最小后端能力**

状态成功数据固定为：

```php
[
    'provider' => 'platform-managed',
    'parse_available' => true,
    'rewrite_available' => $rewriteStatus['available'],
    'model' => $rewriteStatus['model'],
]
```

`RewriteService` 只把公开模型名暴露给状态接口；配置不完整、主机不允许、请求失败、非 2xx 和空结果分别抛出稳定模块异常。`ApiController` 将该异常转换为原有 JSON 信封，不把供应商错误误报为 User 登录过期。

- [ ] **Step 4: 运行 GREEN 与相关回归**

```powershell
php artisan test --filter=QingyuIpAgentCapabilityP0Test
php artisan test --filter=QingyuIpAgentModuleTest
```

---

### Task 2: 文案解析网络边界强化

**Files:**
- Modify: `modules/QingyuIpAgent/src/Services/VideoParserService.php`
- Test: `tests/Feature/Modules/QingyuIpAgentCapabilityP0Test.php`

**Interfaces:**
- `VideoParserService` 在每次初始请求和重定向前校验受支持域名及 DNS 解析地址。
- 只接收 HTML、JSON、XML 文本响应，拒绝声明超过 `1048576` 字节的响应。

- [ ] **Step 1: 写解析安全 RED 测试**

覆盖私网/环回解析、重定向重新校验、非文本 Content-Type、超大 Content-Length，以及正常抖音元数据提取。

- [ ] **Step 2: 运行 RED**

```powershell
php artisan test --filter=QingyuIpAgentCapabilityP0Test
```

- [ ] **Step 3: 实现最小网络校验**

解析器接受一个可测试的主机解析器；生产默认使用系统 A/AAAA 解析。任何解析结果属于私网、保留、环回或链路本地地址时拒绝该请求；每次重定向重新执行同一校验。

- [ ] **Step 4: 运行 GREEN**

```powershell
php artisan test --filter=QingyuIpAgentCapabilityP0Test
```

---

### Task 3: 桌面 IPC 直接接管

**Files:**
- Modify: `desktop-shell/takeover-core.test.js`
- Modify: `desktop-shell/takeover-core.js`
- Modify: `desktop-shell/takeover.js`

**Interfaces:**
- `videoParserRequestForChannel(channel, args)` 同时支持 `video-parser:parse` 与 `video-parser:parse-and-extract`。
- `platformAiRequestForChannel(channel, args)` 映射状态和明确改写请求。
- `isExplicitRewriteRequest(message, options)` 只接受带有改写工作流/提示特征的请求。
- `normalizePlatformAiResponse(channel, response)` 生成原 Renderer 兼容字段。

- [ ] **Step 1: 写桌面 RED 测试**

断言：两个文案 IPC 直接调用模块且不执行原监听器；`cloud:get-ai-config` 和连接测试调用 `/content/status`；明确改写调用 `/content/rewrite`；非改写 LLM 返回 `该 AI 能力尚未接管`；响应包含 Renderer 所需别名；P0 外 IPC 不进入模块。

- [ ] **Step 2: 运行 RED**

```powershell
node --test --test-name-pattern "P0|video parser" desktop-shell\takeover-core.test.js
```

- [ ] **Step 3: 实现最小 IPC 接管**

`VIDEO_PARSER_IPC_CHANNELS` 增加 `parse-and-extract`，解析处理器直接调用模块。新增平台 AI 中间件处理四个 `ipcMain.handle` 通道；Renderer 传入的 token、API URL 和 API Key 不进入请求正文。`localApiRequest` 继续保持一次刷新重试和同一 `X-Request-ID`。

- [ ] **Step 4: 运行 GREEN 与全量 Node 回归**

```powershell
node --test desktop-shell\takeover-core.test.js
node --check desktop-shell\takeover-core.js
node --check desktop-shell\takeover.js
```

---

### Task 4: 审查、提交、同步安装端与真实验证

**Files:**
- Sync: `E:\code\轻语智能体\aigc-human-new\desktop-shell\takeover-core.js`
- Sync: `E:\code\轻语智能体\aigc-human-new\desktop-shell\takeover.js`

- [ ] **Step 1: 审查变更边界**

运行 `git diff --check`、敏感词扫描、保护文件状态核对；只暂存本计划涉及文件。

- [ ] **Step 2: 分仓提交**

```text
feat: expose Qingyu P0 content capabilities
feat: route Qingyu P0 desktop capabilities through User
```

- [ ] **Step 3: 同步安装端并校验哈希**

关闭客户端后备份现有 `desktop-shell` 文件，再同步两个 runtime 文件，并确认源码与安装端 SHA-256 一致。

- [ ] **Step 4: 部署后端与真实验证**

先验证 `/content/status`，再用指定抖音链接验证解析，最后使用短文本验证真实云端改写。检查 User 会话未退出、供应商错误未显示为登录过期、日志中没有令牌/API Key/正文。
