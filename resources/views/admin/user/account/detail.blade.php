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
                <th>Mobile</th>
                <td>{{ $user->mobile }}</td>
            </tr>
            <tr>
                <th>Email</th>
                <td>{{ $user->email }}</td>
            </tr>
            <tr>
                <th>Nickname</th>
                <td>{{ $user->nickname }}</td>
            </tr>
            <tr>
                <th>Status</th>
                <td>{{ $user->status }}</td>
            </tr>
            <tr>
                <th>Register IP</th>
                <td>{{ $user->register_ip }}</td>
            </tr>
            <tr>
                <th>Last Login IP</th>
                <td>{{ $user->last_login_ip }}</td>
            </tr>
            <tr>
                <th>Available Balance</th>
                <td>{{ $user->available_balance }}</td>
            </tr>
            <tr>
                <th>Frozen Balance</th>
                <td>{{ $user->frozen_balance }}</td>
            </tr>
            <tr>
                <th>VIP Level</th>
                <td>{{ $user->vip_level }}</td>
            </tr>
            <tr>
                <th>VIP Expires At</th>
                <td>{{ $user->vip_expires_at }}</td>
            </tr>
            </tbody>
        </table>
    </div>
</div>
@include('admin.layout.foot')
