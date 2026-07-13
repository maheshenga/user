@include('admin.layout.head')
<div class="layuimini-container">
    <div class="layuimini-main">
        <table class="layui-table">
            <tr><th>ID</th><td>{{ $row['id'] ?? '' }}</td></tr>
            <tr><th>手机</th><td>{{ $row['mobile'] ?? '' }}</td></tr>
            <tr><th>邮箱</th><td>{{ $row['email'] ?? '' }}</td></tr>
            <tr><th>状态</th><td>{{ $row['status'] ?? '' }}</td></tr>
            <tr><th>来源模块</th><td>{{ $row['source_module'] ?? '' }}</td></tr>
            <tr><th>VIP 等级</th><td>{{ $row['vip_level'] ?? 0 }}</td></tr>
            <tr><th>VIP 到期</th><td>{{ $row['vip_expires_at'] ?? '' }}</td></tr>
        </table>
    </div>
</div>
@include('admin.layout.foot')
