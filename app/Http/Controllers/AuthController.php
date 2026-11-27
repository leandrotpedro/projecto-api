<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;
use OpenApi\Attributes as OA;

#[OA\SecurityScheme(
    securityScheme: 'sanctum',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'Personal Access Token',
    description: 'Cada funcionário deve iniciar sessão com a sua própria conta em POST /api/login e reutilizar o token Bearer durante o turno. Termine a sessão com POST /api/logout. No botão Authorize, introduza apenas o valor do token. A auditoria requer o perfil administrador.'
)]
class AuthController extends Controller
{
    #[OA\Post(
        path: '/api/login',
        tags: ['Authentication'],
        summary: 'Iniciar sessão e obter token',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                    new OA\Property(property: 'password', type: 'string', format: 'password'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Login efetuado; copiar o campo token para Authorize',
                content: new OA\JsonContent(
                    required: ['token', 'token_type', 'user'],
                    properties: [
                        new OA\Property(property: 'token', type: 'string', example: '1|token-pessoal'),
                        new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
                        new OA\Property(
                            property: 'user',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 1),
                                new OA\Property(property: 'name', type: 'string', example: 'Ana Silva'),
                                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'ana@example.com'),
                                new OA\Property(property: 'role', type: 'string', example: 'cliente'),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Credenciais inválidas'),
            new OA\Response(response: 422, description: 'Dados inválidos'),
        ]
    )]
    public function login(Request $request): JsonResponse
    {
        $request->merge([
            'email' => $request->input('email')
                ?? $request->input('identifier')
                ?? $request->input('name'),
        ]);
        $credentials = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $identifier = mb_strtolower(trim($credentials['email']));
        $user = User::whereRaw('LOWER(email) = ?', [$identifier])->first();
        if (!$user) {
            $matchingNames = User::whereRaw('LOWER(name) = ?', [$identifier])->limit(2)->get();
            if ($matchingNames->count() === 1) {
                $user = $matchingNames->first();
            }
        }

        $storedPassword = $user?->password;
        if (
            !$user
            || $storedPassword === null
            || (Hash::isHashed($storedPassword)
                ? !Hash::check($credentials['password'], $storedPassword)
                : !hash_equals($storedPassword, $credentials['password']))
        ) {
            return response()->json(['message' => 'Utilizador ou palavra-passe inválidos.'], 401);
        }

        if (!Hash::isHashed($storedPassword)) {
            $user->password = $credentials['password'];
            $user->save();
        }

        return response()->json([
            'token' => $user->createToken('api')->plainTextToken,
            'user' => $user->only(['id', 'name', 'email', 'role']),
            'token_type' => 'Bearer',
        ]);
    }

    #[OA\Post(
        path: '/api/logout',
        tags: ['Authentication'],
        summary: 'Terminar sessão',
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Sessão terminada')]
    )]
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(['message' => 'Sessão terminada.']);
    }

    #[OA\Get(
        path: '/api/sessions',
        tags: ['Authentication'],
        summary: 'Listar sessões da conta autenticada',
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Lista de sessões')]
    )]
    public function sessions(Request $request): JsonResponse
    {
        $currentToken = $request->user()->currentAccessToken();
        $currentTokenId = $currentToken instanceof PersonalAccessToken ? $currentToken->getKey() : null;

        return response()->json(
            $request->user()->tokens()
                ->get(['id', 'name', 'created_at', 'last_used_at', 'expires_at'])
                ->map(fn (PersonalAccessToken $token) => [
                    'id' => $token->getKey(),
                    'name' => $token->name,
                    'created_at' => $token->created_at,
                    'last_used_at' => $token->last_used_at,
                    'expires_at' => $token->expires_at,
                    'is_current' => $token->getKey() === $currentTokenId,
                ])
        );
    }

    #[OA\Delete(
        path: '/api/sessions/{session}',
        tags: ['Authentication'],
        summary: 'Terminar uma sessão pelo ID',
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'session', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Sessão terminada'),
            new OA\Response(response: 404, description: 'Sessão não encontrada'),
        ]
    )]
    public function destroySession(Request $request, int $session): JsonResponse
    {
        $token = $request->user()->tokens()->whereKey($session)->first();
        if (!$token) {
            return response()->json(['message' => 'Sessão não encontrada.'], 404);
        }

        $token->delete();

        return response()->json(['message' => 'Sessão terminada.']);
    }

    #[OA\Patch(
        path: '/api/me',
        tags: ['Authentication'],
        summary: 'Atualizar dados do utilizador autenticado',
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Utilizador atualizado')]
    )]
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        $previousValues = array_intersect_key($user->getAttributes(), $validated);
        $user->update($validated);
        $changes = [];
        foreach ($validated as $key => $value) {
            if (($previousValues[$key] ?? null) !== $value) {
                $changes[$key] = ['from' => $previousValues[$key] ?? null, 'to' => $value];
            }
        }
        if ($changes !== []) {
            $this->logUserActivity($request, 'user.profile_updated', $user, $changes);
        }

        return response()->json([
            'message' => 'Utilizador atualizado.',
            'user' => $user->only(['id', 'name', 'email', 'role']),
        ]);
    }

    #[OA\Delete(
        path: '/api/me',
        tags: ['Authentication'],
        summary: 'Eliminar logicamente a conta autenticada',
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Conta eliminada logicamente')]
    )]
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        $this->logUserActivity($request, 'user.account_deleted', $user);
        $user->delete();

        return response()->json(['message' => 'Conta eliminada.']);
    }

    /**
     * @param array<string, mixed>|null $changes
     */
    private function logUserActivity(Request $request, string $action, User $user, ?array $changes = null): void
    {
        ActivityLog::create([
            'actor_id' => $user->id,
            'action' => $action,
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'changes' => $changes,
            'ip_address' => $request->ip(),
        ]);
    }
}
