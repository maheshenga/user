@extends('user.portal.layout')

@section('content')
    <h1 class="page-title">注册</h1>
    <section class="panel">
        <form data-portal-form data-endpoint="/user/register" data-register-login-endpoint="/user/login" data-success-redirect="/u/dashboard">
            <label>
                手机号
                <input type="text" name="mobile" autocomplete="tel">
            </label>
            <label>
                邮箱
                <input type="email" name="email" autocomplete="email">
            </label>
            <label>
                密码
                <input type="password" name="password" autocomplete="new-password" required>
            </label>
            <label>
                邀请码
                <input type="text" name="invite_code">
            </label>
            <button type="submit">注册</button>
            <div class="status" data-form-status></div>
        </form>
        <p class="muted">已有账号？<a href="/u/login">去登录</a>。</p>
    </section>
@endsection
