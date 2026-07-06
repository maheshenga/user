@extends('user.portal.layout')

@section('content')
    <h1 class="page-title">重置密码</h1>
    <section class="panel">
        <form data-portal-form data-endpoint="/user/password/reset" data-success-redirect="/u/login">
            <label>
                账号
                <input type="text" name="account" autocomplete="username" required>
            </label>
            <label>
                新密码
                <input type="password" name="password" autocomplete="new-password" required>
            </label>
            <label>
                重置令牌
                <input type="text" name="token">
            </label>
            <label>
                验证码
                <input type="text" name="code">
            </label>
            <button type="submit">重置密码</button>
            <div class="status" data-form-status></div>
        </form>
    </section>
@endsection
