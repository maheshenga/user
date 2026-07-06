@extends('user.portal.layout')

@section('content')
    <div class="auth-shell">
        <h1 class="page-title">注册</h1>
        <section class="auth-card">
            <p class="auth-intro">创建用户账号后会自动生成邀请码。</p>
            <form data-portal-form data-endpoint="/user/register" data-register-login-endpoint="/user/login" data-success-redirect="/u/dashboard" data-loading-text="注册中...">
            <label>
                手机号
                <input type="text" name="mobile" autocomplete="tel">
                <small class="form-tip">手机号和邮箱至少填写一项。</small>
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
                <small class="form-tip">邀请码可选，用于绑定邀请关系。</small>
            </label>
            <div class="form-actions">
                <button type="submit">注册</button>
                <a href="/u/login">已有账号，去登录</a>
            </div>
            <div class="status" data-form-status></div>
            </form>
        </section>
    </div>
@endsection
