# EasyAdmin8 模块 AI 开发手册

本文档给 AI 开发者使用。目标不是介绍概念，而是约束 AI 如何在本项目中独立设计、编写、升级、审核和交付模块。

适用项目：`EasyAdmin8-Laravel`

适用模块目录：`modules/{StudlyName}`

当前模块入口：`module.json`

当前模块管理后台：`系统管理 -> 模块管理`

## 1. AI 执行总规则

AI 开发模块前必须先完成以下动作：

1. 阅读本文档。
2. 阅读目标需求。
3. 阅读当前项目模块实现：
   - `app/Modules/ModuleManifest.php`
   - `app/Modules/ModuleManager.php`
   - `app/Modules/ModuleInstaller.php`
   - `app/Modules/ModuleRepository.php`
   - `app/Modules/ModuleRouteResolver.php`
   - `app/Modules/ModuleMigrationRunner.php`
4. 阅读可运行样例：
   - `tests/Fixtures/modules/Blog/module.json`
   - `tests/Fixtures/modules/Blog/src/Controllers/PostController.php`
5. 先产出模块设计说明，再写代码。
6. 每次修改必须有验证命令和验证结果。

AI 禁止事项：

- 禁止改宿主系统核心业务来迁就模块，除非需求明确要求修改宿主能力。
- 禁止绕过模块生命周期，不能直接把模块状态写成 `enabled`。
- 禁止在模块外写入业务表、视图、静态资源，除非是宿主约定的导入点。
- 禁止在卸载时删除用户业务数据；当前系统采用保留数据卸载。
- 禁止使用全局函数、全局变量或硬编码路径污染宿主。
- 禁止把模块权限、菜单、路由散落到宿主文件中。
- 禁止在未说明兼容性和回滚方式的情况下升级模块版本。
- 禁止自动信任第三方代码。第三方模块必须经过管理员审核。

## 2. 模块边界

模块只能拥有自己的代码、视图、资产、迁移和菜单声明。

模块目录示例：

```text
modules/
  Blog/
    module.json
    src/
      Controllers/
      Services/
      Models/
      Providers/
    resources/
      views/
    assets/
      js/
      css/
      images/
    database/
      migrations/
      seeders/
    tests/
    docs/
```

模块可以做：

- 提供后台控制器。
- 提供后台页面视图。
- 提供模块静态资源。
- 声明后台菜单。
- 声明权限节点。
- 创建模块自有数据表。
- 在安装和升级时运行模块迁移。
- 在启用状态下响应自己的后台路由。

模块不可以做：

- 接管宿主已有后台前缀，例如 `system`、`user`、`mall` 等已存在业务前缀。
- 修改宿主登录、权限、菜单、模块中心逻辑。
- 依赖未声明的外部服务。
- 在运行时写入 `app/`、`routes/`、`config/` 等宿主目录。
- 在模块控制器中跳过 EasyAdmin 权限体系。

## 3. module.json 规范

每个模块必须有 `module.json`，并且必须是 JSON 对象。

必填字段：

```json
{
  "schema_version": "1.0",
  "name": "blog",
  "title": "Blog Module",
  "vendor": "easyadmin8",
  "version": "1.0.0",
  "type": "private",
  "core_version": "^8.0",
  "namespace": "Modules\\Blog",
  "admin_prefix": "blog"
}
```

字段规则：

- `schema_version`：当前使用 `1.0`。
- `name`：模块唯一标识，只能使用小写字母、数字和下划线，必须以小写字母开头。
- `title`：后台展示名称。
- `vendor`：开发方或团队标识。
- `version`：语义化版本，建议 `MAJOR.MINOR.PATCH`。
- `type`：模块类型，可用值应与 `config/modules.php` 的 `allowed_types` 一致。
- `core_version`：兼容的宿主版本范围。
- `namespace`：模块 PHP 命名空间。
- `admin_prefix`：模块后台路由前缀，只能使用小写字母、数字和下划线，必须唯一。

推荐字段：

```json
{
  "php": ">=8.3",
  "entry": "src/Providers/BlogServiceProvider.php",
  "controllers": "src/Controllers",
  "views": "resources/views",
  "assets": "assets",
  "migrations": "database/migrations",
  "seeders": "database/seeders",
  "permissions": ["menu:write", "node:write"],
  "external_domains": [],
  "dependencies": {},
  "conflicts": {},
  "database": {
    "tables": ["blog_posts"],
    "preserve_on_uninstall": true
  },
  "menus": []
}
```

路径规则：

- 相对路径必须位于模块目录内。
- 不允许使用 `../` 逃出模块目录。
- 不允许把模块路径指向宿主源码目录。
- `controllers` 默认是 `src/Controllers`。
- `views` 默认是 `resources/views`。
- `assets` 默认是 `assets`。
- `migrations` 默认是 `database/migrations`。
- `seeders` 默认是 `database/seeders`。

## 4. 后台路由规则

模块后台 URL 规则：

```text
/admin/{admin_prefix}/{controller}/{action}
```

示例：

```text
/admin/blog/post/index
```

对应控制器：

```php
namespace Modules\Blog\Controllers;

class PostController
{
    public function index()
    {
        //
    }
}
```

控制器解析规则：

