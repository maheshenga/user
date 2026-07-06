@extends('user.portal.layout')

@section('content')
    <h1 class="page-title">找回密码</h1>
    <section class="panel">
        <form data-portal-form data-endpoint="/user/password/forgot">
            <label>
                账号
                <input type="text" name="account" autocomplete="username" required>
            </label>
            <button type="submit">发送重置请求</button>
            <div class="status" data-form-status></div>
        </form>
        <p class="muted">已有令牌或验证码？<a href="/u/reset-password">设置新密码</a>。</p>
    </section>
@endsection
