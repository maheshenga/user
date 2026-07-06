@extends('user.portal.layout')

@section('content')
    <h1 class="page-title">登录</h1>
    <section class="panel">
        <form data-portal-form data-endpoint="/user/login" data-success-redirect="/u/dashboard">
            <label>
                账号
                <input type="text" name="account" autocomplete="username" required>
            </label>
            <label>
                密码
                <input type="password" name="password" autocomplete="current-password" required>
            </label>
            <button type="submit">登录</button>
            <div class="status" data-form-status></div>
        </form>
        <p class="muted">还没有账号？<a href="/u/register">立即注册</a>。需要帮助？<a href="/u/forgot-password">找回密码</a>。</p>
    </section>
@endsection
