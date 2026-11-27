<?php

namespace App\Http\Controllers;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class EmployeeSalesReportController extends Controller
{
    #[OA\Get(
        path: '/api/reports/sales/by-employee',
        tags: ['Reports'],
        summary: 'Relatório de vendas por vendedor, operador e posto de caixa',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [new OA\Response(response: 200, description: 'Totais de vendas por funcionário e sessão')]
    )]
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        $baseQuery = fn (): Builder => DB::table('sales')
            ->where('sales.status', 'completed')
            ->when(isset($validated['from']), fn (Builder $query) => $query->whereDate('sales.created_at', '>=', $validated['from']))
            ->when(isset($validated['to']), fn (Builder $query) => $query->whereDate('sales.created_at', '<=', $validated['to']));

        $bySeller = $baseQuery()
            ->leftJoin('users as sellers', 'sellers.id', '=', 'sales.seller_id')
            ->selectRaw("sales.seller_id, COALESCE(sellers.name, sales.seller_name, 'Sem vendedor') as seller_name, COUNT(sales.id) as sales_count, SUM(sales.total) as total")
            ->groupBy('sales.seller_id', 'sellers.name', 'sales.seller_name')
            ->orderByDesc('total')
            ->get();

        $byOperator = $baseQuery()
            ->leftJoin('users as operators', 'operators.id', '=', 'sales.created_by')
            ->selectRaw("sales.created_by as operator_id, COALESCE(operators.name, 'Operador removido') as operator_name, COUNT(sales.id) as sales_count, SUM(sales.total) as total")
            ->groupBy('sales.created_by', 'operators.name')
            ->orderByDesc('total')
            ->get();

        $byCashSession = $baseQuery()
            ->leftJoin('cash_sessions', 'cash_sessions.id', '=', 'sales.cash_session_id')
            ->leftJoin('cash_registers', 'cash_registers.id', '=', 'cash_sessions.cash_register_id')
            ->leftJoin('users as operators', 'operators.id', '=', 'cash_sessions.opened_by')
            ->selectRaw("sales.cash_session_id, COALESCE(cash_registers.name, 'Sem caixa') as register_name, operators.name as operator_name, cash_sessions.opened_at, cash_sessions.closed_at, COUNT(sales.id) as sales_count, SUM(sales.total) as total")
            ->groupBy('sales.cash_session_id', 'cash_registers.name', 'operators.name', 'cash_sessions.opened_at', 'cash_sessions.closed_at')
            ->orderByDesc('cash_sessions.opened_at')
            ->get();

        return response()->json([
            'currency' => 'AOA',
            'period' => [
                'from' => $validated['from'] ?? null,
                'to' => $validated['to'] ?? null,
            ],
            'by_seller' => $bySeller,
            'by_operator' => $byOperator,
            'by_cash_session' => $byCashSession,
        ]);
    }
}
