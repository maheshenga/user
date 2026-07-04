<?php

namespace App\Http\Controllers\admin\system;

use App\Http\Controllers\common\AdminController;
use App\Http\Services\annotation\ControllerAnnotation;
use App\Http\Services\annotation\NodeAnnotation;
use App\Models\SystemModule;
use App\Models\SystemModuleLog;
use App\Modules\ModuleInstaller;
use App\Modules\ModuleManager;
use App\Modules\ModuleRepository;
use App\Modules\ModuleRollbacker;
use App\Modules\ModuleUpgrader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

#[ControllerAnnotation(title: '模块中心')]
class ModuleController extends AdminController
{
    public function initialize()
    {
        parent::initialize();
        $this->model = new SystemModule();
    }

    #[NodeAnnotation(title: '列表', auth: true)]
    public function index(): View|JsonResponse
    {
        if (! request()->ajax() && ! request()->expectsJson()) {
            return $this->fetch();
        }

        [$page, $limit, $where] = $this->buildTableParams();
        $query = SystemModule::query()->where($where);

        return json([
            'code' => 0,
            'msg' => '',
            'count' => (clone $query)->count(),
            'data' => $query
                ->orderBy($this->order, $this->orderDirection)
                ->paginate($limit, ['*'], 'page', (int) $page)
                ->items(),
        ]);
    }

    #[NodeAnnotation(title: '详情', auth: true)]
    public function detail(): View|JsonResponse
    {
        $module = $this->findModule();
        if ($module === null) {
            return $this->error('模块不存在');
        }

        return $this->fetch('', [
            'module' => $module,
            'metadata' => $module->config_json ?: [],
        ]);
    }

    #[NodeAnnotation(title: '操作日志', auth: true)]
    public function logs(): View|JsonResponse
    {
        if (! request()->ajax() && ! request()->expectsJson()) {
            return $this->fetch('', ['module' => request()->input('module', '')]);
        }

        [$page, $limit, $where] = $this->buildTableParams(['module']);
        $module = request()->input('module');
        $query = SystemModuleLog::query()->where($where);
        if ($module !== null && $module !== '') {
            $query->where('module', (string) $module);
        }

        return json([
            'code' => 0,
            'msg' => '',
            'count' => (clone $query)->count(),
            'data' => $query
                ->orderBy($this->order, $this->orderDirection)
                ->paginate($limit, ['*'], 'page', (int) $page)
                ->items(),
        ]);
    }

    #[NodeAnnotation(title: '上传安装包', auth: true)]
    public function upload(): View
    {
        return $this->fetch();
    }

    #[NodeAnnotation(title: '发现模块', auth: true)]
    public function discover(): Response|JsonResponse|View
    {
        if ($response = $this->requirePost()) {
            return $response;
        }

        try {
            $count = DB::transaction(function (): int {
                $count = 0;
                $manager = app(ModuleManager::class);
                $repository = app(ModuleRepository::class);
                foreach ($manager->discover() as $manifest) {
                    $repository->upsertDiscovered($manifest);
                    $count++;
                }

                return $count;
            });
        } catch (Throwable $exception) {
            return $this->error($exception->getMessage());
        }

        return $this->success("发现 {$count} 个模块");
    }

    #[NodeAnnotation(title: '安装模块', auth: true)]
    public function install(): Response|JsonResponse|View
    {
        return $this->runLifecycleAction(fn () => app(ModuleInstaller::class)->install($this->moduleName(), $this->actorId()));
    }

    #[NodeAnnotation(title: 'Approve module', auth: true)]
    public function approve(): Response|JsonResponse|View
    {
        return $this->runLifecycleAction(fn () => app(ModuleRepository::class)->approve($this->moduleName(), $this->actorId()));
    }

    #[NodeAnnotation(title: 'Reject module', auth: true)]
    public function reject(): Response|JsonResponse|View
    {
        return $this->runLifecycleAction(function (): void {
            $reason = trim((string) request()->input('reason', 'Rejected by administrator.'));
            app(ModuleRepository::class)->reject(
                $this->moduleName(),
                $reason === '' ? 'Rejected by administrator.' : $reason,
                $this->actorId()
            );
        });
    }

