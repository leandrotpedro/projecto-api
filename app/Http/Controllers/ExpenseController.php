<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\CashSession;
use App\Models\Expense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class ExpenseController extends Controller
{
    #[OA\Get(
        path: '/api/expenses',
        tags: ['Financial'],
        summary: 'Listar despesas registadas (administradores, gerentes e caixas)',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),
        ],
        responses: [new OA\Response(response: 200, description: 'Lista paginada de despesas')]
    )]
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', Rule::when($request->filled('from'), ['after_or_equal:from'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Expense::query()->with('recorder');
        if (isset($validated['from'])) {
            $query->whereDate('expense_date', '>=', $validated['from']);
        }
        if (isset($validated['to'])) {
            $query->whereDate('expense_date', '<=', $validated['to']);
        }

        return response()->json($query->latest('expense_date')->paginate($validated['per_page'] ?? 20));
    }

    #[OA\Post(
        path: '/api/expenses',
        tags: ['Financial'],
        summary: 'Registar uma despesa (administradores, gerentes e caixas)',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['description', 'amount', 'expense_date', 'payment_method'],
                properties: [
                    new OA\Property(property: 'description', type: 'string', example: 'Pagamento da electricidade'),
                    new OA\Property(property: 'category', type: 'string', nullable: true, example: 'Serviços'),
                    new OA\Property(property: 'amount', type: 'number', minimum: 0.01, example: 25000),
                    new OA\Property(property: 'expense_date', type: 'string', format: 'date'),
                    new OA\Property(property: 'payment_method', type: 'string', enum: ['dinheiro', 'cartao', 'transferencia']),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Despesa registada'),
            new OA\Response(response: 422, description: 'Dados inválidos'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999.99'],
            'expense_date' => ['required', 'date_format:Y-m-d'],
            'payment_method' => ['required', 'string', 'in:dinheiro,cartao,transferencia'],
        ]);

        $expense = DB::transaction(function () use ($validated, $request): Expense {
            $cashSession = null;
            if ($request->user()->isCashier() || $request->user()->isManager() || $request->user()->isAdmin()) {
                $cashSession = CashSession::query()
                    ->where('opened_by', $request->user()->id)
                    ->where('status', 'open')
                    ->lockForUpdate()
                    ->first();
            }

            if (
                ! $cashSession
                && ($request->user()->isCashier() || $validated['payment_method'] === 'dinheiro')
            ) {
                throw ValidationException::withMessages([
                    'cash_session' => ['É necessária uma sessão de caixa aberta para registar esta despesa.'],
                ]);
            }

            if (
                $validated['payment_method'] === 'dinheiro'
                && (float) $validated['amount'] > $cashSession->summary()['expected_amount']
            ) {
                throw ValidationException::withMessages([
                    'amount' => ['A despesa excede o dinheiro disponível no caixa.'],
                ]);
            }

            $expense = Expense::create([
                ...$validated,
                'recorded_by' => $request->user()->id,
                'cash_session_id' => $cashSession?->id,
            ]);

            ActivityLog::create([
                'actor_id' => $request->user()->id,
                'action' => 'expense.created',
                'subject_type' => Expense::class,
                'subject_id' => $expense->id,
                'changes' => [
                    'amount' => $expense->amount,
                    'expense_date' => $validated['expense_date'],
                    'payment_method' => $expense->payment_method,
                ],
                'ip_address' => $request->ip(),
            ]);

            return $expense;
        });

        return response()->json([
            'message' => 'Despesa registada com sucesso.',
            'expense' => $expense->load('recorder'),
        ], 201);
    }
}
