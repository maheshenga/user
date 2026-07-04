# 用户端账号、VIP、邀请和 2 级分销系统设计

## 背景

当前 EasyAdmin8-Laravel 已有后台管理员登录体系，基于 `system_admin`、后台动态路由和 `session('admin')`。该体系用于管理后台，不适合作为普通用户端账号体系。

本设计新增一套独立用户账户中心，服务普通用户注册登录、VIP 权益、邀请关系、激活码兑换和 2 级分销。后台管理员只负责配置、审核、查询、调整和风控。

## 目标

- 用户可以用手机号或邮箱注册，至少绑定其中一个。
- 用户可以登录、退出、找回密码。
- 用户可以通过邀请码或邀请链接注册。
- 系统记录固定邀请关系，并支持 2 级分销。
- 用户可以兑换激活码开通或续期 VIP。
- 可分佣 VIP 订单和可分佣激活码兑换会产生 2 级分销奖励。
- 分销奖励进入站内余额账本，默认先待结算，由管理员审核后转为可用余额。
- 后台管理员可以管理用户、邀请、VIP、激活码、佣金、余额和安全日志。

## 非目标

- 本阶段不实现前端页面细节，只定义后端能力和后台管理边界。
- 本阶段不接入真实支付网关，但预留 VIP 订单分佣入口。
- 本阶段不实现自动提现打款，只设计余额、冻结、审核和提现扩展点。
- 本阶段不复用 `system_admin` 作为普通用户表。

## 角色

- 游客：未登录用户，可注册、登录、找回密码。
- 普通用户：已注册用户，可查看 VIP、兑换激活码、查看邀请和余额。
- VIP 用户：拥有有效 VIP 权益的普通用户。
- 分销用户：拥有下级邀请关系并可能产生佣金的用户。所有用户默认可成为分销用户。
- 后台管理员：通过现有后台登录，可审核、配置、冻结、作废和调整数据。

## 账号模型

### 用户主表

建议表名：`user_account`

核心字段：
- `id`
- `mobile`
- `mobile_verified_at`
- `email`
- `email_verified_at`
- `password`
- `nickname`
- `avatar`
- `status`: `pending`, `active`, `disabled`, `frozen`
- `register_channel`
- `register_ip`
- `last_login_at`
- `last_login_ip`
- `available_balance`
- `frozen_balance`
- `vip_level`
- `vip_expires_at`
- `create_time`
- `update_time`
- `delete_time`

约束：
- `mobile` 可空但唯一。
- `email` 可空但唯一。
- 注册时 `mobile` 和 `email` 至少一个不为空。
- 密码使用 Laravel Hash，不保存明文。
- `available_balance` 和 `frozen_balance` 是余额快照，真实来源以账本流水为准。

### 用户资料扩展表

建议表名：`user_profile`

用于保存非登录关键字段，避免用户主表膨胀：
- `user_id`
- `real_name`
- `company`
- `country`
- `province`
- `city`
- `metadata_json`

## 注册登录

### 注册方式

支持：
- 手机号 + 密码。
- 邮箱 + 密码。
- 手机号 + 邮箱 + 密码。

注册请求必须满足：
- 手机号或邮箱至少填写一个。
- 密码符合最小安全规则。
- 如果配置要求邀请码必填，则必须提供有效邀请码。
- 同一手机号或邮箱不能重复注册。
- 被禁用渠道码、过期邀请码不能用于注册。

### 登录方式

支持：
- 手机号 + 密码。
- 邮箱 + 密码。

登录成功后：
- 写入用户端 session 或 API token。
- 更新最后登录时间和 IP。
- 写入 `user_login_log`。

登录失败后：
- 写入失败日志。
- 同账号、同 IP 做限频。
- 多次失败可触发短时锁定。

### 退出登录

退出登录清理当前 session 或当前 token。找回密码成功后，应撤销该用户的其他活跃 token 或会话。

## 找回密码

支持手机号或邮箱找回。

### 找回流程

