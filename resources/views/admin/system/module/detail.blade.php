@include('admin.layout.head')
@php
    $active = $reviewDetails['active'];
    $pending = $reviewDetails['pending'];
    $signatureLabels = ['valid' => '有效', 'invalid' => '无效', 'unsigned' => '未签名'];
    $diffLabels = [
        'permissions' => '宿主权限',
        'api_abilities' => 'API 能力',
        'external_domains' => '外部域名',
        'dependencies' => '依赖模块',
        'conflicts' => '冲突模块',
        'api_quotas' => 'API 配额',
    ];
@endphp
<div class="layuimini-container">
    <div class="layuimini-main">
        <fieldset class="table-search-fieldset">
            <legend>模块信息</legend>
            <table class="layui-table">
                <tbody>
                <tr><th width="160">模块标识</th><td>{{ $module->name }}</td><th width="160">显示名称</th><td>{{ $module->title }}</td></tr>
                <tr><th>当前版本</th><td>{{ $module->version }}</td><th>运行状态</th><td>{{ $module->status }}</td></tr>
                <tr><th>开发厂商</th><td>{{ $module->vendor }}</td><th>信任类型</th><td>{{ $module->trust_level ?: $module->type }}</td></tr>
                <tr><th>命名空间</th><td>{{ $module->namespace }}</td><th>后台前缀</th><td>{{ $module->admin_prefix }}</td></tr>
                <tr><th>模块路径</th><td colspan="3">{{ $module->path }}</td></tr>
                @if(!empty($module->last_error))
                    <tr><th>最近错误</th><td colspan="3" class="color-red">{{ $module->last_error }}</td></tr>
                @endif
                </tbody>
            </table>
        </fieldset>

        <fieldset class="table-search-fieldset">
            <legend>待审制品</legend>
            <table class="layui-table">
                <thead>
                <tr><th width="180">检查项</th><th>当前活动制品</th><th>待审制品</th></tr>
                </thead>
                <tbody>
                <tr><td>版本 / 状态</td><td>{{ $active['version'] ?? '-' }} / {{ $active['status'] ?? '-' }}</td><td>{{ $pending['version'] ?? '-' }} / {{ $pending['status'] ?? '-' }}</td></tr>
                <tr><td>来源 / 信任级别</td><td>{{ $active['source_type'] ?? '-' }} / {{ $active['trust_level'] ?? '-' }}</td><td>{{ $pending['source_type'] ?? '-' }} / {{ $pending['trust_level'] ?? '-' }}</td></tr>
                <tr><td>制品哈希</td><td class="layui-font-monospace">{{ $active['artifact_hash'] ?? '-' }}</td><td class="layui-font-monospace">{{ $pending['artifact_hash'] ?? '-' }}</td></tr>
                <tr><td>签名状态</td><td>{{ $signatureLabels[$active['signature_state'] ?? 'unsigned'] }}</td><td>{{ $signatureLabels[$pending['signature_state'] ?? 'unsigned'] }}</td></tr>
                <tr><td>签名哈希</td><td class="layui-font-monospace">{{ $active['signature_hash'] ?? '-' }}</td><td class="layui-font-monospace">{{ $pending['signature_hash'] ?? '-' }}</td></tr>
                <tr><td>上传管理员</td><td>{{ $active['uploaded_by'] ?? '-' }}</td><td>{{ $pending['uploaded_by'] ?? '-' }}</td></tr>
                <tr><td>审核管理员</td><td>{{ $active['reviewed_by'] ?? '-' }}</td><td>{{ $pending['reviewed_by'] ?? '-' }}</td></tr>
                <tr><td>审核时间</td><td>{{ $active['reviewed_at'] ?? '-' }}</td><td>{{ $pending['reviewed_at'] ?? '-' }}</td></tr>
                <tr><td>审核原因</td><td>{{ $active['review_reason'] ?? '-' }}</td><td>{{ $pending['review_reason'] ?? '-' }}</td></tr>
                </tbody>
            </table>
        </fieldset>

        <fieldset class="table-search-fieldset">
            <legend>权限与依赖差异</legend>
            <table class="layui-table">
                <thead>
                <tr><th width="160">类别</th><th>新增</th><th>移除</th><th>变更</th></tr>
                </thead>
                <tbody>
                @foreach($reviewDetails['manifest_diff'] as $key => $diff)
                    <tr>
                        <td>{{ $diffLabels[$key] ?? $key }}</td>
                        <td><pre class="layui-code">{{ json_encode($diff['added'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></td>
                        <td><pre class="layui-code">{{ json_encode($diff['removed'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></td>
                        <td><pre class="layui-code">{{ json_encode($diff['changed'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </fieldset>

        <fieldset class="table-search-fieldset">
            <legend>Manifest 对照</legend>
            <div class="layui-row layui-col-space15">
                <div class="layui-col-md6">
                    <h3>当前活动 Manifest</h3>
                    <pre class="layui-code">{{ json_encode($reviewDetails['active_manifest'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                </div>
                <div class="layui-col-md6">
                    <h3>待审 Manifest</h3>
                    <pre class="layui-code">{{ json_encode($reviewDetails['pending_manifest'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                </div>
            </div>
        </fieldset>

        <fieldset class="table-search-fieldset">
            <legend>版本历史（最近 100 条 / 共 {{ $reviewDetails['release_history_total'] }} 条）</legend>
            <table class="layui-table">
                <thead>
                <tr><th>ID</th><th>版本</th><th>状态</th><th>来源</th><th>信任级别</th><th>上传人</th><th>审核人</th><th>审核时间</th><th>激活时间</th></tr>
                </thead>
                <tbody>
                @forelse($reviewDetails['release_history'] as $release)
                    <tr>
                        <td>{{ $release['id'] }}</td><td>{{ $release['version'] }}</td><td>{{ $release['status'] }}</td>
                        <td>{{ $release['source_type'] }}</td><td>{{ $release['trust_level'] }}</td>
                        <td>{{ $release['uploaded_by'] ?? '-' }}</td><td>{{ $release['reviewed_by'] ?? '-' }}</td>
                        <td>{{ $release['reviewed_at'] ?? '-' }}</td><td>{{ $release['activated_at'] ?? '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center">暂无制品历史</td></tr>
                @endforelse
                </tbody>
            </table>
        </fieldset>
    </div>
</div>
@include('admin.layout.foot')