- `admin_prefix` 对应 `module.json` 的 `admin_prefix`。
- `{controller}` 会映射到模块 `controllers` 目录下的控制器类。
- `post` 映射为 `PostController`。
- `reports/post` 映射为 `Reports\PostController`。
- 只有模块状态为 `enabled` 时，模块路由才会生效。
- 如果前缀是宿主保留前缀，则走宿主控制器，不走模块控制器。

控制器规则：

- 后台控制器必须使用 EasyAdmin 注解体系：

```php
use App\Http\Services\annotation\ControllerAnnotation;
use App\Http\Services\annotation\NodeAnnotation;

#[ControllerAnnotation(title: '文章管理', auth: true)]
class PostController extends BasePostController
{
    #[NodeAnnotation(title: '列表', auth: true)]
    public function index()
    {
        return $this->fetch();
    }
}
```

- 需要权限控制的 action 必须加 `NodeAnnotation`。
- 不需要出现在节点中的 action 必须显式忽略。
- 不允许用控制器方法直接拼 SQL。
- 不允许在控制器里写复杂业务逻辑；业务逻辑放到模块 `Services`。

## 5. 视图和资产规则

模块视图命名空间：

```text
modules.{admin_prefix}::{controller}.{action}
```

示例：

```text
modules.blog::post.index
```

推荐视图路径：

```text
modules/Blog/resources/views/post/index.blade.php
```

模块资产访问路径：

```text
/module-assets/{admin_prefix}/{path}
```

示例：

```text
/module-assets/blog/js/post.js
```

资产规则：

- 模块资产必须放在模块 `assets` 目录。
- 不要写入 `public/static/admin/js`。
- 不要覆盖宿主 CSS、JS、图片。
- JS 里不要假设全局页面状态，必须通过当前页面 DOM 或 EasyAdmin 提供的接口读取。
- 外部 CDN 必须写入 `external_domains`，并说明用途。

## 6. 菜单规则

模块菜单必须写在 `module.json` 的 `menus` 中。

示例：

```json
{
  "menus": [
    {
      "title": "Blog",
      "icon": "fa fa-edit",
      "href": "",
      "children": [
        {
          "title": "Posts",
          "icon": "fa fa-file-text",
          "href": "blog/post/index"
        }
      ]
    }
  ]
}
```

菜单规则：

- 父菜单 `href` 可以为空。
- 子菜单 `href` 必须指向模块后台路由。
- `href` 必须以模块 `admin_prefix` 开头。
- 菜单标题必须面向管理员可理解，不要使用内部类名。
- 安装模块时菜单会导入到 `system_menu`。
- 菜单导入是幂等的，重复安装不应产生重复菜单。

AI 生成菜单时必须检查：

- 是否和已有菜单语义重复。
- 是否存在孤儿菜单。
- 是否有页面但没有菜单。
- 是否有菜单但没有控制器。
- 是否有菜单但没有权限节点。

## 7. 权限节点规则

权限节点来自控制器注解扫描。

模块节点扫描会读取启用模块的控制器注解，并合并到宿主节点体系。

规则：

- 每个可访问后台页面都必须有 `ControllerAnnotation`。
- 每个需要权限控制的方法都必须有 `NodeAnnotation`。
- 列表、详情、创建、编辑、删除、导出、审核、启用、禁用等动作必须单独声明节点。
- 只读页面也要声明节点。
- 只给超级管理员看不等于无需节点；节点仍要完整。

AI 必须避免：

- 通过隐藏路由绕过权限。
- 在菜单中暴露无节点页面。
- 把危险操作放在 GET 请求中。

## 8. 生命周期

模块状态流：

```text
发现 -> 待审核 -> 已审核 -> 已安装 -> 已启用
                      |        |
                      |        -> 已禁用
                      |
                      -> 已拒绝
```

系统中的实际状态：

- `pending_review`：待管理员审核。
- `approved`：审核通过。
- `rejected`：审核拒绝。
- `installed`：已安装。
- `enabled`：已启用。
- `disabled`：已禁用。
- `uninstalled`：已卸载但保留数据。

命令：

```bash
php artisan module:discover
php artisan module:list
php artisan module:install blog
php artisan module:enable blog
php artisan module:disable blog
php artisan module:uninstall blog
```

后台操作：

- 发现模块。
- 上传 ZIP。
- 审核通过。
- 审核拒绝。
- 安装。
- 启用。
- 禁用。
- 本地升级。
- ZIP 升级。
- 回滚。
- 卸载。

AI 必须遵守：

- 新发现模块默认不能直接安装，必须经过管理员审核。
- 审核拒绝的模块不能安装。
- 只有 `installed` 或 `disabled` 模块可以启用。
- 只有 `enabled` 模块可以禁用。
- 卸载只标记为 `uninstalled`，不删除业务数据。
- 升级必须新版本号大于当前版本。
- 回滚必须有备份。

## 9. 版本规则

模块版本使用语义化版本：

```text
MAJOR.MINOR.PATCH
```

版本含义：

- `PATCH`：修复 Bug，不改变接口和数据结构。
- `MINOR`：新增向后兼容功能，可增加表、字段、菜单、节点。
- `MAJOR`：破坏性变更，必须提供迁移说明、兼容说明和人工审核清单。

AI 修改版本时必须判断：

- 是否改了数据库结构。
- 是否改了公开接口。
- 是否改了菜单或权限。
- 是否改了模块配置。
- 是否改了用户可见行为。
- 是否需要数据迁移。
- 是否能回滚。

版本升级规则：

