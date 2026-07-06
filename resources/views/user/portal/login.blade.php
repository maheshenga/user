@extends('user.portal.layout')

@section('content')
    <div class="auth-shell">
        <h1 class="page-title">登录</h1>
        <section class="auth-card">
            <p class="auth-intro">登录后查看 VIP、余额、邀请和提现进度。</p>
            <form data-portal-form data-endpoint="/user/login" data-success-redirect="/u/dashboard" data-loading-text="登录中...">
            <label>
                账号
                <input type="text" name="account" autocomplete="username" required>
            </label>
            <label>
                密码
                <input type="password" name="password" autocomplete="current-password" required>
            </label>
            <div class="form-actions">
                <button type="submit">登录</button>
                <a href="/u/forgot-password">找回密码</a>
            </div>
            <div class="status" data-form-status></div>
            </form>
            <p class="muted">还没有账号？<a href="/u/register">立即注册</a>。</p>
        </section>
    </div>
@endsection
