@extends('user.portal.layout')

@section('content')
    <h1 class="page-title">Reset Password</h1>
    <section class="panel">
        <form data-portal-form data-endpoint="/user/password/reset" data-success-redirect="/u/login">
            <label>
                Account
                <input type="text" name="account" autocomplete="username" required>
            </label>
            <label>
                New Password
                <input type="password" name="password" autocomplete="new-password" required>
            </label>
            <label>
                Token
                <input type="text" name="token">
            </label>
            <label>
                Code
                <input type="text" name="code">
            </label>
            <button type="submit">Reset Password</button>
            <div class="status" data-form-status></div>
        </form>
    </section>
@endsection
