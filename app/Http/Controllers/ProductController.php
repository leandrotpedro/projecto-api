<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\Stock;
use App\Support\StockLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;

class ProductController extends Controller
{
    // Listar todos os produtos
    #[OA\Get(
        path: '/api/products',
        summary: 'Listar todos os produtos',
        tags: ['Produtos'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lista de produtos obtida com sucesso'
            ),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
        ]);
        $query = Product::with('stock');
        if (isset($validated['search'])) {
            $search = trim($validated['search']);
            $query->where(function ($products) use ($search): void {
                $products->where('name', 'like', '%'.$search.'%')
                    ->orWhere('sku', $search)
                    ->orWhere('barcode', $search);
            });
        }

        return response()->json($query->orderBy('name')->get()->map(function (Product $product) {
            return array_merge($product->toArray(), [
                'stock' => $product->stock->quantity ?? 0,
            ]);
        }));
    }

    #[OA\Get(
        path: '/api/products/lookup/{identifier}',
        summary: 'Pesquisar produto por código de barras, SKU ou ID',
        tags: ['Produtos'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Produto localizado'),
            new OA\Response(response: 404, description: 'Produto não encontrado'),
        ]
    )]
    public function lookup(string $identifier): JsonResponse
    {
        $product = Product::query()
            ->with('stock')
            ->where('barcode', $identifier)
            ->orWhere('sku', $identifier)
            ->when(ctype_digit($identifier), fn ($query) => $query->orWhereKey((int) $identifier))
            ->firstOrFail();

        return response()->json(array_merge($product->toArray(), [
            'stock' => $product->stock->quantity ?? 0,
        ]));
    }

    // Registar um novo produto
    #[OA\Post(
        path: '/api/products',
        summary: 'Registar um novo produto',
        tags: ['Produtos'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['category_id', 'name', 'price'],
                properties: [
                    new OA\Property(
                        property: 'category_id',
                        type: 'integer',
                        example: 1,
                        description: 'ID da categoria do produto'
                    ),
                    new OA\Property(
                        property: 'name',
                        type: 'string',
                        example: 'Teclado'
                    ),
                    new OA\Property(
                        property: 'description',
                        type: 'string',
                        example: 'Teclado USB'
                    ),
                    new OA\Property(property: 'sku', type: 'string', example: 'TEC-001', nullable: true),
                    new OA\Property(property: 'barcode', type: 'string', example: '5601234567890', nullable: true),
                    new OA\Property(property: 'manufacturing_date', type: 'string', format: 'date', example: '2026-01-15', nullable: true),
                    new OA\Property(property: 'expiration_date', type: 'string', format: 'date', example: '2027-01-15', nullable: true),
                    new OA\Property(
                        property: 'price',
                        type: 'number',
                        format: 'float',
                        example: 15000
                    ),
                    new OA\Property(
                        property: 'stock',
                        type: 'integer',
                        example: 10
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Produto criado com sucesso'
            ),
            new OA\Response(
                response: 422,
                description: 'Dados inválidos'
            ),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => 'required|exists:categories,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'sku' => ['sometimes', 'nullable', 'string', 'max:100', 'unique:products,sku'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:100', 'unique:products,barcode'],
            'manufacturing_date' => ['sometimes', 'nullable', 'date'],
            'expiration_date' => ['sometimes', 'nullable', 'date', Rule::when($request->filled('manufacturing_date'), ['after_or_equal:manufacturing_date'])],
            'price' => 'required|numeric|min:0',
            'stock' => 'sometimes|integer|min:0',
        ]);

        $product = DB::transaction(function () use ($validated, $request) {
            $initialStock = $validated['stock'] ?? 0;
            unset($validated['stock']);

            $product = Product::create($validated);
            Stock::create([
                'product_id' => $product->id,
                'quantity' => $initialStock,
            ]);
            if ($initialStock > 0) {
                StockLedger::record(
                    $product->id,
                    $request->user()->id,
                    'initial',
                    'in',
                    $initialStock,
                    0,
                    $initialStock,
                    'Stock inicial do produto',
                    $product,
                );
            }

            ActivityLog::create([
                'actor_id' => $request->user()->id,
                'action' => 'product.created',
                'subject_type' => Product::class,
                'subject_id' => $product->id,
                'changes' => ['stock' => $initialStock],
                'ip_address' => $request->ip(),
            ]);

            return $product->load('stock');
        });

        return response()->json([
            'message' => 'Produto criado com sucesso.',
            'product' => array_merge($product->toArray(), [
                'stock' => $product->stock->quantity,
            ]),
        ], 201);
    }

    // Consultar um produto pelo ID
    #[OA\Get(
        path: '/api/products/{id}',
        summary: 'Consultar um produto pelo ID',
        tags: ['Produtos'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'ID do produto',
                schema: new OA\Schema(type: 'integer'),
                example: 1
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Produto encontrado'
            ),
            new OA\Response(
                response: 404,
                description: 'Produto não encontrado'
            ),
        ]
    )]
    public function show(int $id): JsonResponse
    {
        $product = Product::with('stock')->find($id);

        if (! $product) {
            return response()->json([
                'message' => 'Produto não encontrado.',
            ], 404);
        }

        return response()->json(array_merge($product->toArray(), [
            'stock' => $product->stock->quantity,
        ]));
    }

    // Actualizar um produto existente
    #[OA\Put(
        path: '/api/products/{id}',
        summary: 'Actualizar um produto (administradores e gerentes)',
        tags: ['Produtos'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'ID do produto a actualizar',
                schema: new OA\Schema(type: 'integer'),
                example: 1
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(
                        property: 'name',
                        type: 'string',
                        example: 'Teclado mecânico'
                    ),
                    new OA\Property(
                        property: 'description',
                        type: 'string',
                        example: 'Teclado USB actualizado'
                    ),
                    new OA\Property(property: 'sku', type: 'string', example: 'TEC-001', nullable: true),
                    new OA\Property(property: 'barcode', type: 'string', example: '5601234567890', nullable: true),
                    new OA\Property(property: 'manufacturing_date', type: 'string', format: 'date', example: '2026-01-15', nullable: true),
                    new OA\Property(property: 'expiration_date', type: 'string', format: 'date', example: '2027-01-15', nullable: true),
                    new OA\Property(
                        property: 'price',
                        type: 'number',
                        format: 'float',
                        example: 20000,
                        description: 'Administradores e gerentes podem alterar o preço de um produto existente'
                    ),
                    new OA\Property(
                        property: 'stock',
                        type: 'integer',
                        example: 15
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Produto actualizado com sucesso'
            ),
            new OA\Response(
                response: 404,
                description: 'Produto não encontrado'
            ),
            new OA\Response(
                response: 422,
                description: 'Dados inválidos'
            ),
        ]
    )]
    public function update(Request $request, int $id): JsonResponse
    {
        $product = Product::with('stock')->find($id);

        if (! $product) {
            return response()->json([
                'message' => 'Produto não encontrado.',
            ], 404);
        }

        $validated = $request->validate([
            'category_id' => 'sometimes|required|exists:categories,id',
            'name' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|nullable|string',
            'sku' => ['sometimes', 'nullable', 'string', 'max:100', Rule::unique('products', 'sku')->ignore($product->id)],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:100', Rule::unique('products', 'barcode')->ignore($product->id)],
            'manufacturing_date' => ['sometimes', 'nullable', 'date'],
            'expiration_date' => ['sometimes', 'nullable', 'date'],
            'price' => 'sometimes|required|numeric|min:0',
            'stock' => 'sometimes|required|integer|min:0',
            'stock_reason' => ['required_with:stock', 'string', 'min:3', 'max:255'],
        ]);

        $manufacturingDate = array_key_exists('manufacturing_date', $validated)
            ? $validated['manufacturing_date']
            : ($product->manufacturing_date === null ? null : Carbon::parse($product->manufacturing_date)->toDateString());
        $expirationDate = array_key_exists('expiration_date', $validated)
            ? $validated['expiration_date']
            : ($product->expiration_date === null ? null : Carbon::parse($product->expiration_date)->toDateString());
        if ($manufacturingDate !== null && $expirationDate !== null
            && Carbon::parse($expirationDate)->lt($manufacturingDate)) {
            throw ValidationException::withMessages([
                'expiration_date' => ['A data de caducidade deve ser igual ou posterior à data de fabrico.'],
            ]);
        }

        DB::transaction(function () use ($product, $validated, $request) {
            $stock = Stock::query()->where('product_id', $product->id)->lockForUpdate()->first();
            $oldStock = $stock->quantity ?? 0;
            $stockWasProvided = array_key_exists('stock', $validated);
            $newStock = $validated['stock'] ?? null;
            $oldAttributes = array_intersect_key($product->getAttributes(), $validated);
            unset($validated['stock']);
            $stockReason = $validated['stock_reason'] ?? null;
            unset($validated['stock_reason']);
            $product->update($validated);

            if ($stockWasProvided) {
                $stock ??= new Stock(['product_id' => $product->id]);
                $stock->quantity = $newStock;
                $stock->save();
                if ($newStock !== $oldStock) {
                    StockLedger::record(
                        $product->id,
                        $request->user()->id,
                        'adjustment',
                        $newStock > $oldStock ? 'in' : 'out',
                        abs($newStock - $oldStock),
                        $oldStock,
                        $newStock,
                        $stockReason,
                        $stock,
                    );
                }
            }

            ActivityLog::create([
                'actor_id' => $request->user()->id,
                'action' => 'product.updated',
                'subject_type' => Product::class,
                'subject_id' => $product->id,
                'changes' => [
                    'fields' => array_map(
                        fn ($value, $key) => ['from' => $oldAttributes[$key] ?? null, 'to' => $value],
                        $validated,
                        array_keys($validated),
                    ),
                    'stock' => $stockWasProvided ? ['from' => $oldStock, 'to' => $newStock] : null,
                ],
                'ip_address' => $request->ip(),
            ]);
        });
        $product->load('stock');

        return response()->json([
            'message' => 'Produto actualizado com sucesso.',
            'product' => array_merge($product->toArray(), [
                'stock' => $product->stock->quantity,
            ]),
        ]);
    }

    // Eliminar um produto
    #[OA\Delete(
        path: '/api/products/{id}',
        summary: 'Eliminar um produto',
        tags: ['Produtos'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'ID do produto a eliminar',
                schema: new OA\Schema(type: 'integer'),
                example: 3
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Produto eliminado com sucesso'
            ),
            new OA\Response(
                response: 404,
                description: 'Produto não encontrado'
            ),
        ]
    )]
    public function destroy(int $id): JsonResponse
    {
        $product = Product::find($id);

        if (! $product) {
            return response()->json([
                'message' => 'Produto não encontrado.',
            ], 404);
        }

        if ($product->saleItems()->exists()) {
            return response()->json([
                'message' => 'Não é possível eliminar um produto associado a vendas.',
            ], 422);
        }

        ActivityLog::create([
            'actor_id' => request()->user()->id,
            'action' => 'product.deleted',
            'subject_type' => Product::class,
            'subject_id' => $product->id,
            'changes' => ['name' => $product->name],
            'ip_address' => request()->ip(),
        ]);
        $product->delete();

        return response()->json([
            'message' => 'Produto eliminado com sucesso.',
        ]);
    }
}
