@extends('user.portal.layout')

@section('content')
    <div class="auth-shell">
        <h1 class="page-title">找回密码</h1>
        <section class="auth-card">
            <p class="auth-intro">提交账号后，系统会生成可用于重置密码的记录。</p>
            <form data-portal-form data-endpoint="/user/password/forgot" data-loading-text="发送中...">
            <label>
                账号
                <input type="text" name="account" autocomplete="username" required>
            </label>
            <div class="form-actions">
                <button type="submit">发送重置请求</button>
                <a href="/u/reset-password">已有令牌或验证码</a>
            </div>
            <div class="status" data-form-status></div>
            </form>
        </section>
    </div>
@endsection