- 任何 ZIP 或本地升级，`module.json.version` 必须大于当前版本。
- 升级前系统会备份模块目录。
- 升级时会运行未执行的模块迁移。
- 升级失败时应保留 `last_error` 和模块日志。
- 文件替换失败时必须停止并保留现场。

禁止：

- 用同版本覆盖发布。
- 降级伪装成升级。
- 修改迁移文件但不改版本。
- 删除已执行迁移文件。

## 10. 数据库迁移规则

模块迁移目录：

```text
database/migrations
```

迁移文件必须返回对象，并包含 `up()` 方法。

推荐包含 `down()` 方法：

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_posts', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 200);
            $table->text('content')->nullable();
            $table->unsignedBigInteger('create_time')->nullable();
            $table->unsignedBigInteger('update_time')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_posts');
    }
};
```

迁移规则：

- 表名必须带模块前缀，例如 `blog_posts`。
- 不要修改宿主表，除非需求明确并写入审核说明。
- 所有迁移必须幂等可理解。
- 新增字段必须有默认值或允许为空。
- 金额字段必须使用 decimal。
- 状态字段必须有明确枚举说明。
- 大表查询字段必须加索引。
- 可回滚迁移必须提供 `down()`。

回滚限制：

- 当前自动回滚最多处理一个缺失迁移。
- 多个缺失迁移需要人工审核。
- 没有 `down()` 的迁移会阻止回滚。

AI 必须在模块文档中写明：

- 新增表。
- 新增字段。
- 索引。
- 数据保留策略。
- 是否支持回滚。

## 11. 安全边界

模块安全默认不可信。

必须遵守：

- 所有后台危险操作必须使用 POST。
- 所有写操作必须经过权限节点。
- 所有输入必须校验。
- 文件上传必须限制类型、大小和路径。
- 不允许解压 ZIP 到模块目录外。
- 不允许访问 `.env`、密钥、宿主缓存、日志敏感信息。
- 不允许执行 shell 命令，除非模块需求明确且管理员审核通过。
- 不允许发起外部网络请求，除非在 `external_domains` 声明并审核。
- 不允许保存明文密码、token、密钥。
- 不允许在视图中输出未转义用户输入。

第三方模块审核必须检查：

- `module.json` 是否完整。
- `admin_prefix` 是否冲突。
- 是否存在危险 PHP 函数：`exec`、`shell_exec`、`system`、`passthru`、`proc_open`、`eval`。
- 是否读取 `.env` 或宿主敏感文件。
- 是否写入模块目录外路径。
- 是否有外部网络请求。
- 是否有未声明数据表。
- 是否有不可回滚迁移。
- 是否有隐藏后台路由。
- 是否有绕过权限的 action。

## 12. AI 开发流程

AI 每次开发模块必须按以下流程执行：

1. 需求澄清
   - 明确模块目标。
   - 明确用户角色。
   - 明确后台菜单。
   - 明确数据表。
   - 明确是否需要用户端页面或 API。

2. 模块设计
   - 写出模块目录结构。
   - 写出 `module.json`。
   - 写出路由和控制器列表。
   - 写出菜单和权限节点。
   - 写出数据库迁移计划。
   - 写出版本号和升级策略。

3. 编码
   - 先写迁移。
   - 再写模型和服务。
   - 再写控制器。
   - 再写视图和资产。
   - 最后写模块文档。

4. 本地安装验证
   - 放入 `modules/{StudlyName}`。
   - 运行 `php artisan module:discover`。
   - 在模块中心审核通过。
   - 安装模块。
   - 启用模块。
   - 检查菜单。
   - 检查页面。
   - 检查权限。
   - 检查日志。

5. 升级验证
   - 修改版本号。
   - 运行本地升级或 ZIP 升级。
   - 检查备份。
   - 检查新迁移是否执行。
   - 检查旧数据是否保留。
   - 检查回滚。

6. 交付
   - 提供模块包。
   - 提供变更日志。
   - 提供安装说明。
   - 提供审核清单。
   - 提供测试结果。

## 13. AI 输出格式

AI 交付一个模块时必须输出：

```text
模块名称：
模块版本：
模块类型：
兼容宿主版本：
后台前缀：
菜单入口：
新增数据表：
新增权限节点：
外部依赖：
外部域名：
是否支持升级：
是否支持回滚：
是否保留卸载数据：
验证命令：
验证结果：
风险说明：
```

如果是升级包，必须额外输出：

```text
当前版本：
目标版本：
升级类型：PATCH/MINOR/MAJOR
数据库变更：
迁移文件：
是否可回滚：
回滚限制：
人工审核重点：
```

## 14. module.json 完整模板

```json
{
  "schema_version": "1.0",
  "name": "example",
  "title": "Example Module",
  "vendor": "internal",
  "version": "1.0.0",
  "type": "private",
  "core_version": "^8.0",
  "php": ">=8.3",
  "namespace": "Modules\\Example",
  "entry": "src/Providers/ExampleServiceProvider.php",
  "admin_prefix": "example",
  "controllers": "src/Controllers",
  "views": "resources/views",
  "assets": "assets",
  "migrations": "database/migrations",
  "seeders": "database/seeders",
  "permissions": [],
  "external_domains": [],
  "dependencies": {},
  "conflicts": {},
  "database": {
    "tables": ["example_items"],
    "preserve_on_uninstall": true
  },
  "menus": [
    {
      "title": "Example",
      "icon": "fa fa-cubes",
      "href": "",
      "children": [
        {
          "title": "Items",
          "icon": "fa fa-list",
          "href": "example/item/index"
        }
      ]
    }
  ]
}
```

## 15. 控制器模板

```php
<?php

