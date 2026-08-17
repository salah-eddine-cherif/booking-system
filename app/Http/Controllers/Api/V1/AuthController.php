<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\IssueTokenRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Staff authentication for the admin API. Sanctum personal access tokens rather
 * than sessions, because the admin client is a separate front end.
 */
class AuthController extends Controller
{
    public function store(IssueTokenRequest $request): JsonResponse
    {
        $user = User::where('email', $request->input('email'))->first();

        // One generic failure for both branches, so the endpoint cannot be used
        // to discover which email addresses exist.
        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        $token = $user->createToken($request->input('device_name', 'admin'));

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'timezone' => $user->timezone,
            ],
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Token revoked.']);
    }
}
