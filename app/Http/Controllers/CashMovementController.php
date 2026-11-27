<?php

namespace App\Http\Controllers;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class CashMovementController extends Controller
{
    #[OA\Get(
        path: '/api/cash-movements',
        tags: ['Financial'],
        summary: 'Consultar entradas e saídas de dinheiro (administradores, gerentes e caixas)',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'direction', in: 'query', schema: new OA\Schema(type: 'string', enum: ['in', 'out'])),
            new OA\Parameter(name: 'type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['sale', 'supplier_purchase', 'refund', 'expense', 'cash_adjustment'])),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),
        ],
        responses: [new OA\Response(response: 200, description: 'Totais de entradas e saídas e histórico paginado de movimentos em AOA')]
    )]
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', Rule::when($request->filled('from'), ['after_or_equal:from'])],
            'direction' => ['sometimes', 'string', 'in:in,out'],
            'type' => ['sometimes', 'string', 'in:sale,supplier_purchase,refund,expense,cash_adjustment'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $sales = DB::table('sales')
            ->whereIn('payment_status', ['paid', 'refund_pending', 'refunded'])
            ->whereNotNull('paid_at')
            ->selectRaw("'sale' as type, 'in' as direction, id as reference_id, total as amount, paid_at as occurred_at, payment_method, COALESCE(customer_name, 'Venda') as description");

        $purchases = DB::table('purchases')
            ->where('payment_status', 'paid')
            ->whereNotNull('paid_at')
            ->selectRaw("'supplier_purchase' as type, 'out' as direction, id as reference_id, total as amount, paid_at as occurred_at, payment_method, supplier_name as description");

        $refunds = DB::table('sales')
            ->where('status', 'cancelled')
            ->where('payment_status', 'refunded')
            ->whereNotNull('cancelled_at')
            ->selectRaw("'refund' as type, 'out' as direction, id as reference_id, total as amount, cancelled_at as occurred_at, payment_method, COALESCE(cancellation_reason, 'Reembolso') as description");

        $saleReturns = DB::table('sale_returns')
            ->selectRaw("'refund' as type, 'out' as direction, id as reference_id, total as amount, refunded_at as occurred_at, refund_method as payment_method, reason as description");

        $expenses = DB::table('expenses')
            ->selectRaw("'expense' as type, 'out' as direction, id as reference_id, amount, expense_date as occurred_at, payment_method, description");

        $cashAdjustments = DB::table('cash_session_movements')
            ->selectRaw("'cash_adjustment' as type, direction, id as reference_id, amount, created_at as occurred_at, 'dinheiro' as payment_method, description");

        $union = $sales->unionAll($purchases)->unionAll($refunds)->unionAll($saleReturns)->unionAll($expenses)->unionAll($cashAdjustments);
        $movements = DB::query()
            ->fromSub($union, 'cash_movements')
            ->select([
                'type',
                'direction',
                'reference_id',
                'amount',
                'occurred_at',
                'payment_method',
                'description',
            ]);

        $summaryQuery = DB::query()->fromSub($union, 'cash_movements');
        $this->applyFilters($movements, $validated);
        $this->applyFilters($summaryQuery, $validated);

        $summary = $summaryQuery
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE 0 END), 0) as total_in")
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'out' THEN amount ELSE 0 END), 0) as total_out")
            ->first();

        $page = $movements
            ->orderByDesc('occurred_at')
            ->orderBy('type')
            ->orderByDesc('reference_id')
            ->paginate($validated['per_page'] ?? 20);
        $page->getCollection()->transform(function (object $movement): object {
            $movementData = get_object_vars($movement);
            if (! array_key_exists('amount', $movementData)) {
                throw new \UnexpectedValueException('O movimento de caixa não contém o valor esperado.');
            }

            $movementData['amount'] = (float) $movementData['amount'];

            return (object) $movementData;
        });

        return response()->json([
            'currency' => 'AOA',
            'totals' => [
                'in' => (float) $summary->total_in,
                'out' => (float) $summary->total_out,
                'balance' => round((float) $summary->total_in - (float) $summary->total_out, 2),
            ],
            'movements' => $page,
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (isset($filters['from'])) {
            $query->whereDate('occurred_at', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->whereDate('occurred_at', '<=', $filters['to']);
        }
        if (isset($filters['direction'])) {
            $query->where('direction', $filters['direction']);
        }
        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }
    }
}
