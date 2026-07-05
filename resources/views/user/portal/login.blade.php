@extends('user.portal.layout')

@section('content')
    <h1 class="page-title">Login</h1>
    <section class="panel">
        <form data-portal-form data-endpoint="/user/login" data-success-redirect="/u/dashboard">
            <label>
                Account
                <input type="text" name="account" autocomplete="username" required>
            </label>
            <label>
                Password
                <input type="password" name="password" autocomplete="current-password" required>
            </label>
            <button type="submit">Login</button>
            <div class="status" data-form-status></div>
        </form>
        <p class="muted">No account? <a href="/u/register">Register</a>. Need help? <a href="/u/forgot-password">Reset password</a>.</p>
    </section>
@endsection
