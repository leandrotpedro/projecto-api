<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Support\StockLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class StockController extends Controller
{
    #[OA\Get(
        path: '/api/stock/movements',
        tags: ['Stock'],
        summary: 'Consultar histórico unificado de movimentos de stock',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['initial', 'purchase', 'sale', 'cancellation', 'return', 'adjustment'])),
            new OA\Parameter(name: 'from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),
        ],
        responses: [new OA\Response(response: 200, description: 'Movimentos paginados com stock anterior e posterior')]
    )]
    public function movements(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['sometimes', 'integer', 'exists:products,id'],
            'type' => ['sometimes', 'string', Rule::in(['initial', 'purchase', 'sale', 'cancellation', 'return', 'adjustment'])],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', Rule::when($request->filled('from'), ['after_or_equal:from'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = StockMovement::query()->with(['product', 'recorder'])->latest();
        foreach (['product_id', 'type'] as $filter) {
            if (isset($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }
        if (isset($validated['from'])) {
            $query->whereDate('created_at', '>=', $validated['from']);
        }
        if (isset($validated['to'])) {
            $query->whereDate('created_at', '<=', $validated['to']);
        }

        return response()->json($query->paginate($validated['per_page'] ?? 20));
    }

    #[OA\Get(
        path: '/api/stock',
        operationId: 'listStock',
        tags: ['Stock'],
        summary: 'Listar stock dos produtos',
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Lista de stock'),
        ]
    )]
    public function index(): JsonResponse
    {
        return response()->json(
            Product::with('stock')->get()->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'stock' => $product->stock->quantity,
                ];
            })
        );
    }

    #[OA\Get(
        path: '/api/stock/{product}',
        operationId: 'showProductStock',
        tags: ['Stock'],
        summary: 'Consultar stock de um produto',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(
                name: 'product',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Stock do produto'),
            new OA\Response(response: 404, description: 'Produto não encontrado'),
        ]
    )]
    public function show(string $product): JsonResponse
    {
        $item = Product::with('stock')->findOrFail($product);

        return response()->json([
            'id' => $item->id,
            'name' => $item->name,
            'stock' => $item->stock->quantity,
        ]);
    }

    #[OA\Patch(
        path: '/api/stock/{product}',
        operationId: 'updateProductStock',
        tags: ['Stock'],
        summary: 'Atualizar stock de um produto',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(
                name: 'product',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['stock', 'reason'],
                properties: [
                    new OA\Property(
                        property: 'stock',
                        type: 'integer',
                        minimum: 0,
                        example: 25
                    ),
                    new OA\Property(
                        property: 'reason',
                        type: 'string',
                        minLength: 3,
                        maxLength: 255,
                        example: 'Ajuste após contagem física'
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Stock atualizado'),
            new OA\Response(response: 404, description: 'Produto não encontrado'),
            new OA\Response(response: 422, description: 'Dados inválidos'),
        ]
    )]
    public function update(Request $request, string $product): JsonResponse
    {
        $validated = $request->validate([
            'stock' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        [$item, $stock] = DB::transaction(function () use ($validated, $request, $product) {
            $item = Product::query()->findOrFail($product);
            $stock = Stock::query()->where('product_id', $item->id)->lockForUpdate()->first();
            $previousQuantity = $stock->quantity ?? 0;
            $stock ??= new Stock(['product_id' => $item->id]);
            $stock->quantity = $validated['stock'];
            $stock->save();

            if ($validated['stock'] !== $previousQuantity) {
                StockLedger::record(
                    $item->id,
                    $request->user()->id,
                    'adjustment',
                    $validated['stock'] > $previousQuantity ? 'in' : 'out',
                    abs($validated['stock'] - $previousQuantity),
                    $previousQuantity,
                    $validated['stock'],
                    $validated['reason'],
                    $stock,
                );
            }

            ActivityLog::create([
                'actor_id' => $request->user()->id,
                'action' => 'stock.updated',
                'subject_type' => Stock::class,
                'subject_id' => $stock->id,
                'changes' => [
                    'product_id' => $item->id,
                    'from' => $previousQuantity,
                    'to' => $stock->quantity,
                    'reason' => $validated['reason'],
                ],
                'ip_address' => $request->ip(),
            ]);

            return [$item, $stock];
        });

        return response()->json([
            'id' => $item->id,
            'name' => $item->name,
            'stock' => $stock->quantity,
        ]);
    }
}
