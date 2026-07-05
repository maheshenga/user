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
        return $this->render('login', 'Login');
    }

    public function register(): View
    {
        return $this->render('register', 'Register');
    }

    public function forgotPassword(): View
    {
        return $this->render('forgot-password', 'Forgot Password');
    }

    public function resetPassword(): View
    {
        return $this->render('reset-password', 'Reset Password');
    }

    public function dashboard(): View
    {
        return $this->render('dashboard', 'Dashboard');
    }

    private function render(string $view, string $title): View
    {
        return view('user.portal.' . $view, [
            'title' => $title,
            'currentUser' => session('user', []),
        ]);
    }
}
