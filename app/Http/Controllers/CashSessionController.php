<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\CashSessionMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class CashSessionController extends Controller
{
    #[OA\Get(
        path: '/api/cash-registers',
        tags: ['Cash Control'],
        summary: 'Listar postos de caixa activos',
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Postos de caixa disponíveis')]
    )]
    public function registers(): JsonResponse
    {
        return response()->json(CashRegister::query()->where('is_active', true)->orderBy('name')->get());
    }

    #[OA\Post(
        path: '/api/cash-registers',
        tags: ['Cash Control'],
        summary: 'Criar um posto de caixa (administradores e gerentes)',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [new OA\Property(property: 'name', type: 'string', example: 'Caixa 2')]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Posto criado'),
            new OA\Response(response: 422, description: 'Nome já utilizado ou inválido'),
        ]
    )]
    public function createRegister(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:cash_registers,name'],
        ]);

        $register = CashRegister::create([
            ...$validated,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['cash_register' => $register], 201);
    }

    #[OA\Patch(
        path: '/api/cash-registers/{cashRegister}',
        tags: ['Cash Control'],
        summary: 'Actualizar ou desactivar um posto de caixa',
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'cashRegister', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Caixa 2'),
                    new OA\Property(property: 'is_active', type: 'boolean', example: false),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Posto actualizado'),
            new OA\Response(response: 409, description: 'Não é possível desactivar um posto com sessão aberta'),
        ]
    )]
    public function updateRegister(Request $request, CashRegister $cashRegister): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', 'unique:cash_registers,name,'.$cashRegister->id],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ]);

        $register = DB::transaction(function () use ($cashRegister, $validated, $request): CashRegister {
            $register = CashRegister::query()->lockForUpdate()->findOrFail($cashRegister->id);
            if (($validated['is_active'] ?? true) === false) {
                $hasOpenSession = $register->sessions()->where('status', 'open')->exists();
                if ($hasOpenSession) {
                    abort(409, 'Feche a sessão de caixa antes de desactivar este posto.');
                }
            }

            $changes = [];
            foreach ($validated as $key => $value) {
                if ($register->{$key} !== $value) {
                    $changes[$key] = ['from' => $register->{$key}, 'to' => $value];
                }
            }
            $register->update($validated);

            if ($changes !== []) {
                $this->logActivity($request, 'cash_register.updated', $register, $changes);
            }

            return $register;
        });

        return response()->json(['cash_register' => $register]);
    }

    #[OA\Get(
        path: '/api/cash-sessions/current',
        tags: ['Cash Control'],
        summary: 'Consultar a sessão de caixa aberta pelo utilizador autenticado',
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Sessão actual ou null')]
    )]
    public function current(Request $request): JsonResponse
    {
        $session = CashSession::query()
            ->where('opened_by', $request->user()->id)
            ->where('status', 'open')
            ->with(['register', 'opener'])
            ->first();

        return response()->json([
            'cash_session' => $session,
            'summary' => $session?->summary(),
        ]);
    }

    #[OA\Get(
        path: '/api/cash-sessions',
        tags: ['Cash Control'],
        summary: 'Listar sessões de caixa',
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Sessões paginadas')]
    )]
    public function index(Request $request): JsonResponse
    {
        $query = CashSession::query()->with(['register', 'opener', 'closer'])->latest('opened_at');
        if (! $request->user()->canViewAllSales()) {
            $query->where('opened_by', $request->user()->id);
        }

        return response()->json($query->paginate(20));
    }

    #[OA\Post(
        path: '/api/cash-sessions',
        tags: ['Cash Control'],
        summary: 'Abrir uma sessão num posto de caixa',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['cash_register_id', 'opening_amount'],
                properties: [
                    new OA\Property(property: 'cash_register_id', type: 'integer', example: 1),
                    new OA\Property(property: 'opening_amount', type: 'number', minimum: 0, example: 5000),
                    new OA\Property(property: 'notes', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Sessão aberta; o nome do vendedor é obtido da conta autenticada'),
            new OA\Response(response: 409, description: 'Posto ou operador já tem sessão aberta'),
        ]
    )]
    public function open(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cash_register_id' => ['required', 'integer', 'exists:cash_registers,id'],
            'opening_amount' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $session = DB::transaction(function () use ($validated, $request): CashSession {
            $register = CashRegister::query()
                ->whereKey($validated['cash_register_id'])
                ->lockForUpdate()
                ->firstOrFail();
            User::query()->lockForUpdate()->findOrFail($request->user()->id);
            if (! $register->is_active) {
                abort(409, 'Este posto de caixa está desactivado.');
            }

            $registerHasOpenSession = CashSession::query()
                ->where('cash_register_id', $register->id)
                ->where('status', 'open')
                ->exists();
            $operatorHasOpenSession = CashSession::query()
                ->where('opened_by', $request->user()->id)
                ->where('status', 'open')
                ->exists();

            if ($registerHasOpenSession || $operatorHasOpenSession) {
                abort(409, 'O posto ou operador já tem uma sessão de caixa aberta.');
            }

            $session = CashSession::create([
                'cash_register_id' => $register->id,
                'opened_by' => $request->user()->id,
                'opening_amount' => $validated['opening_amount'],
                'status' => 'open',
                'opened_at' => now(),
                'notes' => $validated['notes'] ?? null,
            ]);

            $this->logActivity($request, 'cash_session.opened', $session, [
                'cash_register_id' => $register->id,
                'opening_amount' => $session->opening_amount,
            ]);

            return $session;
        });

        return response()->json([
            'message' => 'Sessão de caixa aberta.',
            'cash_session' => $session->load(['register', 'opener']),
            'summary' => $session->summary(),
        ], 201);
    }

    #[OA\Get(
        path: '/api/cash-sessions/{cashSession}',
        tags: ['Cash Control'],
        summary: 'Consultar uma sessão de caixa e a sua conferência',
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'cashSession', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Detalhe e resumo da sessão'),
            new OA\Response(response: 403, description: 'Sem acesso a esta sessão'),
            new OA\Response(response: 404, description: 'Sessão não encontrada'),
        ]
    )]
    public function show(Request $request, CashSession $cashSession): JsonResponse
    {
        $this->authorizeSession($request, $cashSession);

        return response()->json([
            'cash_session' => $cashSession->load(['register', 'opener', 'closer', 'movements.recorder']),
            'summary' => $cashSession->summary(),
        ]);
    }

    #[OA\Post(
        path: '/api/cash-sessions/{cashSession}/close',
        tags: ['Cash Control'],
        summary: 'Fechar sessão e registar o dinheiro contado',
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'cashSession', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['counted_amount'],
                properties: [
                    new OA\Property(property: 'counted_amount', type: 'number', minimum: 0, example: 14500),
                    new OA\Property(property: 'notes', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Sessão fechada com diferença calculada'),
            new OA\Response(response: 409, description: 'Sessão já fechada'),
        ]
    )]
    public function close(Request $request, CashSession $cashSession): JsonResponse
    {
        $this->authorizeSession($request, $cashSession);
        $validated = $request->validate([
            'counted_amount' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $cashSession = DB::transaction(function () use ($cashSession, $validated, $request): CashSession {
            $session = CashSession::query()->lockForUpdate()->findOrFail($cashSession->id);
            if ($session->status !== 'open') {
                abort(409, 'A sessão de caixa já está fechada.');
            }

            $summary = $session->summary();
            $session->update([
                'status' => 'closed',
                'closed_by' => $request->user()->id,
                'closed_at' => now(),
                'expected_amount' => $summary['expected_amount'],
                'counted_amount' => $validated['counted_amount'],
                'discrepancy' => round((float) $validated['counted_amount'] - $summary['expected_amount'], 2),
                'notes' => $validated['notes'] ?? $session->notes,
            ]);

            $this->logActivity($request, 'cash_session.closed', $session, [
                'expected_amount' => $session->expected_amount,
                'counted_amount' => $session->counted_amount,
                'discrepancy' => $session->discrepancy,
            ]);

            return $session;
        });

        return response()->json([
            'message' => 'Sessão de caixa fechada.',
            'cash_session' => $cashSession->load(['register', 'opener', 'closer']),
            'summary' => $cashSession->summary(),
        ]);
    }

    #[OA\Post(
        path: '/api/cash-sessions/{cashSession}/movements',
        tags: ['Cash Control'],
        summary: 'Registar entrada ou saída manual de dinheiro',
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'cashSession', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['direction', 'amount', 'description'],
                properties: [
                    new OA\Property(property: 'direction', type: 'string', enum: ['in', 'out']),
                    new OA\Property(property: 'amount', type: 'number', minimum: 0.01, example: 1000),
                    new OA\Property(property: 'description', type: 'string', example: 'Reforço de troco'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Movimento registado'),
            new OA\Response(response: 409, description: 'Sessão fechada'),
        ]
    )]
    public function storeMovement(Request $request, CashSession $cashSession): JsonResponse
    {
        $this->authorizeSession($request, $cashSession);
        $validated = $request->validate([
            'direction' => ['required', 'string', 'in:in,out'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999.99'],
            'description' => ['required', 'string', 'max:255'],
        ]);

        $movement = DB::transaction(function () use ($cashSession, $validated, $request): CashSessionMovement {
            $session = CashSession::query()->lockForUpdate()->findOrFail($cashSession->id);
            if ($session->status !== 'open') {
                abort(409, 'Não é possível movimentar uma sessão de caixa fechada.');
            }

            if ($validated['direction'] === 'out' && (float) $validated['amount'] > $session->summary()['expected_amount']) {
                throw ValidationException::withMessages([
                    'amount' => ['O valor de saída excede o dinheiro disponível no caixa.'],
                ]);
            }

            $movement = $session->movements()->create([
                ...$validated,
                'recorded_by' => $request->user()->id,
            ]);

            $this->logActivity($request, 'cash_session.movement_recorded', $movement, [
                'cash_session_id' => $session->id,
                'direction' => $movement->direction,
                'amount' => $movement->amount,
            ]);

            return $movement;
        });

        return response()->json([
            'message' => 'Movimento registado.',
            'movement' => $movement->load('recorder'),
            'summary' => $cashSession->fresh()->summary(),
        ], 201);
    }

    private function authorizeSession(Request $request, CashSession $cashSession): void
    {
        abort_unless(
            $request->user()->canViewAllSales() || $cashSession->opened_by === $request->user()->id,
            403
        );
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function logActivity(Request $request, string $action, Model $subject, array $changes): void
    {
        ActivityLog::create([
            'actor_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'changes' => $changes,
            'ip_address' => $request->ip(),
        ]);
    }
}