1. 用户输入手机号或邮箱。
2. 系统生成验证码或一次性重置 token。
3. 验证码或 token 设置过期时间。
4. 用户提交验证码、新密码。
5. 系统校验通过后更新密码。
6. 清理旧登录态。
7. 写入安全日志。

### 数据表

建议表名：`user_password_reset`

字段：
- `id`
- `account_type`: `mobile`, `email`
- `account`
- `token_hash`
- `code_hash`
- `expires_at`
- `used_at`
- `request_ip`
- `attempt_count`
- `create_time`

规则：
- token 和验证码只保存 hash。
- 同账号和同 IP 均有限频。
- 过期或已使用 token 不能重复使用。

## 邀请机制

### 邀请码

建议表名：`user_invite_code`

字段：
- `id`
- `owner_user_id`
- `code`
- `type`: `user`, `channel`, `admin_batch`
- `status`: `active`, `disabled`, `expired`
- `max_uses`
- `used_count`
- `expires_at`
- `metadata_json`
- `create_time`

规则：
- 每个用户默认拥有一个长期邀请码。
- 后台可创建渠道码和批量码。
- 邀请码应不可预测，不能使用简单自增。
- 邀请码可设置使用次数和有效期。

### 邀请关系

建议表名：`user_invite_relation`

字段：
- `id`
- `user_id`
- `parent_user_id`
- `grandparent_user_id`
- `invite_code_id`
- `level_path`
- `bind_type`: `register`, `admin_adjust`
- `status`: `active`, `invalid`
- `create_time`

规则：
- 每个用户最多一个直接上级。
- 邀请关系注册后默认不可修改。
- 后台如需调整，必须写审计日志。
- 只做 2 级分销计算，但可以保存 `level_path` 便于后续统计。
- 用户不能邀请自己，不能形成循环关系。

## VIP 模块

### VIP 套餐

建议表名：`vip_plan`

字段：
- `id`
- `name`
- `level`
- `duration_days`
- `price`
- `status`
- `is_commissionable`
- `first_level_rate`
- `second_level_rate`
- `benefits_json`
- `create_time`
- `update_time`

规则：
- 只有 `is_commissionable = 1` 的套餐才触发分销佣金。
- 一级、二级佣金比例可按套餐配置。
- VIP 权益由服务层统一判断，不直接散落在业务代码中。

### 用户 VIP 记录

建议表名：`user_vip_record`

字段：
- `id`
- `user_id`
- `source_type`: `order`, `activation_code`, `admin_adjust`
- `source_id`
- `vip_plan_id`
- `before_expires_at`
- `after_expires_at`
- `duration_days`
- `status`: `active`, `revoked`
- `create_time`

规则：
- 开通、续期、后台调整都写记录。
- 用户表可保存当前 VIP 快照，但原始流水以 `user_vip_record` 为准。
- VIP 到期不删除记录。

### VIP 订单来源

建议表名：`vip_order`

本阶段不接入真实支付网关，但需要预留订单来源，作为后续支付和分销的统一入口。

字段：
- `id`
- `order_no`
- `user_id`
- `vip_plan_id`
- `amount`
- `pay_status`: `pending`, `paid`, `closed`, `refunded`
- `is_commissionable`
- `paid_at`
- `refunded_at`
- `create_time`
- `update_time`

规则：
- 只有 `pay_status = paid` 且 `is_commissionable = 1` 的订单才触发分销。
- 订单完成后由 `VipService` 生成 `user_vip_record`。
- 分销系统只接收已支付订单事件，不直接处理支付渠道回调。
- 退款或订单关闭后，需要触发佣金冻结或冲正。

## 激活码机制

### 激活码批次

建议表名：`activation_code_batch`

字段：
- `id`
- `name`
- `vip_plan_id`
- `duration_days`
- `total_count`
- `status`: `draft`, `active`, `disabled`, `expired`
- `is_commissionable`
- `first_level_reward`
- `second_level_reward`
- `expires_at`
- `create_admin_id`
- `create_time`

### 激活码

建议表名：`activation_code`

字段：
- `id`
- `batch_id`
- `code_hash`
- `display_code_tail`
- `status`: `unused`, `used`, `disabled`, `expired`, `void`
- `max_uses`
- `used_count`
- `bound_user_id`
- `expires_at`
- `create_time`