    #[NodeAnnotation(title: '启用模块', auth: true)]
    public function enable(): Response|JsonResponse|View
    {
        return $this->runLifecycleAction(fn () => app(ModuleInstaller::class)->enable($this->moduleName(), $this->actorId()));
    }

    #[NodeAnnotation(title: '禁用模块', auth: true)]
    public function disable(): Response|JsonResponse|View
    {
        return $this->runLifecycleAction(fn () => app(ModuleInstaller::class)->disable($this->moduleName(), $this->actorId()));
    }

    #[NodeAnnotation(title: '卸载模块', auth: true)]
    public function uninstall(): Response|JsonResponse|View
    {
        return $this->runLifecycleAction(fn () => app(ModuleInstaller::class)->uninstallPreserve($this->moduleName(), $this->actorId()));
    }

    #[NodeAnnotation(title: '本地升级', auth: true)]
    public function upgradeLocal(): Response|JsonResponse|View
    {
        return $this->runLifecycleAction(fn () => app(ModuleUpgrader::class)->upgradeLocal($this->moduleName(), $this->actorId()));
    }

    #[NodeAnnotation(title: '上传升级', auth: true)]
    public function upgradeZip(): Response|JsonResponse|View
    {
        if ($response = $this->requirePost()) {
            return $response;
        }

        $file = request()->file('file');
        if (! $this->isValidZipUpload($file)) {
            return $this->error('请上传模块 zip 文件');
        }

        $dir = storage_path('modules/uploads');
        if (! is_dir($dir) && ! mkdir($dir, 0777, true) && ! is_dir($dir)) {
            return $this->error("无法创建上传目录: {$dir}");
        }

        $path = $dir.DIRECTORY_SEPARATOR.uniqid('module_', true).'.zip';
        $file->move($dir, basename($path));

        try {
            $expectedName = request()->input('name');
            app(ModuleUpgrader::class)->upgradeZip($path, $expectedName === '' ? null : $expectedName, $this->actorId());
        } catch (Throwable $exception) {
            return $this->error($exception->getMessage());
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        return $this->success('操作成功');
    }

    #[NodeAnnotation(title: '回滚模块', auth: true)]
    public function rollback(): Response|JsonResponse|View
    {
        return $this->runLifecycleAction(fn () => app(ModuleRollbacker::class)->rollback($this->moduleName(), $this->actorId()));
    }

    private function findModule(): ?SystemModule
    {
        $name = request()->input('name', request()->input('module', ''));
        if ($name === '') {
            return null;
        }

        return SystemModule::query()->where('name', (string) $name)->first();
    }

    private function moduleName(): string
    {
        return (string) request()->input('name', request()->input('module', ''));
    }

    private function actorId(): ?int
    {
        $id = session('admin.id');

        return $id === null ? null : (int) $id;
    }

    private function requirePost(): ?JsonResponse
    {
        if (request()->isMethod('post')) {
            return null;
        }

        return response()->json([
            'code' => 0,
            'msg' => 'Lifecycle actions require POST.',
            'data' => [],
            'url' => '',
            'wait' => 3,
            '__token__' => csrf_token(),
        ]);
    }

    private function isValidZipUpload(mixed $file): bool
    {
        if ($file === null || ! $file->isValid()) {
            return false;
        }

        $size = $file->getSize();
        if (! is_int($size) || $size > 20 * 1024 * 1024) {
            return false;
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $mime = strtolower((string) $file->getMimeType());
        if ($extension !== 'zip' || ! in_array($mime, ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'], true)) {
            return false;
        }

        $zip = new \ZipArchive();
        $path = $file->getRealPath();
        if ($path === false) {
            return false;
        }

        $opened = $zip->open($path);
        if ($opened === true) {
            $zip->close();
        }

        return $opened === true;
    }

    /**
     * @param callable(): void $operation
     */
    private function runLifecycleAction(callable $operation): Response|JsonResponse|View
    {
        if ($response = $this->requirePost()) {
            return $response;
        }

        if ($this->moduleName() === '') {
            return $this->error('缺少模块名称');
        }

        try {
            $operation();
        } catch (Throwable $exception) {
            return $this->error($exception->getMessage());
        }

        return $this->success('操作成功');
    }
}
