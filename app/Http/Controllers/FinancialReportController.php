<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleReturn;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class FinancialReportController extends Controller
{
    #[OA\Get(
        path: '/api/reports/financial',
        tags: ['Reports'],
        summary: 'Resumo financeiro diário, semanal ou mensal',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'period', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['daily', 'weekly', 'monthly'])),
            new OA\Parameter(name: 'date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date', description: 'Dia de referência; por omissão, a data actual')),
        ],
        responses: [new OA\Response(response: 200, description: 'Resumo de vendas pagas, reembolsos, compras recebidas e saldo operacional estimado em AOA')]
    )]
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['required', 'string', 'in:daily,weekly,monthly'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $referenceDate = CarbonImmutable::parse($validated['date'] ?? today()->toDateString());
        [$start, $end] = match ($validated['period']) {
            'daily' => [$referenceDate->startOfDay(), $referenceDate->startOfDay()->addDay()],
            'weekly' => [
                $referenceDate->startOfWeek(CarbonImmutable::MONDAY),
                $referenceDate->startOfWeek(CarbonImmutable::MONDAY)->addWeek(),
            ],
            'monthly' => [$referenceDate->startOfMonth(), $referenceDate->startOfMonth()->addMonth()],
            default => throw ValidationException::withMessages([
                'period' => ['O período indicado não é válido.'],
            ]),
        };

        $grossSales = (float) Sale::query()
            ->whereIn('payment_status', ['paid', 'refund_pending', 'refunded'])
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $start)
            ->where('paid_at', '<', $end)
            ->sum('total');

        $cancelledRefunds = (float) Sale::query()
            ->where('payment_status', 'refunded')
            ->whereNotNull('cancelled_at')
            ->where('cancelled_at', '>=', $start)
            ->where('cancelled_at', '<', $end)
            ->sum('total');

        $saleReturns = (float) SaleReturn::query()
            ->where('refunded_at', '>=', $start)
            ->where('refunded_at', '<', $end)
            ->sum('total');
        $refunds = $cancelledRefunds + $saleReturns;

        $pendingRefunds = (float) Sale::query()
            ->where('payment_status', 'refund_pending')
            ->whereNotNull('cancelled_at')
            ->where('cancelled_at', '>=', $start)
            ->where('cancelled_at', '<', $end)
            ->sum('total');

        $purchasesTotal = (float) Purchase::query()
            ->where('payment_status', 'paid')
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $start)
            ->where('paid_at', '<', $end)
            ->sum('total');

        $expensesTotal = (float) Expense::query()
            ->whereDate('expense_date', '>=', $start->toDateString())
            ->whereDate('expense_date', '<', $end->toDateString())
            ->sum('amount');

        $netSales = round($grossSales - $refunds, 2);

        return response()->json([
            'period' => $validated['period'],
            'reference_date' => $referenceDate->toDateString(),
            'from' => $start->toDateString(),
            'to' => $end->subDay()->toDateString(),
            'currency' => 'AOA',
            'sales_received' => round($grossSales, 2),
            'refunds_confirmed' => round($refunds, 2),
            'refunds_pending' => round($pendingRefunds, 2),
            'net_sales' => $netSales,
            'stock_purchases_recorded' => round($purchasesTotal, 2),
            'expenses_recorded' => round($expensesTotal, 2),
            'estimated_operational_balance' => round($netSales - $purchasesTotal - $expensesTotal, 2),
            'note' => 'O saldo considera como saída apenas compras a fornecedores marcadas como pagas e despesas registadas.',
        ]);
    }
}