规则：
- 数据库保存激活码 hash，后台生成后只展示一次完整明文码。
- 支持单次码和有限次数码。
- 可绑定指定用户。
- 过期、禁用、作废的码不可兑换。
- 兑换操作必须幂等。

### 兑换记录

建议表名：`activation_code_redemption`

字段：
- `id`
- `activation_code_id`
- `batch_id`
- `user_id`
- `vip_record_id`
- `commission_source_id`
- `redeem_ip`
- `result`
- `error_message`
- `create_time`

## 2 级分销

### 触发来源

支持两类来源：
- 可分佣 VIP 订单。
- 可分佣激活码兑换。

只有来源被后台标记为可分佣时才产生佣金。

### 佣金计算

用户 A 邀请 B，B 邀请 C：
- B 是 A 的一级下级。
- C 是 B 的一级下级。
- C 是 A 的二级下级。

当 C 产生可分佣事件：
- B 获得一级奖励。
- A 获得二级奖励。

规则：
- 只计算 2 级。
- 上级用户不存在、被冻结、被禁用时，不生成或冻结该级佣金，具体由后台配置决定。
- 同一来源只能生成一次佣金，使用唯一索引防重复。
- 退款、订单撤销、激活码作废时，需要支持佣金冲正。

### 佣金记录

建议表名：`affiliate_commission`

字段：
- `id`
- `source_type`: `vip_order`, `activation_code`
- `source_id`
- `buyer_user_id`
- `beneficiary_user_id`
- `level`: `1`, `2`
- `amount`
- `status`: `pending`, `approved`, `rejected`, `frozen`, `settled`, `reversed`
- `reason`
- `audit_admin_id`
- `audited_at`
- `create_time`

规则：
- 新佣金默认 `pending`。
- 管理员审核通过后写入余额账本，并将佣金标记为 `settled`。
- `approved` 只用于需要“审核通过”和“入账”拆成两个步骤的运营模式；默认实现可以直接从 `pending` 进入 `settled`。
- 审核拒绝必须填写原因。
- 冲正必须关联原佣金记录。

## 站内余额账本

### 余额流水

建议表名：`user_balance_ledger`

字段：
- `id`
- `user_id`
- `direction`: `in`, `out`, `freeze`, `unfreeze`
- `amount`
- `balance_before`
- `balance_after`
- `frozen_before`
- `frozen_after`
- `type`: `affiliate_commission`, `admin_adjust`, `withdraw_freeze`, `withdraw_success`, `withdraw_reject`, `reversal`
- `source_type`
- `source_id`
- `remark`
- `admin_id`
- `create_time`

规则：
- 所有余额变化必须有流水。
- 用户余额快照和流水更新必须在同一数据库事务中完成。
- 禁止直接修改用户余额快照。
- 后台手动调整余额必须写原因和管理员 ID。

## 后台管理

### 用户管理

- 用户列表、搜索、详情。
- 查看登录日志、安全日志、邀请关系、VIP 记录、余额流水。
- 禁用、冻结、解冻用户。
- 后台重置密码。
- 后台调整手机号、邮箱时写审计日志。

### 邀请管理

- 查看用户邀请树和 2 级下级。
- 管理用户邀请码、渠道码、批量码。
- 禁用邀请码。
- 查看异常邀请。

### VIP 管理

- 管理 VIP 套餐。
- 配置是否可分佣。
- 配置一级和二级佣金规则。
- 查看用户 VIP 记录。
- 后台手动开通、续期、撤销。

### 激活码管理

- 创建激活码批次。
- 批量生成激活码。
- 导出激活码。
- 禁用、作废、过期处理。
- 查看兑换记录。
- 配置批次是否可分佣。

### 分销和余额管理

- 佣金列表。
- 批量审核通过、驳回、冻结。
- 查看佣金来源。
- 余额流水查询。
- 后台余额调整。
- 预留提现审核入口。

## 风控和审核

