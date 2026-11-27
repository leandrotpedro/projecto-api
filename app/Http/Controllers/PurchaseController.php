<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Stock;
use App\Models\StockEntry;
use App\Models\Supplier;
use App\Support\StockLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class PurchaseController extends Controller
{
    #[OA\Get(
        path: '/api/purchases',
        tags: ['Purchases'],
        summary: 'Listar compras recebidas',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'supplier_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),
        ],
        responses: [new OA\Response(response: 200, description: 'Lista paginada de compras')]
    )]
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => ['sometimes', 'integer', 'exists:suppliers,id'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', Rule::when($request->filled('from'), ['after_or_equal:from'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Purchase::query()->with(['supplier', 'recorder', 'items']);
        if (isset($validated['supplier_id'])) {
            $query->where('supplier_id', $validated['supplier_id']);
        }
        if (isset($validated['from'])) {
            $query->whereDate('purchase_date', '>=', $validated['from']);
        }
        if (isset($validated['to'])) {
            $query->whereDate('purchase_date', '<=', $validated['to']);
        }

        return response()->json($query->latest('purchase_date')->paginate($validated['per_page'] ?? 20));
    }

    #[OA\Get(
        path: '/api/purchases/{purchase}',
        tags: ['Purchases'],
        summary: 'Consultar uma compra recebida',
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'purchase', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Compra encontrada'),
            new OA\Response(response: 404, description: 'Compra não encontrada'),
        ]
    )]
    public function show(int $purchase): JsonResponse
    {
        return response()->json(
            Purchase::query()
                ->with(['supplier', 'recorder', 'items'])
                ->findOrFail($purchase)
        );
    }

    #[OA\Post(
        path: '/api/purchases',
        tags: ['Purchases'],
        summary: 'Registar compra já recebida e actualizar o stock',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['supplier_id', 'items'],
                properties: [
                    new OA\Property(property: 'supplier_id', type: 'integer', example: 1),
                    new OA\Property(property: 'purchase_date', type: 'string', format: 'date', example: '2026-11-10'),
                    new OA\Property(property: 'invoice_number', type: 'string', nullable: true),
                    new OA\Property(property: 'notes', type: 'string', nullable: true),
                    new OA\Property(property: 'payment_status', type: 'string', enum: ['pending', 'paid'], description: 'Por omissão, pending. Para paid, indique também payment_method.'),
                    new OA\Property(property: 'payment_method', type: 'string', nullable: true, enum: ['dinheiro', 'cartao', 'transferencia']),
                    new OA\Property(
                        property: 'items',
                        type: 'array',
                        items: new OA\Items(
                            type: 'object',
                            required: ['product_id', 'quantity', 'unit_cost'],
                            properties: [
                                new OA\Property(property: 'product_id', type: 'integer'),
                                new OA\Property(property: 'quantity', type: 'integer', minimum: 1),
                                new OA\Property(property: 'unit_cost', type: 'number', minimum: 0, example: 1250.00),
                            ]
                        )
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Compra registada como recebida e stock actualizado'),
            new OA\Response(response: 422, description: 'Dados inválidos'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => [
                'required',
                'integer',
                Rule::exists('suppliers', 'id')->where('is_active', true),
            ],
            'purchase_date' => ['sometimes', 'date'],
            'invoice_number' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'payment_status' => ['sometimes', 'string', 'in:pending,paid'],
            'payment_method' => [
                'prohibited_unless:payment_status,paid',
                'required_if:payment_status,paid',
                'nullable',
                'string',
                'in:dinheiro,cartao,transferencia',
            ],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
        ]);

        $lineTotals = [];
        $total = 0.0;
        foreach ($validated['items'] as $index => $item) {
            $lineTotal = round($item['quantity'] * (float) $item['unit_cost'], 2);
            $lineTotals[$index] = $lineTotal;
            $total += $lineTotal;
        }
        $total = round($total, 2);
        if ($total > 999999999999.99) {
            throw ValidationException::withMessages([
                'items' => ['O total da compra excede o limite permitido.'],
            ]);
        }

        $purchase = DB::transaction(function () use ($validated, $lineTotals, $total, $request): Purchase {
            $supplier = Supplier::query()
                ->whereKey($validated['supplier_id'])
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();

            if (! is_array($validated['items'])) {
                throw new \LogicException('Os artigos validados da compra devem ser uma lista.');
            }

            $productIds = array_values(array_unique(array_map(
                'intval',
                array_column($validated['items'], 'product_id')
            )));
            sort($productIds);
            $products = Product::query()
                ->whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($products->count() !== count($productIds)) {
                throw ValidationException::withMessages([
                    'items' => ['Um ou mais produtos já não estão disponíveis.'],
                ]);
            }

            $purchase = Purchase::create([
                'supplier_id' => $supplier->id,
                'recorded_by' => $request->user()->id,
                'supplier_name' => $supplier->name,
                'invoice_number' => $validated['invoice_number'] ?? null,
                'purchase_date' => $validated['purchase_date'] ?? today()->toDateString(),
                'received_at' => now(),
                'total' => $total,
                'notes' => $validated['notes'] ?? null,
                'payment_status' => $validated['payment_status'] ?? 'pending',
                'payment_method' => $validated['payment_method'] ?? null,
                'paid_at' => ($validated['payment_status'] ?? 'pending') === 'paid' ? now() : null,
            ]);

            $stockChanges = [];
            foreach ($validated['items'] as $index => $item) {
                $product = $products->get((int) $item['product_id']);
                $stock = Stock::query()
                    ->where('product_id', $product->id)
                    ->lockForUpdate()
                    ->first();

                if (! $stock) {
                    $stock = Stock::create([
                        'product_id' => $product->id,
                        'quantity' => 0,
                    ]);
                }

                $stockBefore = $stock->quantity;
                $stock->increment('quantity', (int) $item['quantity']);
                $stock->refresh();

                $unitCost = round((float) $item['unit_cost'], 2);
                $purchaseItem = PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'quantity' => $item['quantity'],
                    'unit_cost' => $unitCost,
                    'subtotal' => $lineTotals[$index],
                ]);

                StockEntry::create([
                    'purchase_id' => $purchase->id,
                    'purchase_item_id' => $purchaseItem->id,
                    'product_id' => $product->id,
                    'supplier_id' => $supplier->id,
                    'recorded_by' => $request->user()->id,
                    'product_name' => $product->name,
                    'supplier_name' => $supplier->name,
                    'quantity' => $item['quantity'],
                    'stock_before' => $stock->quantity - $item['quantity'],
                    'stock_after' => $stock->quantity,
                    'unit_cost' => $unitCost,
                    'total_cost' => $lineTotals[$index],
                    'occurred_at' => now(),
                ]);
                StockLedger::record(
                    $product->id,
                    $request->user()->id,
                    'purchase',
                    'in',
                    $item['quantity'],
                    $stockBefore,
                    $stock->quantity,
                    'Compra recebida: '.$supplier->name,
                    $purchase,
                );

                $stockChanges[] = [
                    'product_id' => $product->id,
                    'quantity_added' => $item['quantity'],
                    'stock_after' => $stock->quantity,
                ];
            }

            ActivityLog::create([
                'actor_id' => $request->user()->id,
                'action' => 'purchase.received',
                'subject_type' => Purchase::class,
                'subject_id' => $purchase->id,
                'changes' => [
                    'supplier_id' => $supplier->id,
                    'total' => $total,
                    'payment_status' => $purchase->payment_status,
                    'payment_method' => $purchase->payment_method,
                    'stock' => $stockChanges,
                ],
                'ip_address' => $request->ip(),
            ]);

            return $purchase->load(['supplier', 'recorder', 'items']);
        });

        return response()->json([
            'message' => 'Compra registada como recebida; o stock foi actualizado.',
            'purchase' => $purchase,
        ], 201);
    }

    #[OA\Patch(
        path: '/api/purchases/{purchase}/payment',
        tags: ['Purchases'],
        summary: 'Marcar uma compra recebida como paga',
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'purchase', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['payment_method'],
                properties: [
                    new OA\Property(property: 'payment_method', type: 'string', enum: ['dinheiro', 'cartao', 'transferencia']),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Pagamento registado'),
            new OA\Response(response: 409, description: 'Compra já paga'),
        ]
    )]
    public function markPaid(Request $request, int $purchase): JsonResponse
    {
        $validated = $request->validate([
            'payment_method' => ['required', 'string', 'in:dinheiro,cartao,transferencia'],
        ]);

        $record = DB::transaction(function () use ($request, $validated, $purchase): Purchase {
            $record = Purchase::query()->lockForUpdate()->findOrFail($purchase);
            if ($record->payment_status === 'paid') {
                abort(409, 'Esta compra já está paga.');
            }

            $record->update([
                'payment_status' => 'paid',
                'payment_method' => $validated['payment_method'],
                'paid_at' => now(),
            ]);

            ActivityLog::create([
                'actor_id' => $request->user()->id,
                'action' => 'purchase.paid',
                'subject_type' => Purchase::class,
                'subject_id' => $record->id,
                'changes' => [
                    'payment_status' => ['from' => 'pending', 'to' => 'paid'],
                    'payment_method' => $validated['payment_method'],
                ],
                'ip_address' => $request->ip(),
            ]);

            return $record->load(['supplier', 'recorder', 'items']);
        });

        return response()->json([
            'message' => 'Pagamento da compra registado com sucesso.',
            'purchase' => $record,
        ]);
    }
}
