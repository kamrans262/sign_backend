<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\M24TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class M24TwoFactorController extends Controller
{
    public function __construct(private readonly M24TwoFactorService $service) {}

    public function setup(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        return $this->result($this->service->setup($request->user(), $data['password'], $request));
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'regex:/^[0-9]{6}$/D']]);
        return $this->result($this->service->confirm($request->user(), $data['code'], $request));
    }

    public function disable(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
            'code' => ['required', 'string', 'max:24'],
        ]);
        return $this->result($this->service->disable(
            $request->user(), $data['password'], $data['code'], $request));
    }

    public function regenerate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
            'code' => ['required', 'string', 'max:24'],
        ]);
        return $this->result($this->service->regenerate(
            $request->user(), $data['password'], $data['code'], $request));
    }

    public function verifyLogin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ticket' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'code' => ['required', 'string', 'max:24'],
        ]);
        $token = $this->service->verifyLogin($data['ticket'], $data['code'], $request);
        if ($token === '') {
            throw ValidationException::withMessages(['code' => 'Invalid authenticator or recovery code.']);
        }
        return $this->result([
            'purpose' => 'login', 'access_token' => $token, 'token_type' => 'Bearer',
        ]);
    }

    private function result(array $data): JsonResponse
    {
        return response()->json(['data' => $data], 200, ['Cache-Control' => 'private, no-store']);
    }
}