必须具备：
- 注册 IP 限频。
- 登录失败限频。
- 找回密码限频。
- 激活码兑换限频。
- 邀请注册异常检测。
- 同 IP、同设备、同手机号段批量注册标记。
- 管理员关键操作审计。

建议策略：
- 分销佣金默认待审核。
- 新用户产生的佣金可设置观察期。
- 异常邀请链自动冻结佣金。
- 激活码批次可限制渠道和兑换时间。

## 服务边界

建议核心服务：
- `UserAuthService`: 注册、登录、退出、密码重置。
- `InviteService`: 邀请码生成、校验、绑定邀请关系。
- `VipService`: VIP 开通、续期、状态判断。
- `ActivationCodeService`: 激活码生成、兑换、作废。
- `AffiliateService`: 佣金生成、审核、冲正。
- `BalanceLedgerService`: 余额入账、冻结、解冻、扣减。
- `UserSecurityLogService`: 安全日志和审计。

边界规则：
- 控制器只做请求校验和响应。
- 余额、VIP、佣金必须通过服务层修改。
- 分销和余额更新必须使用数据库事务。
- 对外查询 VIP 状态统一通过 `VipService`。

## API 能力

用户端建议接口：
- `POST /user/register`
- `POST /user/login`
- `POST /user/logout`
- `POST /user/password/forgot`
- `POST /user/password/reset`
- `GET /user/profile`
- `GET /user/vip`
- `POST /user/activation-code/redeem`
- `GET /user/invite`
- `GET /user/invite/records`
- `GET /user/balance`
- `GET /user/balance/ledger`

后台沿用 EasyAdmin 动态路由风格，新增管理控制器：
- `user/account`
- `user/invite`
- `user/vip-plan`
- `user/activation-code`
- `user/commission`
- `user/balance`
- `user/security-log`

## 测试策略

必须覆盖：
- 手机号注册。
- 邮箱注册。
- 手机号和邮箱至少一个必填。
- 重复手机号、邮箱注册失败。
- 密码登录成功和失败。
- 找回密码 token 过期、重复使用、成功重置。
- 邀请码注册绑定一级和二级关系。
- 无效、过期、禁用邀请码不可用。
- 激活码兑换开通 VIP。
- 激活码重复兑换幂等。
- 不可分佣激活码不生成佣金。
- 可分佣激活码生成 2 级佣金。
- 可分佣 VIP 订单生成 2 级佣金。
- 佣金审核通过写入余额账本。
- 佣金驳回不入账。
- 余额流水和用户余额快照一致。
- 冻结用户不允许登录或提现相关操作。

## 分期计划

### Phase 1: 用户账户基础

- 用户表和资料表。
- 用户注册、登录、退出。
- 登录日志和基础限频。
- 后台用户列表和详情。

### Phase 2: 邀请关系

- 用户邀请码。
- 邀请注册绑定。
- 一级、二级关系查询。
- 后台邀请管理。

### Phase 3: 找回密码

- 手机号或邮箱找回密码。
- token 或验证码存储。
- 找回密码限频。
- 安全日志。

### Phase 4: VIP 和激活码

- VIP 套餐。
- 用户 VIP 记录。
- 激活码批次、生成、兑换。
- 后台激活码管理。

### Phase 5: 2 级分销和余额账本

- 可分佣来源标记。
- 2 级佣金生成。
- 佣金审核。
- 余额流水。
- 后台余额调整。

### Phase 6: 运营和风控增强

- 异常邀请检测。
- 批量审核。
- 激活码导出。
- 分销统计。
- 提现审核预留或实现。

## 验收标准

- 普通用户端账号体系不依赖 `system_admin`。
- 手机号和邮箱注册登录均可用。
- 用户注册可以绑定邀请码。
- 邀请关系可追溯 2 级。
- 找回密码流程可安全重置密码。
- 激活码可兑换 VIP。
- 可分佣 VIP 订单和激活码兑换均能生成 2 级佣金。
- 佣金审核通过后进入站内余额。
- 所有余额变化都有流水。
- 后台可管理用户、邀请码、VIP 套餐、激活码、佣金和余额。
- 关键操作具备日志和限频。