namespace Modules\Example\Controllers;

use App\Http\Controllers\common\AdminController;
use App\Http\Services\annotation\ControllerAnnotation;
use App\Http\Services\annotation\NodeAnnotation;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

#[ControllerAnnotation(title: '示例数据管理', auth: true)]
class ItemController extends AdminController
{
    #[NodeAnnotation(title: '列表', auth: true)]
    public function index(): View|JsonResponse
    {
        if (! request()->ajax() && ! request()->expectsJson()) {
            return $this->fetch();
        }

        return response()->json([
            'code' => 0,
            'msg' => '',
            'count' => 0,
            'data' => [],
        ]);
    }
}
```

## 16. 版本迭代模板

每个模块必须维护变更日志：

```text
## 1.1.0

类型：MINOR

新增：
- 新增订单列表页面。
- 新增 order_items 表。

变更：
- 调整订单搜索条件。

兼容：
- 兼容 1.0.x 数据。

迁移：
- 2026_07_07_000001_create_example_orders_table.php

回滚：
- 支持 down() 回滚。

审核重点：
- 检查订单金额字段 decimal 精度。
- 检查订单详情权限节点。
```

## 17. 测试和验收

基础验证命令：

```bash
php artisan module:discover
php artisan module:list
php artisan module:install example
php artisan module:enable example
php artisan module:disable example
php artisan module:uninstall example
```

Windows 本项目测试命令：

```bash
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite
```

模块验收清单：

- `module.json` 能被解析。
- 模块能被 `module:discover` 发现。
- 模块中心显示模块。
- 待审核模块不能安装。
- 审核通过后可以安装。
- 安装后菜单导入成功。
- 启用后后台路由可访问。
- 禁用后后台路由不可访问。
- 升级要求版本号递增。
- 升级会生成备份。
- 迁移执行记录写入 `system_module_migration`。
- 操作日志写入 `system_module_log`。
- 回滚能恢复最新备份。
- 卸载不删除业务数据。

## 18. 长线运营规则

模块要适合长期运营，不能只为一次交付。

长期运营要求：

- 所有状态必须可追踪。
- 所有危险操作必须可审计。
- 所有版本必须可对比。
- 所有数据库变更必须可迁移。
- 所有失败必须写入错误信息。
- 所有升级必须保留备份。
- 所有第三方模块必须人工审核。
- 所有外部依赖必须声明。
- 所有配置必须可后台管理或写入说明。
- 所有数据必须有保留策略。

AI 每次迭代必须回答：

- 这次改动会影响哪些模块状态？
- 这次改动会影响哪些表？
- 这次改动能否回滚？
- 这次改动是否需要管理员重新审核？
- 这次改动是否影响已有菜单和权限？
- 这次改动是否影响用户数据？
- 这次改动是否引入外部依赖？

## 19. 管理员审核规则

本系统不做第三方平台审核，只做管理员审核。

管理员审核入口：

```text
系统管理 -> 模块管理
```

管理员审核动作：

- 审核通过。
- 审核拒绝。
- 填写拒绝原因。
- 查看模块详情。
- 查看操作日志。

审核通过前，AI 不得要求用户安装模块。

审核拒绝后，AI 必须根据拒绝原因修复模块，并提升版本或重新提交包。

## 20. 停止条件

AI 遇到以下情况必须停止并报告，不得继续自动修改：

- 模块要求修改宿主登录或权限核心。
- 模块要求读取 `.env` 或密钥。
- 模块需要执行 shell 命令。
- 模块需要访问未声明外部域名。
- 模块迁移无法提供回滚策略。
- 模块升级需要删除用户数据。
- 模块后台前缀与已有前缀冲突。
- 模块版本低于或等于当前版本。
- 模块 ZIP 解压结果不是单一模块目录。
- 模块测试无法确认通过。

## 21. 推荐给 AI 的开发提示词

```text
你正在为 EasyAdmin8-Laravel 开发模块。

请先阅读 docs/modules/ai-module-development-handbook.md。
必须遵守 module.json、生命周期、权限、菜单、迁移、版本、升级和回滚规则。

目标模块：
- name:
- title:
- admin_prefix:
- version:
- type:

