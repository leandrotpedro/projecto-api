<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\CashSession;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class UserController extends Controller
{
    #[OA\Post(
        path: '/api/forgot-password',
        operationId: 'requestPasswordReset',
        tags: ['Authentication'],
        summary: 'Solicitar ligação para recuperar a senha',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email'],
                properties: [
                    new OA\Property(
                        property: 'email',
                        type: 'string',
                        format: 'email',
                        example: 'ana@example.com'
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Se a conta existir, será enviada uma ligação de recuperação'),
            new OA\Response(response: 422, description: 'Endereço de e-mail inválido'),
            new OA\Response(response: 429, description: 'Pedido temporariamente limitado'),
        ]
    )]
    public function sendPasswordResetLink(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $status = Password::sendResetLink($validated);

        if ($status === Password::RESET_THROTTLED) {
            return response()->json([
                'message' => 'Aguarde antes de solicitar outra ligação de recuperação.',
            ], 429);
        }

        return response()->json([
            'message' => 'Se existir uma conta para este e-mail, será enviada uma ligação de recuperação.',
        ]);
    }

    #[OA\Post(
        path: '/api/reset-password',
        operationId: 'resetPassword',
        tags: ['Authentication'],
        summary: 'Definir uma nova senha com o token de recuperação',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'token', 'password', 'password_confirmation'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'ana@example.com'),
                    new OA\Property(property: 'token', type: 'string'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8),
                    new OA\Property(property: 'password_confirmation', type: 'string', format: 'password', minLength: 8),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Senha redefinida com sucesso'),
            new OA\Response(response: 422, description: 'Token inválido, expirado ou dados inválidos'),
        ]
    )]
    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', \Illuminate\Validation\Rules\Password::default()],
        ]);

        $status = Password::reset($validated, function (User $user, string $password) use ($request): void {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();

            $user->tokens()->delete();
            ActivityLog::create([
                'actor_id' => $user->id,
                'action' => 'user.password_reset',
                'subject_type' => User::class,
                'subject_id' => $user->id,
                'ip_address' => $request->ip(),
            ]);
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => trans($status),
            ], 422);
        }

        return response()->json([
            'message' => 'Senha redefinida com sucesso.',
        ]);
    }

    #[OA\Get(
        path: '/api/users',
        operationId: 'listUsers',
        tags: ['Users'],
        summary: 'Listar utilizadores (administradores e gerentes)',
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lista de utilizadores',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'id', type: 'integer', example: 1),
                            new OA\Property(property: 'name', type: 'string', example: 'Ana Silva'),
                            new OA\Property(property: 'email', type: 'string', format: 'email', example: 'ana@example.com'),
                            new OA\Property(
                                property: 'role',
                                type: 'string',
                                enum: ['administrador', 'gerente', 'vendedor', 'caixa', 'cliente'],
                                description: 'Perfil de acesso: administrador, gerente, vendedor, caixa ou cliente'
                            ),
                            new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                        ]
                    )
                )
            ),
        ]
    )]
    public function index(): JsonResponse
    {
        return response()->json(
            User::query()
                ->select('id', 'name', 'email', 'role', 'created_at')
                ->get()
        );
    }

    #[OA\Get(
        path: '/api/clients',
        tags: ['Users'],
        summary: 'Listar clientes (administradores e gerentes)',
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lista de utilizadores com perfil cliente',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'id', type: 'integer', example: 10, description: 'ID do perfil de cliente'),
                            new OA\Property(property: 'user_id', type: 'integer', nullable: true, example: 5, description: 'ID da conta de acesso online, se existir'),
                            new OA\Property(property: 'name', type: 'string', example: 'Ana Silva'),
                            new OA\Property(property: 'phone', type: 'string', example: '912345678'),
                            new OA\Property(property: 'customer_type', type: 'string', enum: ['individual', 'company']),
                            new OA\Property(property: 'customer_nif', type: 'string', nullable: true, example: '5000000000'),
                            new OA\Property(
                                property: 'user',
                                type: 'object',
                                nullable: true,
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 5),
                                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'ana@example.com'),
                                    new OA\Property(property: 'role', type: 'string', enum: ['cliente']),
                                ]
                            ),
                            new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                        ]
                    )
                )
            ),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 403, description: 'Perfil sem permissão para consultar clientes'),
        ]
    )]
    public function clients(Request $request): JsonResponse
    {
        $query = Client::query()
            ->select('id', 'user_id', 'name', 'phone', 'customer_type', 'customer_nif', 'created_at')
            ->orderBy('name');

        if ($request->user()->canViewAllSales()) {
            $query->with(['user:id,email,role']);
        }

        return response()->json($query->get());
    }

    #[OA\Get(
        path: '/api/sellers',
        tags: ['Users'],
        summary: 'Listar vendedores para selecção no caixa e gestão',
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lista de utilizadores com perfil vendedor',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'id', type: 'integer', example: 4),
                            new OA\Property(property: 'name', type: 'string', example: 'João Manuel'),
                            new OA\Property(property: 'email', type: 'string', format: 'email', example: 'joao@example.com'),
                            new OA\Property(property: 'phone', type: 'string', nullable: true),
                            new OA\Property(property: 'role', type: 'string', enum: ['vendedor']),
                            new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                        ]
                    )
                )
            ),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 403, description: 'Apenas administradores, gerentes e caixas'),
        ]
    )]
    public function sellers(): JsonResponse
    {
        return $this->usersByRole('vendedor');
    }

    private function usersByRole(string $role): JsonResponse
    {
        $users = User::query()
            ->where('role', $role)
            ->select('id', 'name', 'email', 'phone', 'role', 'created_at')
            ->orderBy('name')
            ->get();

        return response()->json($users);
    }

    #[OA\Post(
        path: '/api/staff',
        tags: ['Users'],
        summary: 'Criar uma conta de gerente ou vendedor (apenas administradores)',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'email', 'password', 'role'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'João Manuel'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'joao@example.com'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8),
                    new OA\Property(property: 'phone', type: 'string', nullable: true, example: '912345678'),
                    new OA\Property(property: 'role', type: 'string', enum: ['gerente', 'vendedor']),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Conta de funcionário criada'),
            new OA\Response(response: 403, description: 'Apenas administradores'),
            new OA\Response(response: 422, description: 'Dados inválidos'),
        ]
    )]
    public function createStaff(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'role' => ['required', 'string', 'in:gerente,vendedor'],
        ]);

        $user = DB::transaction(function () use ($validated, $request): User {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'phone' => $validated['phone'] ?? null,
            ]);
            $user->forceFill(['role' => $validated['role']])->save();

            ActivityLog::create([
                'actor_id' => $request->user()->id,
                'action' => 'user.staff_created',
                'subject_type' => User::class,
                'subject_id' => $user->id,
                'changes' => ['role' => $user->role],
                'ip_address' => $request->ip(),
            ]);

            return $user->refresh();
        });

        return response()->json([
            'message' => 'Conta de funcionário criada com sucesso.',
            'user' => $user->only(['id', 'name', 'email', 'phone', 'role', 'created_at']),
        ], 201);
    }

    #[OA\Delete(
        path: '/api/staff/{user}',
        tags: ['Users'],
        summary: 'Remover uma conta de gerente ou vendedor (apenas administradores)',
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Conta removida e sessões de acesso revogadas'),
            new OA\Response(response: 403, description: 'Apenas administradores'),
            new OA\Response(response: 409, description: 'O funcionário tem uma sessão de caixa aberta'),
            new OA\Response(response: 422, description: 'A conta seleccionada não é de gerente ou vendedor'),
        ]
    )]
    public function deleteStaff(Request $request, int $user): JsonResponse
    {
        DB::transaction(function () use ($request, $user): void {
            $record = User::query()->lockForUpdate()->findOrFail($user);
            abort_unless(in_array($record->role, ['gerente', 'vendedor'], true), 422, 'Só é possível remover contas de gerente ou vendedor.');

            if (CashSession::query()->where('opened_by', $record->id)->where('status', 'open')->exists()) {
                abort(409, 'Feche a sessão de caixa do funcionário antes de o remover.');
            }

            $record->tokens()->delete();
            $record->delete();

            ActivityLog::create([
                'actor_id' => $request->user()->id,
                'action' => 'user.staff_deleted',
                'subject_type' => User::class,
                'subject_id' => $record->id,
                'changes' => ['role' => $record->role],
                'ip_address' => $request->ip(),
            ]);
        });

        return response()->json(['message' => 'Conta de funcionário removida.']);
    }

    #[OA\Patch(
        path: '/api/users/{user}/role',
        tags: ['Users'],
        summary: 'Atribuir um perfil de acesso a um utilizador',
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['role'],
                properties: [
                    new OA\Property(
                        property: 'role',
                        type: 'string',
                        enum: ['administrador', 'gerente', 'vendedor', 'caixa', 'cliente'],
                        description: 'administrador: acesso total; gerente: gestão da loja e equipa; vendedor: vendas; caixa: vendas e caixa; cliente: compras próprias. Gerentes só podem atribuir vendedor, caixa ou cliente.'
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Perfil actualizado'),
            new OA\Response(response: 403, description: 'Sem permissão para alterar este perfil'),
            new OA\Response(response: 422, description: 'Perfil inválido ou tentativa de remover o último administrador'),
        ]
    )]
    public function updateRole(Request $request, int $user): JsonResponse
    {
        $allowedRoles = $request->user()->isAdmin()
            ? 'administrador,gerente,vendedor,caixa,cliente'
            : 'vendedor,caixa,cliente';
        $validated = $request->validate([
            'role' => ['required', 'string', 'in:'.$allowedRoles],
        ]);

        $updated = DB::transaction(function () use ($request, $user, $validated): User {
            $record = User::query()->lockForUpdate()->findOrFail($user);
            if ($request->user()->isManager() && ($record->isAdmin() || $record->isManager())) {
                abort(403, 'Gerentes só podem gerir clientes, vendedores e caixas.');
            }

            $previousRole = $record->role;
            $newRole = $validated['role'];

            if ($previousRole === 'administrador' && $newRole !== 'administrador') {
                if ($record->is($request->user())) {
                    abort(422, 'Não pode remover o seu próprio perfil de administrador.');
                }

                if (User::query()->where('role', 'administrador')->count() <= 1) {
                    abort(422, 'Não pode remover o último administrador.');
                }
            }

            if ($previousRole !== $newRole) {
                $record->forceFill(['role' => $newRole])->save();
                ActivityLog::create([
                    'actor_id' => $request->user()->id,
                    'action' => 'user.role_changed',
                    'subject_type' => User::class,
                    'subject_id' => $record->id,
                    'changes' => ['role' => ['from' => $previousRole, 'to' => $newRole]],
                    'ip_address' => $request->ip(),
                ]);
            }

            return $record->refresh();
        });

        return response()->json([
            'message' => 'Perfil do utilizador atualizado com sucesso.',
            'user' => $updated->only(['id', 'name', 'email', 'role']),
        ]);
    }

    #[OA\Get(
        path: '/api/audit-logs',
        operationId: 'listAuditLogs',
        tags: ['Audit'],
        summary: 'Consultar registos de auditoria (apenas administradores)',
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Registos de auditoria paginados'),
            new OA\Response(response: 401, description: 'Não autenticado: autorize o Swagger com um token Bearer'),
            new OA\Response(response: 403, description: 'Apenas administradores podem consultar a auditoria'),
        ]
    )]
    public function auditLogs(): JsonResponse
    {
        return response()->json(
            ActivityLog::with('actor:id,name,email')
                ->latest()
                ->paginate(50)
        );
    }

    #[OA\Post(
        path: '/api/users',
        operationId: 'createUser',
        tags: ['Users'],
        summary: 'Registar utilizador',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'email', 'password'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Ana Silva'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'ana@example.com'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8, example: 'senha123'),
                    new OA\Property(property: 'phone', type: 'string', example: '912345678', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Utilizador criado; copiar o campo token para Authorize',
                content: new OA\JsonContent(
                    required: ['message', 'user', 'token', 'token_type'],
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Utilizador registado com sucesso.'),
                        new OA\Property(
                            property: 'user',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 1),
                                new OA\Property(property: 'name', type: 'string', example: 'Ana Silva'),
                                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'ana@example.com'),
                                new OA\Property(property: 'phone', type: 'string', nullable: true, example: '912345678'),
                                new OA\Property(property: 'role', type: 'string', example: 'cliente'),
                                new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                            ]
                        ),
                        new OA\Property(property: 'token', type: 'string', example: '1|token-pessoal'),
                        new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
                    ]
                )
            ),
            new OA\Response(response: 422, description: 'Erro de validação dos dados enviados'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8',
            'phone' => 'nullable|string|max:30',
            'role' => 'prohibited',
        ]);

        return DB::transaction(function () use ($validated, $request): JsonResponse {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'phone' => $validated['phone'] ?? null,
            ]);
            $user->refresh();

            $token = $user->createToken('api-token')->plainTextToken;
            ActivityLog::create([
                'actor_id' => $user->id,
                'action' => 'user.registered',
                'subject_type' => User::class,
                'subject_id' => $user->id,
                'changes' => ['role' => $user->role],
                'ip_address' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Utilizador registado com sucesso.',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role' => $user->role,
                    'created_at' => $user->created_at,
                ],
                'token' => $token,
                'token_type' => 'Bearer',
            ], 201);
        });
    }

    #[OA\Patch(
        path: '/api/user',
        operationId: 'updateAuthenticatedUser',
        tags: ['Users'],
        summary: 'Atualizar dados do utilizador autenticado',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Ana Silva'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'ana@example.com'),
                    new OA\Property(property: 'phone', type: 'string', nullable: true, example: '912345678'),
                    new OA\Property(property: 'address', type: 'string', nullable: true, example: 'Luanda'),
                    new OA\Property(property: 'bi', type: 'string', nullable: true, example: '000000000LA000'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Dados do utilizador atualizados com sucesso'),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 422, description: 'Erro de validação dos dados enviados'),
        ]
    )]
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|max:255|unique:users,email,'.$user->id,
            'phone' => 'sometimes|nullable|string|max:30',
            'address' => 'sometimes|nullable|string|max:255',
            'bi' => 'sometimes|nullable|string|max:50',
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
            ActivityLog::create([
                'actor_id' => $user->id,
                'action' => 'user.profile_updated',
                'subject_type' => User::class,
                'subject_id' => $user->id,
                'changes' => $changes,
                'ip_address' => $request->ip(),
            ]);
        }

        return response()->json([
            'message' => 'Dados do utilizador atualizados com sucesso.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'address' => $user->address,
                'bi' => $user->bi,
            ],
        ]);
    }
}
