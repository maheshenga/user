<?php

namespace App\Http\Controllers\admin\system;

use App\Http\Controllers\common\AdminController;
use App\Http\Services\annotation\ControllerAnnotation;
use App\Http\Services\annotation\NodeAnnotation;
use App\Models\SystemModule;
use App\Models\SystemModuleLog;
use App\Models\SystemModuleRelease;
use App\Modules\ModuleInstaller;
use App\Modules\ModuleManager;
use App\Modules\ModuleReleaseManager;
use App\Modules\ModuleReleaseSigner;
use App\Modules\ModuleRepository;
use App\Modules\ModuleReviewService;
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
    private const LIST_FILTER_FIELDS = [
        'id', 'name', 'title', 'vendor', 'version', 'type', 'trust_level', 'status',
        'admin_prefix', 'installed_at', 'enabled_at', 'created_at', 'updated_at',
    ];

    private const LOG_FILTER_FIELDS = [
        'id', 'admin_id', 'module', 'action', 'old_state', 'new_state', 'result',
        'started_at', 'finished_at', 'created_at', 'updated_at',
    ];

    public function initialize()
    {
        parent::initialize();
        $this->model = new SystemModule;
    }

    #[NodeAnnotation(title: '列表', auth: true)]
    public function index(): View|JsonResponse
    {
        if (! request()->ajax() && ! request()->expectsJson()) {
            return $this->fetch();
        }

        [$page, $limit, $where] = $this->buildTableParams([], self::LIST_FILTER_FIELDS);
        $query = SystemModule::query()->where($where);
        $items = $query
            ->orderBy($this->order, $this->orderDirection)
            ->paginate($limit, ['*'], 'page', (int) $page)
            ->items();
        $pendingIds = collect($items)->pluck('pending_release_id')->filter()->values();
        $pending = $pendingIds->isEmpty()
            ? collect()
            : SystemModuleRelease::query()->whereIn('id', $pendingIds)->get()->keyBy('id');
        foreach ($items as $module) {
            $release = $module->pending_release_id === null ? null : $pending->get($module->pending_release_id);
            $module->setAttribute('pending_version', $release?->version);
            $module->setAttribute('pending_release_status', $release?->status);
            $module->setAttribute('pending_trust_level', $release?->trust_level);
        }

        return json([
            'code' => 0,
            'msg' => '',
            'count' => (clone $query)->count(),
            'data' => $items,
        ]);
    }

    #[NodeAnnotation(title: '详情', auth: true)]
    public function detail(): View|JsonResponse
    {
        $module = $this->findModule();
        if ($module === null) {
            return $this->error('模块不存在');
        }

        $activeRelease = $module->active_release_id === null
            ? null
            : SystemModuleRelease::query()->find($module->active_release_id);
        $pendingRelease = $module->pending_release_id === null
            ? null
            : SystemModuleRelease::query()->find($module->pending_release_id);

        return $this->fetch('', [
            'module' => $module,
            'metadata' => $module->config_json ?: [],
            'activeRelease' => $activeRelease,
            'pendingRelease' => $pendingRelease,
            'reviewDetails' => $this->reviewDetails($module, $activeRelease, $pendingRelease),
        ]);
    }

    #[NodeAnnotation(title: '操作日志', auth: true)]
    public function logs(): View|JsonResponse
    {
        if (! request()->ajax() && ! request()->expectsJson()) {
            return $this->fetch('', ['module' => request()->input('module', '')]);
        }

        [$page, $limit, $where] = $this->buildTableParams(['module'], self::LOG_FILTER_FIELDS);
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

    #[NodeAnnotation(title: '审核通过模块', auth: true)]
    public function approve(): Response|JsonResponse|View
    {
        return $this->runLifecycleAction(fn () => app(ModuleReviewService::class)->approve($this->moduleName(), $this->actorId()));
    }

    #[NodeAnnotation(title: '审核拒绝模块', auth: true)]
    public function reject(): Response|JsonResponse|View
    {
        return $this->runLifecycleAction(function (): void {
            $reason = trim((string) request()->input('reason', '管理员审核拒绝。'));
            app(ModuleReviewService::class)->reject(
                $this->moduleName(),
                $reason === '' ? '管理员审核拒绝。' : $reason,
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
            app(ModuleReleaseManager::class)->stageZip($path, $expectedName === '' ? null : $expectedName, $this->actorId());
        } catch (Throwable $exception) {
            return $this->error($exception->getMessage());
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        return $this->success('模块版本已暂存，请审核通过后安装或升级');
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

    public function setOrder(): static
    {
        $tableOrder = trim((string) request()->get('tableOrder', ''));
        if ($tableOrder === '') {
            return $this;
        }

        $parts = preg_split('/\s+/', $tableOrder) ?: [];
        $allowed = $this->action === 'logs' ? self::LOG_FILTER_FIELDS : self::LIST_FILTER_FIELDS;
        if (count($parts) !== 2 || ! in_array($parts[0], $allowed, true)) {
            $this->order = 'id';
            $this->orderDirection = 'desc';

            return $this;
        }

        $this->order = $parts[0];
        $direction = strtolower($parts[1]);
        $this->orderDirection = in_array($direction, ['asc', 'desc'], true) ? $direction : 'desc';

        return $this;
    }

    private function moduleName(): string
    {
        return (string) request()->input('name', request()->input('module', ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewDetails(
        SystemModule $module,
        ?SystemModuleRelease $activeRelease,
        ?SystemModuleRelease $pendingRelease
    ): array {
        $activeManifest = is_array($activeRelease?->manifest_json)
            ? $activeRelease->manifest_json
            : (is_array($module->config_json) ? $module->config_json : []);
        $pendingManifest = is_array($pendingRelease?->manifest_json)
            ? $pendingRelease->manifest_json
            : [];
        $historyQuery = SystemModuleRelease::query()->where('module', $module->name);
        $historyTotal = (clone $historyQuery)->count();

        return [
            'active' => $this->releaseSummary($activeRelease),
            'pending' => $this->releaseSummary($pendingRelease),
            'manifest_diff' => $this->manifestDiff(
                $activeManifest,
                $pendingRelease === null ? $activeManifest : $pendingManifest
            ),
            'active_manifest' => $activeManifest,
            'pending_manifest' => $pendingManifest,
            'release_history_total' => $historyTotal,
            'release_history' => $historyQuery
                ->orderByDesc('id')
                ->limit(100)
                ->get()
                ->map(fn (SystemModuleRelease $release): array => $this->releaseSummary($release) ?? [])
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function releaseSummary(?SystemModuleRelease $release): ?array
    {
        if ($release === null) {
            return null;
        }

        $signatureState = 'unsigned';
        if (is_string($release->signature_hash) && $release->signature_hash !== '') {
            try {
                $signatureState = app(ModuleReleaseSigner::class)->verify($release) ? 'valid' : 'invalid';
            } catch (Throwable) {
                $signatureState = 'invalid';
            }
        }

        return [
            'id' => (int) $release->id,
            'version' => (string) $release->version,
            'status' => (string) $release->status,
            'source_type' => (string) $release->source_type,
            'trust_level' => (string) $release->trust_level,
            'artifact_hash' => (string) $release->artifact_hash,
            'signature_hash' => $release->signature_hash,
            'signature_state' => $signatureState,
            'uploaded_by' => $release->uploaded_by === null ? null : (int) $release->uploaded_by,
            'reviewed_by' => $release->reviewed_by === null ? null : (int) $release->reviewed_by,
            'reviewed_at' => $release->reviewed_at?->toDateTimeString(),
            'review_reason' => $release->review_reason,
            'activated_at' => $release->activated_at?->toDateTimeString(),
            'created_at' => $release->created_at?->toDateTimeString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $active
     * @param  array<string, mixed>  $pending
     * @return array<string, array<string, mixed>>
     */
    private function manifestDiff(array $active, array $pending): array
    {
        $activeApi = is_array($active['api'] ?? null) ? $active['api'] : [];
        $pendingApi = is_array($pending['api'] ?? null) ? $pending['api'] : [];

        return [
            'permissions' => $this->listDiff($active['permissions'] ?? [], $pending['permissions'] ?? []),
            'api_abilities' => $this->listDiff($activeApi['abilities'] ?? [], $pendingApi['abilities'] ?? []),
            'external_domains' => $this->listDiff($active['external_domains'] ?? [], $pending['external_domains'] ?? []),
            'dependencies' => $this->mapDiff($active['dependencies'] ?? [], $pending['dependencies'] ?? []),
            'conflicts' => $this->mapDiff($active['conflicts'] ?? [], $pending['conflicts'] ?? []),
            'api_quotas' => $this->mapDiff($activeApi['quotas'] ?? [], $pendingApi['quotas'] ?? []),
        ];
    }

    /**
     * @return array{added: array<int, string>, removed: array<int, string>, changed: array<string, never>}
     */
    private function listDiff(mixed $active, mixed $pending): array
    {
        $active = array_values(array_unique(array_filter(is_array($active) ? $active : [], 'is_string')));
        $pending = array_values(array_unique(array_filter(is_array($pending) ? $pending : [], 'is_string')));
        sort($active);
        sort($pending);

        return [
            'added' => array_values(array_diff($pending, $active)),
            'removed' => array_values(array_diff($active, $pending)),
            'changed' => [],
        ];
    }

    /**
     * @return array{added: array<string, mixed>, removed: array<string, mixed>, changed: array<string, array{from: mixed, to: mixed}>}
     */
    private function mapDiff(mixed $active, mixed $pending): array
    {
        $active = is_array($active) ? $active : [];
        $pending = is_array($pending) ? $pending : [];
        $result = ['added' => [], 'removed' => [], 'changed' => []];

        foreach ($pending as $key => $value) {
            if (! array_key_exists($key, $active)) {
                $result['added'][(string) $key] = $value;
            } elseif ($active[$key] !== $value) {
                $result['changed'][(string) $key] = ['from' => $active[$key], 'to' => $value];
            }
        }
        foreach ($active as $key => $value) {
            if (! array_key_exists($key, $pending)) {
                $result['removed'][(string) $key] = $value;
            }
        }

        ksort($result['added']);
        ksort($result['removed']);
        ksort($result['changed']);

        return $result;
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
            'msg' => '模块生命周期操作必须使用 POST 请求。',
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

        $zip = new \ZipArchive;
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
     * @param  callable(): void  $operation
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
