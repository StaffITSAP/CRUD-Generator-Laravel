<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    // POST /api/v1/auth/register
    public function register(Request $request)
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'unique:users,username'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::create([
            'name'              => $data['name'],
            'username'          => $data['username'],
            'email'             => $data['email'],
            'password'          => bcrypt($data['password']),
            'email_verified_at' => now(),
        ]);

        $token = $user->createToken('api_token')->plainTextToken;

        return response()->json([
            'message' => 'Registrasi berhasil',
            'token'   => $token,
            'user'    => $user,
        ], 201);
    }

    // POST /api/v1/auth/login
    public function login(Request $request)
    {
        // Terima: {login,password} ATAU {email,password} ATAU {username,password}
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        $identifier = $request->input('login')
            ?? $request->input('email')
            ?? $request->input('username');

        if (!$identifier) {
            throw ValidationException::withMessages([
                'login' => ['Harus mengirim "login" (email/username) atau "email" atau "username".'],
            ]);
        }

        $user = User::where('email', $identifier)
            ->orWhere('username', $identifier)
            ->first();

        if (!$user || !Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'login' => ['Kredensial tidak valid.'],
            ]);
        }

        $token = $user->createToken('api_token')->plainTextToken;

        return response()->json([
            'message' => 'Login berhasil',
            'token'   => $token,
            'user'    => $user,
        ]);
    }

    // POST /api/v1/auth/logout
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logout berhasil']);
    }

    // GET /api/v1/auth/me
    public function me(Request $request)
    {
        return response()->json($request->user());
    }
}
