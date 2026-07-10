<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    // صفحة تسجيل الدخول
    public function show(): Response
    {
        return Inertia::render('auth/login');
    }

    // معالجة تسجيل الدخول
    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'بيانات الدخول غير صحيحة.',
            ]);
        }

        // منع الحسابات الموقوفة من الدخول
        if (! $request->user()->isActive()) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => 'الحساب موقوف حالياً، يرجى مراجعة الإدارة.',
            ]);
        }

        $request->session()->regenerate();

        // التوجيه إلى لوحة الدور المناسب
        return redirect()->intended($request->user()->role->home());
    }

    // تسجيل الخروج
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
