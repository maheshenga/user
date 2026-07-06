@include('admin.layout.head')
<div class="layuimini-container">
    <div class="layuimini-main">
        <table class="layui-table">
            <tbody>
            <tr>
                <th width="160">ID</th>
                <td>{{ $user->id }}</td>
            </tr>
            <tr>
                <th>手机号</th>
                <td>{{ $user->mobile }}</td>
            </tr>
            <tr>
                <th>邮箱</th>
                <td>{{ $user->email }}</td>
            </tr>
            <tr>
                <th>昵称</th>
                <td>{{ $user->nickname }}</td>
            </tr>
            <tr>
                <th>状态</th>
                <td>{{ $user->status }}</td>
            </tr>
            <tr>
                <th>注册IP</th>
                <td>{{ $user->register_ip }}</td>
            </tr>
            <tr>
                <th>最后登录IP</th>
                <td>{{ $user->last_login_ip }}</td>
            </tr>
            <tr>
                <th>可用余额</th>
                <td>{{ $user->available_balance }}</td>
            </tr>
            <tr>
                <th>冻结余额</th>
                <td>{{ $user->frozen_balance }}</td>
            </tr>
            <tr>
                <th>VIP等级</th>
                <td>{{ $user->vip_level }}</td>
            </tr>
            <tr>
                <th>VIP到期时间</th>
                <td>{{ $user->vip_expires_at }}</td>
            </tr>
            </tbody>
        </table>
    </div>
</div>
@include('admin.layout.foot')
