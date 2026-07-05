@extends('user.portal.layout')

@section('content')
    <h1 class="page-title">Register</h1>
    <section class="panel">
        <form data-portal-form data-endpoint="/user/register" data-register-login-endpoint="/user/login" data-success-redirect="/u/dashboard">
            <label>
                Mobile
                <input type="text" name="mobile" autocomplete="tel">
            </label>
            <label>
                Email
                <input type="email" name="email" autocomplete="email">
            </label>
            <label>
                Password
                <input type="password" name="password" autocomplete="new-password" required>
            </label>
            <label>
                Invite Code
                <input type="text" name="invite_code">
            </label>
            <button type="submit">Register</button>
            <div class="status" data-form-status></div>
        </form>
        <p class="muted">Already registered? <a href="/u/login">Login</a>.</p>
    </section>
@endsection