请先输出模块设计，包括目录结构、module.json、菜单、权限节点、数据表、迁移、版本策略和验收清单。
设计通过后再写代码。
禁止修改宿主核心文件，除非明确说明原因和影响范围。
```

## 22. 最小可交付定义

一个模块只有满足以下条件，才算可交付：

- 有完整 `module.json`。
- 有清晰目录结构。
- 有后台菜单。
- 有权限节点。
- 有必要的迁移和回滚说明。
- 能被发现。
- 能通过管理员审核。
- 能安装。
- 能启用。
- 能禁用。
- 能保留数据卸载。
- 有版本和变更日志。
- 有测试或人工验收记录。

不满足以上任一项，AI 必须标记为未完成。

## 23. 宿主用户域接口规范

本章用于约束 AI 开发模块时如何调用宿主系统已经提供的用户端能力。模块可以扩展业务，但不得绕过宿主的会员、邀请、VIP、余额、分销、日志和通知服务直接改表。

### 23.1 总原则

- 模块调用宿主能力时，必须依赖 `App\Contracts\Modules\*Gateway`，不得直接依赖 `App\User\*Service` 或写核心用户表。
- 桌面端、移动端和第三方客户端统一使用 `/api/v1/*` Bearer API；`/user/*` 与 `/u/*` 仅供宿主旧 Web 会话页面兼容，不能作为新模块的稳定协议。
- 后台运营接口统一走动态后台路由 `/admin/user/{controller}/{action}`，实际前缀以 `config('admin.admin_alias_name')` 为准。
- `/api/v1` 业务接口必须经过 `auth:sanctum`、`api.active`、`api.module_active` 和对应 `api.ability:*`；模块不得自行解析或保存访问令牌。
- 后台接口必须依赖 EasyAdmin 后台登录、权限节点和 `session('admin.id')`，禁止模块伪造管理员 ID。
- 金额字段统一保留两位小数，所有余额变更必须形成流水。
- 所有审核类动作必须保留审核人、审核时间、状态和失败原因。
- 模块新增接口必须返回宿主兼容结构：后台表格接口使用 `{code,count,data,msg}`，普通动作使用 `{code,msg,data,url,wait,__token__}` 或现有 `success/error` 封装。
- 模块不得读取 `.env`、不得输出密钥、不得在日志中记录明文密码、完整激活码、完整手机号、完整邮箱或支付凭证敏感内容。

本章后续出现的 `App\User\*Service` 和 `/user/*` 是宿主内部实现及旧 Web 兼容说明。AI 开发独立模块时，以第 24 章的 Gateway 与 `/api/v1` 规范为准。

### 23.2 会员接口

会员接口覆盖注册、登录、退出、会话、找回密码和用户概览。

用户端接口：

| 方法 | 路径 | 用途 | 主要参数 | 备注 |
| --- | --- | --- | --- | --- |
| `POST` | `/user/register` | 用户注册 | `mobile` 或 `email`, `password`, `invite_code?`, `source_module?` | 注册时会创建会员账号、默认邀请码，并记录所属模块；未传 `source_module` 时默认为 `core`。 |
| `POST` | `/user/login` | 用户登录 | `account`, `password` | 支持邮箱或手机号；失败次数触发锁定策略。 |
| `POST` | `/user/logout` | 用户退出 | 无 | 清理用户会话。 |
| `GET` | `/user/session` | 查询当前会话 | 无 | 未登录返回错误。 |
| `POST` | `/user/password/forgot` | 发起找回密码 | `account` | 写入通知队列，按邮箱或手机号发送重置凭证。 |
| `POST` | `/user/password/reset` | 重置密码 | `account`, `password`, `token?`, `code?` | 必须校验重置凭证。 |
| `GET` | `/user/dashboard/summary` | 用户中心汇总 | 无 | 返回当前用户关键状态。 |

后台接口：

| 方法 | 路径 | 用途 | 备注 |
| --- | --- | --- | --- |
| `GET/POST` | `/admin/user/account/index` | 会员列表 | 表格查询、筛选、分页。 |
| `GET/POST` | `/admin/user/account/detail` | 会员详情 | 查看用户状态、VIP、余额、邀请等汇总。 |
| `POST` | `/admin/user/account/modify` | 修改会员字段 | 必须经过后台权限。 |

AI 模块规则：

- 需要创建会员时调用 `App\User\UserAuthService::register()`，不要直接插入 `user_account`。
- 模块自有注册入口必须传 `source_module`，值为模块 `module.json` 的 `name`；宿主默认注册入口使用 `core`。
- `source_module` 是会员来源归属字段，只记录“用户通过哪个模块的会员接口注册进来”，不得在后续登录、消费或邀请流程中随意覆盖。
- 需要登录时调用 `App\User\UserAuthService::login()`，不要自行写 session。
- 需要找回密码时调用 `App\User\PasswordResetService`，不要绕过通知队列直接发送明文密码。
- 模块保存用户扩展资料时，应使用模块自己的表，以 `user_id` 关联宿主会员，不要在核心会员表随意加字段。

### 23.3 邀请码接口

邀请码接口负责邀请关系、直推记录和二级关系基础数据。

用户端接口：

| 方法 | 路径 | 用途 | 主要参数 | 备注 |
| --- | --- | --- | --- | --- |
| `GET` | `/user/invite` | 邀请概览 | 无 | 返回邀请码、直推人数、二级人数。 |
| `GET` | `/user/invite/records` | 邀请记录 | `limit?` | 返回当前用户直推关系。 |

后台接口：

| 方法 | 路径 | 用途 |
| --- | --- | --- |
| `GET/POST` | `/admin/user/invite/index` | 邀请码列表 |
| `GET/POST` | `/admin/user/invite/relations` | 邀请关系列表 |

AI 模块规则：

- 默认邀请码由 `App\User\InviteService::createDefaultCode()` 维护。
- 注册绑定邀请关系必须走 `App\User\InviteService::bindRegistration()`。
- 模块不得手工改写 `parent_user_id`、`grandparent_user_id` 或邀请关系树。
- 需要读取用户邀请数据时优先使用 `inviteSummary()` 和 `inviteRecords()`。
- 邀请码展示给用户时可以展示完整码；写日志、通知和后台导出时必须脱敏。

### 23.4 VIP 接口

VIP 接口覆盖 VIP 状态查询、激活码兑换、套餐管理和激活码管理。

用户端接口：

| 方法 | 路径 | 用途 | 主要参数 | 备注 |
| --- | --- | --- | --- | --- |
| `GET` | `/user/vip` | 当前 VIP 状态 | 无 | 返回是否有效、等级、到期时间和记录数。 |
| `POST` | `/user/activation-code/redeem` | 兑换激活码 | `code` | 成功后发放 VIP；可触发二级分销佣金。 |

后台接口：

| 方法 | 路径 | 用途 |
| --- | --- | --- |
| `GET/POST` | `/admin/user/vip-plan/index` | VIP 套餐列表 |
| `GET/POST` | `/admin/user/vip-plan/add` | 新增套餐 |
| `GET/POST` | `/admin/user/vip-plan/edit` | 编辑套餐 |
| `POST` | `/admin/user/vip-plan/modify` | 修改套餐状态或字段 |
| `GET/POST` | `/admin/user/activation-code/index` | 激活码批次/列表 |
| `POST` | `/admin/user/activation-code/generate` | 生成激活码 |
| `GET/POST` | `/admin/user/activation-code/redemptions` | 兑换记录 |

AI 模块规则：

- 发放 VIP 必须调用 `App\User\VipService::grant($userId, $vipPlanId, $sourceType, $sourceId)`。
- 激活码兑换必须调用 `App\User\ActivationCodeService::redeem()`。
- 批量生成激活码必须调用 `ActivationCodeService::createBatch()` 和 `generateCodes()`。
- 激活码只保存 hash 和尾号，模块不得保存明文激活码到数据库或日志。
- 激活码失败会进入风控记录，模块不得吞掉失败原因。
- 模块新增付费订单后如需发放 VIP，应使用独立 `source_type/source_id`，保证可审计和可回溯。

### 23.5 余额接口

余额接口覆盖余额查询、流水查询、后台调账和提现前置能力。

用户端接口：

| 方法 | 路径 | 用途 | 主要参数 | 备注 |
| --- | --- | --- | --- | --- |
| `GET` | `/user/balance` | 当前余额 | 无 | 返回可用余额和冻结余额。 |
| `GET` | `/user/balance/ledger` | 余额流水 | `limit?` | 返回当前用户最近流水。 |

后台接口：

| 方法 | 路径 | 用途 | 主要参数 |
| --- | --- | --- | --- |
| `GET/POST` | `/admin/user/balance/index` | 余额流水列表 | 表格筛选参数 |
| `POST` | `/admin/user/balance/adjust` | 管理员调账 | `user_id`, `amount`, `reason` |

AI 模块规则：

- 所有余额入账必须调用 `App\User\BalanceLedgerService::credit()`。
- 所有余额扣减必须调用 `BalanceLedgerService::debit()`。
- 提现冻结必须调用 `BalanceLedgerService::freeze()`。
- 审核拒绝或失败解冻必须调用 `BalanceLedgerService::unfreeze()`。
- 打款完成结算冻结余额必须调用 `BalanceLedgerService::settleFrozen()`。
- 管理员调账必须调用 `BalanceLedgerService::adminAdjust()`，并写明原因。
- 模块不得直接修改 `available_balance`、`frozen_balance` 或删除 `user_balance_ledger`。
- 任何金额类接口必须防重复提交，建议用来源唯一键：`source_type + source_id + type`。

### 23.6 提现接口

提现接口和余额流水强绑定，必须保留申请、审核、打款、失败和拒绝完整链路。

用户端接口：

| 方法 | 路径 | 用途 | 主要参数 |
| --- | --- | --- | --- |
| `POST` | `/user/withdrawal/request` | 发起提现 | `amount`, `account` |
| `GET` | `/user/withdrawal` | 我的提现记录 | `limit?` |

后台接口：

| 方法 | 路径 | 用途 | 主要参数 |
| --- | --- | --- | --- |
| `GET/POST` | `/admin/user/withdrawal/index` | 提现列表 | 表格筛选参数 |
| `POST` | `/admin/user/withdrawal/approve` | 审核通过 | `id` |
| `POST` | `/admin/user/withdrawal/reject` | 审核拒绝 | `id`, `reason` |
| `POST` | `/admin/user/withdrawal/payout` | 确认打款 | `id`, `method`, `transaction_id`, `proof?` |
| `POST` | `/admin/user/withdrawal/payoutFail` | 标记打款失败 | `id`, `error` |
| `GET/POST` | `/admin/user/withdrawal/stats` | 提现统计 | 无 |

AI 模块规则：

- 用户提现必须调用 `App\User\WithdrawalService::request()`，由服务自动冻结余额。
- 审核通过、拒绝、确认打款和打款失败必须调用 `WithdrawalService` 对应方法。
- 拒绝提现必须填写原因；确认打款必须填写打款方式和流水号。
- 打款流水号必须防重复，模块不得绕过 `user_withdrawal_payout_reference`。
- 提现状态只能按 `pending -> approved -> paid`、`pending -> rejected`、`approved -> payout_failed -> paid/rejected` 等服务允许路径流转。

### 23.7 分销接口

分销接口基于邀请关系，当前支持二级分销佣金和管理员审核结算。

后台接口：

| 方法 | 路径 | 用途 | 主要参数 |
| --- | --- | --- | --- |
| `GET/POST` | `/admin/user/commission/index` | 佣金列表 | 表格筛选参数 |
| `POST` | `/admin/user/commission/approve` | 单笔审核通过 | `id` |
| `POST` | `/admin/user/commission/reject` | 单笔审核拒绝 | `id`, `reason` |
| `POST` | `/admin/user/commission/batchApprove` | 批量审核通过 | `ids` |
| `POST` | `/admin/user/commission/batchReject` | 批量审核拒绝 | `ids`, `reason` |
| `GET/POST` | `/admin/user/commission/stats` | 佣金统计 | 无 |

服务接口：

| 服务方法 | 用途 |
| --- | --- |
| `AffiliateService::createForActivationCode()` | 激活码兑换成功后创建一、二级佣金。 |
| `AffiliateService::createForVipOrder()` | VIP 订单成功后按比例创建一、二级佣金。 |
| `AffiliateService::approve()` | 管理员审核通过并入账余额。 |
| `AffiliateService::reject()` | 管理员审核拒绝并保留原因。 |
| `AffiliateService::batchApprove()` | 批量审核通过。 |
| `AffiliateService::batchReject()` | 批量审核拒绝。 |
| `AffiliateService::reverse()` | 已结算佣金冲正。 |

AI 模块规则：

- 分销只允许二级：一级邀请人和二级邀请人。
- 佣金创建必须依赖已有邀请关系，不允许模块临时指定受益人。
- 佣金状态默认 `pending`，必须由管理员审核后才可通过 `BalanceLedgerService::credit()` 入账。
- 拒绝佣金必须填写原因。
- 订单退款、撤销或作弊处理需要冲正时，必须调用 `AffiliateService::reverse()` 并写明原因。
- 模块不得直接把分销金额写入余额，也不得跳过人工审核自动结算。

### 23.8 日志接口

日志接口用于安全审计、风控运营和问题追踪，原则是只追加、不删除、不覆盖。

后台接口：

| 方法 | 路径 | 用途 |
| --- | --- | --- |
| `GET/POST` | `/admin/user/security-log/index` | 用户安全日志列表 |
| `GET/POST` | `/admin/user/risk-event/index` | 用户风控事件列表 |
| `POST` | `/admin/user/risk-event/review` | 风控事件审核 |

服务接口：

| 服务 | 用途 |
| --- | --- |
| `App\User\UserSecurityLogService` | 写入登录、密码、账号相关安全日志。 |
| `App\User\RiskService` | 写入和审核注册、激活码、提现、邀请等风险事件。 |

AI 模块规则：

- 安全敏感动作必须写安全日志，包括登录失败、密码重置、账号状态变化、绑定/解绑、提现账号变化。
- 风险动作必须写风控事件，包括重复失败、异常 IP、异常邀请、异常提现、激活码失败。
- 后台日志列表为只读，模块不得提供删除、编辑、导出敏感日志的入口。
- 日志中必须记录 `user_id`、事件类型、IP、必要上下文和时间。
- 日志上下文必须脱敏，不记录密码、完整 token、完整激活码、完整手机号、完整邮箱。

### 23.9 通知接口

通知接口采用 outbox 队列，避免业务请求中直接阻塞发送。

后台接口：

| 方法 | 路径 | 用途 |
| --- | --- | --- |
| `GET/POST` | `/admin/user/notification-outbox/index` | 通知队列列表 |
| `GET/POST` | `/admin/user/notification-outbox/stats` | 通知队列统计 |

命令行接口：

| 命令 | 用途 |
| --- | --- |
| `php artisan user:notifications:send --limit=50` | 发送待发送通知。 |
| `php artisan user:notifications:purge --days=30 --limit=500` | 清理已发送的历史通知。 |

服务接口：

| 服务 | 用途 |
| --- | --- |
| `App\User\NotificationOutboxDispatcher::sendPending()` | 发送待处理通知，失败后延迟重试。 |
| `App\User\NotificationOutboxMaintenanceService::summary()` | 统计通知队列状态。 |
| `App\User\NotificationOutboxMaintenanceService::purgeSentOlderThan()` | 清理历史已发送通知。 |

AI 模块规则：

- 模块需要发送邮件、短信或站内通知时，应写入通知 outbox 或复用宿主通知服务，不要在业务事务中直接调用外部服务。
- 通知必须保存类型、渠道、收件人脱敏值、主题、payload、状态、尝试次数、下次可发送时间。
- 发送失败必须保留 `last_error`，并设置重试时间。
- 通知 payload 不得保存密码、完整 token、完整激活码或支付敏感凭证。
- 用户可见通知文案必须中文化，后台失败原因必须可读。

### 23.10 AI 开发验收清单

AI 完成任何调用宿主用户域能力的模块后，必须逐项确认：

- 是否使用宿主服务类，而不是直接改核心表。
- 模块注册会员时是否传入并保留了 `source_module`。
- 是否保留会员会话校验和后台管理员权限校验。
- 是否为余额、提现、佣金生成完整流水和审核记录。
- 是否将佣金保持为管理员审核后结算。
- 是否对邀请码、激活码、token、手机号、邮箱做了脱敏。
- 是否有失败状态、错误原因、重试或人工处理入口。
- 是否写入安全日志或风控事件。
- 是否避免在事务中直接调用外部通知服务。
- 是否提供可回滚迁移和不破坏既有用户数据的升级策略。

## 24. 稳定模块平台协议

### 24.1 不可变版本与管理员审核

- ZIP 上传只暂存到 `storage/modules/releases/{module}/{version}-{sha256}`，不得覆盖正在运行的模块目录。
- 每个版本必须独立审核；审核记录绑定 `module + version + artifact_hash`。
- `partner`、`community` 制品在生产环境必须有宿主 HMAC 签名；签名密钥只能来自服务端环境变量。
- 审核通过后，由 `system_module.active_release_id` 和 `system_module.path` 原子切换活动版本。
- 回滚只能切换到历史已审核制品；不可逆迁移必须阻止自动回滚。
- 普通请求可按 `MODULE_INTEGRITY_CACHE_SECONDS` 短时复用制品哈希校验；部署、巡检和故障排查必须运行 `php artisan system:module-health` 强制重新计算完整哈希。
- 模块制品是受信任的进程内 PHP 代码，不是沙箱。管理员必须审核文件访问、网络访问、命令执行、反射、动态包含和依赖代码。
- 禁用或卸载模块会隐藏其托管菜单并撤销该模块全部 API 设备会话；业务数据保留。
- 生产环境中的 `installed`、`enabled`、`disabled` 旧模块必须先运行 `module:release-adopt-enabled --admin-id={管理员ID}` 纳入不可变发布历史；未绑定活动制品的模块不能启用或加载。

### 24.2 module.json API 声明

```json
{
  "api": {
    "abilities": ["profile:read", "vip:read", "module:example_module"],
    "quotas": {"content.parse": 200, "content.rewrite": 100}
  }
}
```

- 只能声明 `config/user_api.php` 的 `allowed_abilities` 中已有能力。
- 必须包含 `module:{module_name}` 能力。
- 配额必须是正整数；服务端配置可以进一步收紧，模块不能自行提高生产配额。

### 24.3 宿主 Gateway

| 契约 | 用途 |
| --- | --- |
| `MemberGateway` | 读取标准会员资料。 |
| `InvitationGateway` | 邀请概览和直推记录。 |
| `VipGateway` | VIP 查询和可审计发放。 |
| `ActivationCodeGateway` | 按模块归属创建、生成和兑换激活码。 |
| `BalanceGateway` | 余额、流水、入账和扣减。 |
| `AffiliateGateway` | 按宿主邀请关系创建二级分销佣金。 |
| `AuditGateway` | 写入宿主安全日志。 |
| `NotificationGateway` | 向宿主通知 outbox 写入模块归属消息。 |

模块服务构造函数必须类型提示契约接口。禁止模块 `new Host*Gateway()`、直接解析实现类或绕过 Gateway 改宿主表。

### 24.4 Bearer API

| 方法 | 路径 | 说明或能力 |
| --- | --- | --- |
| `POST` | `/api/v1/auth/register` | 注册并签发访问令牌和旋转刷新令牌。 |
| `POST` | `/api/v1/auth/login` | 登录指定模块；账号归属必须匹配。 |
| `POST` | `/api/v1/auth/refresh` | 单次使用刷新令牌。 |
| `GET` | `/api/v1/auth/profile` | `profile:read` |
| `POST` | `/api/v1/auth/logout` | 撤销当前设备会话。 |
| `GET` | `/api/v1/me/vip` | `vip:read` |
| `GET` | `/api/v1/me/invitations` | `invite:read` |
| `GET` | `/api/v1/me/balance` | `balance:read` |
| `GET` | `/api/v1/me/ledger?limit=20` | `balance:read` |

模块业务接口使用 `/api/v1/modules/{module-slug}/*`。模块禁用后，签发、刷新和业务请求都必须返回 `module_unavailable`，并撤销对应设备会话。

### 24.5 数据归属

- 会员归属使用 `user_account.source_module`，注册后不得覆盖。
- 激活码批次和兑换记录使用 `owner_module`；模块只能读取、生成和兑换自己的批次。
- 模块不能信任请求体中的 `source_module` 或 `owner_module`，必须由服务端模块常量或 Gateway 上下文注入。
- 模块列表、详情、统计、导出和审核都必须带归属过滤，不能只在页面层过滤。

### 24.6 幂等、配额与错误

- 写操作和高成本操作必须发送 `X-Request-ID`，格式为 8 到 80 位字母、数字、点、下划线、冒号或短横线。
- 相同用户、模块、操作、请求 ID 和载荷只执行一次；完成后重放原结果。
- 同一请求 ID 对应不同载荷返回 `409 idempotency_conflict`；仍在处理返回 `409 request_in_progress`。
- 超过日配额返回 `429 quota_exceeded`。
- 响应和审计日志必须包含请求 ID；审计日志还要记录稳定错误码和耗时。
- 客户端遇到 401 最多刷新重试一次，重试必须复用原请求 ID。

### 24.7 AI 交付检查

- 新版本是否生成独立制品并重新由管理员审核。
- 是否只依赖 Gateway 契约，没有直接访问宿主核心表和内部服务。
- 是否声明最小能力、外部域名、依赖、冲突和配额。
- 所有列表、详情、统计和写操作是否按模块归属隔离。
- 所有写操作和高成本操作是否具备请求 ID、幂等、配额、稳定错误码和审计关联。
- 模块禁用、卸载、升级失败和回滚时，菜单、令牌、迁移与数据状态是否符合预期。
- 是否有红绿测试、变更日志、升级说明、回滚说明和管理员审核清单。
