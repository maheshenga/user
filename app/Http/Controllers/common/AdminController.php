<?php

namespace App\Http\Controllers\common;

use App\Http\Curd;
use App\Http\JumpTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AdminController extends Controller
{
    use JumpTrait;
    use Curd;

    /**
     * 是否为演示环境
     * @var bool
     */
    protected bool $isDemo = false;

    /**
     * @Model
     */
    protected object $model;

    /**
     * @var string
     */
    public string $order = 'id';

    /**
     * @var string
     */
    public string $orderDirection = 'desc';

    /**
     * 过滤节点更新
     * @var array
     */
    protected array $ignoreNode = [];

    /**
     * 不导出的字段信息
     * @var array
     */
    protected array $noExportFields = ['delete_time', 'update_time'];

    /**
     * @var string
     */
    public string $secondary = '';

    /**
     * @var string
     */
    public string $controller = '';

    /**
     * @var string
     */
    public string $action = '';

    /**
     * @var array
     */
    public array $adminConfig = [];

    protected function initialize()
    {
        $parameters           = request()->route()->parameters ?? [];
        $this->adminConfig    = $adminConfig = config('admin');
        $this->isDemo         = config('easyadmin.IS_DEMO', false);
        $secondary            = $parameters['secondary'] ?? '';
        $controller           = trim((string) ($parameters['controllerPath'] ?? $parameters['controller'] ?? 'index'), '/');
        $action               = $parameters['action'] ?? 'index';
        $this->secondary      = $secondary;
        $this->controller     = $controller;
        $this->action         = $action;
        $controllerPath       = strtolower($controller);
        $jsBasePath           = ($secondary ? "{$secondary}/" : '') . $controllerPath;
        $moduleManifest       = $secondary ? app(\App\Modules\ModuleManager::class)->enabledByPrefix($secondary) : null;
        if ($moduleManifest) {
            $thisControllerJsPath = "/module-assets/{$secondary}/js/" . $controllerPath . ".js";
            $autoloadJs = file_exists($moduleManifest->assetsPath() . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $controllerPath) . '.js');
        } else {
            $thisControllerJsPath = "admin/js/{$jsBasePath}.js";
            $autoloadJs = file_exists(public_path('static/' . $thisControllerJsPath));
        }
        $adminModuleName      = $adminConfig['admin_alias_name'];
        $isSuperAdmin         = session('admin.id') == $adminConfig['super_admin_id'];
        $version              = cache('version');
        if (empty($version)) {
            $version = sysconfig('site', 'site_version');
            cache('site_version', $version);
            Cache::set('version', $version, 3600);
        }
        $data = [
            'adminModuleName'      => $adminModuleName,
            'thisController'       => $controller,
            'thisAction'           => $action,
            'thisRequest'          => "{$adminModuleName}/{$controller}/{$action}",
            'thisControllerJsPath' => $thisControllerJsPath,
            'autoloadJs'           => $autoloadJs,
            'isSuperAdmin'         => $isSuperAdmin,
            'isDemo'               => $this->isDemo,
            'version'              => config('app.debug') ? time() : $version,
            'adminUploadUrl'       => __url('ajax/upload', [], false),
            'adminEditor'          => sysconfig('site', 'editor_type') ?: 'wangEditor',
            'iframeOpenTop'        => sysconfig('site', 'iframe_open_top') ?: 0,
        ];
        $this->assign($data);
        $this->setOrder();
    }

    /**
     * 初始化排序
     * @return $this
     */
    public function setOrder(): static
    {
        $tableOrder = request()->get('tableOrder', '');
        if (!empty($tableOrder)) {
            [$orderField, $orderType] = explode(' ', $tableOrder);
            $this->order          = $orderField;
            $this->orderDirection = $orderType;
        }
        return $this;
    }

    /**
     * @param array $args
     */
    public function assign(array $args = [])
    {
        \Illuminate\Support\Facades\View::share($args);
    }

    public function fetch(string $template = '', array $args = []): View
    {
        if (empty($template)) {
            $controllerViewPath = str_replace('/', '.', $this->controller);
            $basePath = ".{$controllerViewPath}.{$this->action}";
            if ($this->secondary && app(\App\Modules\ModuleManager::class)->enabledByPrefix($this->secondary)) {
                $moduleTemplate = 'modules.' . $this->secondary . '::' . $controllerViewPath . '.' . $this->action;
                if (view()->exists($moduleTemplate)) {
                    $template = $moduleTemplate;
                }
            }
            if ($this->secondary) {
                $template = $template ?: 'admin.' . $this->secondary . $basePath;
            } elseif ($template === '') {
                $template = 'admin' . $basePath;
            }
        }
        return view($template, $args);
    }

    /**
     * 构建请求参数
     * @param array $excludeFields 忽略构建搜索的字段
     * @return array
     */
    protected function buildTableParams(array $excludeFields = [], array $allowedFields = []): array
    {
        $get     = request()->input();
        $page    = !empty($get['page']) ? $get['page'] : 1;
        $limit   = !empty($get['limit']) ? $get['limit'] : 15;
        $filters = !empty($get['filter']) ? htmlspecialchars_decode($get['filter']) : '{}';
        $ops     = !empty($get['op']) ? htmlspecialchars_decode($get['op']) : '{}';
        // json转数组
        $filters  = json_decode($filters, true);
        $ops      = json_decode($ops, true);
        $filters  = is_array($filters) ? $filters : [];
        $ops      = is_array($ops) ? $ops : [];
        $where    = [];
        $excludes = [];

        foreach ($filters as $key => $val) {
            if (! is_string($key) || preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $key) !== 1) {
                continue;
            }
            if ($allowedFields !== [] && ! in_array($key, $allowedFields, true)) {
                continue;
            }
            if (in_array($key, $excludeFields)) {
                $excludes[$key] = $val;
                continue;
            }
            $op = !empty($ops[$key]) ? $ops[$key] : '%*%';

            switch (strtolower($op)) {
                case '=':
                    $where[] = [$key, '=', $val];
                    break;
                case '%*%':
                    $where[] = [$key, 'LIKE', "%{$val}%"];
                    break;
                case '*%':
                    $where[] = [$key, 'LIKE', "{$val}%"];
                    break;
                case '%*':
                    $where[] = [$key, 'LIKE', "%{$val}"];
                    break;
                case 'in':
                    $values = $this->integerFilterValues($val);
                    if ($values !== []) {
                        $where[] = [DB::raw($key.' IN ('.implode(',', $values).')'), 1];
                    }
                    break;
                case 'find_in_set':
                    $value = is_scalar($val) ? trim((string) $val) : '';
                    if (ctype_digit($value)) {
                        $where[] = [DB::raw('FIND_IN_SET('.(int) $value.','.$key.')'), 1];
                    }
                    break;
                case 'range':
                    $range = is_string($val) ? explode(' - ', $val, 2) : [];
                    if (count($range) !== 2) {
                        break;
                    }
                    [$beginTime, $endTime] = $range;
                    $where[] = [$key, '>=', strtotime($beginTime)];
                    $where[] = [$key, '<=', strtotime($endTime)];
                    break;
                case 'datetime':
                    $range = is_string($val) ? explode(' - ', $val, 2) : [];
                    if (count($range) !== 2) {
                        break;
                    }
                    [$beginTime, $endTime] = $range;
                    $where[] = [$key, '>=', $beginTime];
                    $where[] = [$key, '<=', $endTime];
                    break;
                default:
                    break;
            }
        }
        return [$page, $limit, $where, $excludes];
    }

    /**
     * @return list<int>
     */
    private function integerFilterValues(mixed $value): array
    {
        $values = is_array($value) ? $value : explode(',', (string) $value);
        $integers = [];

        foreach ($values as $item) {
            $item = is_scalar($item) ? trim((string) $item) : '';
            if ($item !== '' && ctype_digit($item)) {
                $integers[] = (int) $item;
            }
        }

        return array_values(array_unique($integers));
    }

    /**
     * 下拉选择列表
     * @return JsonResponse
     */
    public function selectList(): JsonResponse
    {
        $fields = request()->input('selectFields');
        $data   = $this->model->select(explode(',', $fields))->get()->toArray();
        return $this->success('', $data);
    }

}
