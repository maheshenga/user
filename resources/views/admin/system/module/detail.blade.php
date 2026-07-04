@include('admin.layout.head')
<div class="layuimini-container">
    <div class="layuimini-main">
        <table class="layui-table">
            <tbody>
            <tr><th width="160">名称</th><td>{{ $module->name }}</td></tr>
            <tr><th>标题</th><td>{{ $module->title }}</td></tr>
            <tr><th>版本</th><td>{{ $module->version }}</td></tr>
            <tr><th>厂商</th><td>{{ $module->vendor }}</td></tr>
            <tr><th>状态</th><td>{{ $module->status }}</td></tr>
            <tr><th>类型</th><td>{{ $module->type }}</td></tr>
            <tr><th>命名空间</th><td>{{ $module->namespace }}</td></tr>
            <tr><th>后台前缀</th><td>{{ $module->admin_prefix }}</td></tr>
            <tr><th>路径</th><td>{{ $module->path }}</td></tr>
            @if(!empty($module->last_error))
                <tr><th>最近错误</th><td class="color-red">{{ $module->last_error }}</td></tr>
            @endif
            </tbody>
        </table>

        <fieldset class="table-search-fieldset">
            <legend>模块元数据</legend>
            <pre class="layui-code">{{ json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
        </fieldset>
    </div>
</div>
@include('admin.layout.foot')
