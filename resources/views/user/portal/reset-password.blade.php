@extends('user.portal.layout')

@section('content')
    <div class="auth-shell">
        <h1 class="page-title">重置密码</h1>
        <section class="auth-card">
            <p class="auth-intro">输入账号、新密码，并填写重置令牌或验证码。</p>
            <form data-portal-form data-endpoint="/user/password/reset" data-success-redirect="/u/login" data-loading-text="重置中...">
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
                <small class="form-tip">令牌和验证码至少填写一项。</small>
            </label>
            <div class="form-actions">
                <button type="submit">重置密码</button>
                <a href="/u/login">返回登录</a>
            </div>
            <div class="status" data-form-status></div>
            </form>
        </section>
    </div>
@endsection
