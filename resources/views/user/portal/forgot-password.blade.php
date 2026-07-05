@extends('user.portal.layout')

@section('content')
    <h1 class="page-title">Forgot Password</h1>
    <section class="panel">
        <form data-portal-form data-endpoint="/user/password/forgot">
            <label>
                Account
                <input type="text" name="account" autocomplete="username" required>
            </label>
            <button type="submit">Request Reset</button>
            <div class="status" data-form-status></div>
        </form>
        <p class="muted">Have a token or code? <a href="/u/reset-password">Set a new password</a>.</p>
    </section>
@endsection
