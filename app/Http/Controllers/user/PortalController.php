<?php

namespace App\Http\Controllers\user;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PortalController extends Controller
{
    public function index(): RedirectResponse
    {
        return redirect('/u/dashboard');
    }

    public function login(): View
    {
        return $this->render('login', '登录');
    }

    public function register(): View
    {
        return $this->render('register', '注册');
    }

    public function forgotPassword(): View
    {
        return $this->render('forgot-password', '找回密码');
    }

    public function resetPassword(): View
    {
        return $this->render('reset-password', '重置密码');
    }

    public function dashboard(): View
    {
        return $this->render('dashboard', '控制台');
    }

    private function render(string $view, string $title): View
    {
        return view('user.portal.' . $view, [
            'title' => $title,
            'currentUser' => session('user', []),
        ]);
    }
}
