<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\CashSession;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\Stock;
use App\Support\StockLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class SaleReturnController extends Controller
{
    #[OA\Post(
        path: '/api/sales/{sale}/returns',
        tags: ['Sales'],
        summary: 'Devolver artigos vendidos e, opcionalmente, criar uma nova venda de troca',
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'sale', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reason', 'refund_method', 'items'],
                properties: [
                    new OA\Property(property: 'reason', type: 'string', maxLength: 255),
                    new OA\Property(property: 'refund_method', type: 'string', enum: ['dinheiro', 'cartao', 'transferencia']),
                    new OA\Property(property: 'items', type: 'array', items: new OA\Items(
                        type: 'object',
                        required: ['sale_item_id', 'quantity'],
                        properties: [
                            new OA\Property(property: 'sale_item_id', type: 'integer'),
                            new OA\Property(property: 'quantity', type: 'integer', minimum: 1),
                        ]
                    )),
                    new OA\Property(property: 'exchange_items', type: 'array', nullable: true, description: 'Artigos da nova venda de troca; se enviados, cria uma venda separada associada à devolução.', items: new OA\Items(
                        type: 'object',
                        required: ['product_id', 'quantity'],
                        properties: [
                            new OA\Property(property: 'product_id', type: 'integer'),
                            new OA\Property(property: 'quantity', type: 'integer', minimum: 1),
                        ]
                    )),
                    new OA\Property(property: 'exchange_payment_method', type: 'string', enum: ['dinheiro', 'cartao', 'transferencia']),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Devolução processada'),
            new OA\Response(response: 403, description: 'Sem permissão para registar devoluções'),
            new OA\Response(response: 404, description: 'Venda não encontrada ou não acessível'),
            new OA\Response(response: 422, description: 'Quantidade devolvida superior à comprada ou dados inválidos'),
        ]
    )]
    public function store(Request $request, int $sale): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->canManageSales(), 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'refund_method' => ['required', 'string', 'in:dinheiro,cartao,transferencia'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sale_item_id' => ['required', 'integer', 'distinct', 'exists:sale_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'exchange_items' => ['sometimes', 'array', 'min:1'],
            'exchange_items.*.product_id' => ['required_with:exchange_items', 'integer', 'exists:products,id'],
            'exchange_items.*.quantity' => ['required_with:exchange_items', 'integer', 'min:1'],
            'exchange_payment_method' => ['required_with:exchange_items', 'string', 'in:dinheiro,cartao,transferencia'],
        ]);

        if (! is_array($validated['items'])) {
            throw new \LogicException('Os artigos validados da devolução devem ser uma lista.');
        }

        $saleItemIds = array_map('intval', array_column($validated['items'], 'sale_item_id'));

        $result = DB::transaction(function () use ($request, $sale, $validated, $saleItemIds): array {
            $saleQuery = Sale::query();
            if (! $request->user()->canViewAllSales()) {
                $saleQuery->where(function ($sales) use ($request): void {
                    $sales->where('created_by', $request->user()->id)
                        ->orWhere('user_id', $request->user()->id);
                });
            }

            $originalSale = $saleQuery->lockForUpdate()->findOrFail($sale);
            if ($originalSale->status !== 'completed') {
                throw ValidationException::withMessages([
                    'sale' => ['Só é possível devolver artigos de uma venda concluída.'],
                ]);
            }

            $session = CashSession::query()
                ->where('opened_by', $request->user()->id)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();
            if ($request->user()->isCashier() && ! $session) {
                throw ValidationException::withMessages([
                    'cash_session' => ['Abra uma sessão de caixa antes de registar uma devolução.'],
                ]);
            }

            $saleItems = SaleItem::query()
                ->where('sale_id', $originalSale->id)
                ->whereIn('id', $saleItemIds)
                ->orderBy('product_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $returnLines = [];
            $refundTotal = 0.0;

            foreach ($validated['items'] as $requestedItem) {
                $saleItem = $saleItems->get($requestedItem['sale_item_id']);
                if (! $saleItem) {
                    throw ValidationException::withMessages([
                        'items' => ['Um ou mais artigos não pertencem à venda indicada.'],
                    ]);
                }

                $alreadyReturned = (int) DB::table('sale_return_items')
                    ->where('sale_item_id', $saleItem->id)
                    ->sum('quantity');
                $availableQuantity = $saleItem->quantity - $alreadyReturned;
                if ($requestedItem['quantity'] > $availableQuantity) {
                    throw ValidationException::withMessages([
                        'items' => ["A quantidade devolvida excede a quantidade ainda não devolvida do artigo {$saleItem->product_id}."],
                    ]);
                }

                $subtotal = round($requestedItem['quantity'] * (float) $saleItem->unit_price, 2);
                $refundTotal += $subtotal;
                $returnLines[] = [$saleItem, $requestedItem['quantity'], $subtotal];
            }

            if ($validated['refund_method'] === 'dinheiro' && $session) {
                $cashSummary = $session->summary();
                if ($refundTotal > $cashSummary['expected_amount']) {
                    throw ValidationException::withMessages([
                        'refund_method' => ['O reembolso excede o dinheiro disponível no caixa.'],
                    ]);
                }
            }

            $saleReturn = SaleReturn::create([
                'sale_id' => $originalSale->id,
                'recorded_by' => $request->user()->id,
                'cash_session_id' => $session?->id,
                'reason' => $validated['reason'],
                'refund_method' => $validated['refund_method'],
                'total' => round($refundTotal, 2),
                'refunded_at' => now(),
            ]);

            foreach ($returnLines as [$saleItem, $quantity, $subtotal]) {
                $stock = Stock::query()->where('product_id', $saleItem->product_id)->lockForUpdate()->first();
                if (! $stock) {
                    throw ValidationException::withMessages([
                        'stock' => ['O registo de stock do produto devolvido não existe.'],
                    ]);
                }
                $stockBefore = $stock->quantity;
                $stock->increment('quantity', $quantity);

                $returnItem = $saleReturn->items()->create([
                    'sale_item_id' => $saleItem->id,
                    'product_id' => $saleItem->product_id,
                    'product_name' => $saleItem->product->name,
                    'quantity' => $quantity,
                    'unit_price' => $saleItem->unit_price,
                    'subtotal' => $subtotal,
                ]);
                StockLedger::record(
                    $saleItem->product_id,
                    $request->user()->id,
                    'return',
                    'in',
                    $quantity,
                    $stockBefore,
                    $stock->quantity,
                    'Devolução da venda #'.$originalSale->id,
                    $returnItem,
                );
            }

            $exchangeSale = null;
            if (isset($validated['exchange_items'])) {
                $groupedItems = [];
                foreach ($validated['exchange_items'] as $item) {
                    $productId = $item['product_id'];
                    $groupedItems[$productId] = ($groupedItems[$productId] ?? 0) + $item['quantity'];
                }
                ksort($groupedItems);
                $exchangeSale = Sale::create([
                    'user_id' => $originalSale->user_id,
                    'created_by' => $request->user()->id,
                    'seller_id' => $originalSale->seller_id,
                    'seller_name' => $originalSale->seller_name,
                    'cash_session_id' => $session?->id,
                    'exchange_for_return_id' => $saleReturn->id,
                    'customer_name' => $originalSale->customer_name,
                    'customer_type' => $originalSale->customer_type,
                    'customer_nif' => $originalSale->customer_nif,
                    'customer_email' => $originalSale->customer_email,
                    'customer_phone' => $originalSale->customer_phone,
                    'customer_address' => $originalSale->customer_address,
                    'total' => 0,
                    'status' => 'completed',
                    'payment_method' => $validated['exchange_payment_method'],
                    'payment_status' => 'paid',
                    'currency' => $originalSale->currency,
                    'paid_at' => now(),
                ]);

                $exchangeTotal = 0.0;
                foreach ($groupedItems as $productId => $quantity) {
                    $product = Product::query()->lockForUpdate()->findOrFail($productId);
                    $stock = Stock::query()->where('product_id', $product->id)->lockForUpdate()->first();
                    if (! $stock || $stock->quantity < $quantity) {
                        throw ValidationException::withMessages([
                            'exchange_items' => ['Stock insuficiente para um ou mais artigos da troca.'],
                        ]);
                    }
                    $unitPrice = (float) $product->price;
                    $subtotal = round($unitPrice * $quantity, 2);
                    $exchangeItem = $exchangeSale->items()->create([
                        'product_id' => $product->id,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'subtotal' => $subtotal,
                    ]);
                    $stockBefore = $stock->quantity;
                    $stock->decrement('quantity', $quantity);
                    StockLedger::record(
                        $product->id,
                        $request->user()->id,
                        'sale',
                        'out',
                        $quantity,
                        $stockBefore,
                        $stock->quantity,
                        'Venda de troca #'.$exchangeSale->id,
                        $exchangeSale,
                    );
                    $exchangeTotal += $subtotal;
                }
                $exchangeSale->update(['total' => round($exchangeTotal, 2)]);
                $saleReturn->update(['exchange_sale_id' => $exchangeSale->id]);
            }

            $this->logActivity($request, 'sale.returned', $saleReturn, [
                'sale_id' => $originalSale->id,
                'total' => $saleReturn->total,
                'exchange_sale_id' => $exchangeSale?->id,
            ]);

            return [
                'return' => $saleReturn->load(['items.product', 'recorder', 'exchangeSale']),
                'exchange_sale' => $exchangeSale?->load(['items.product', 'seller', 'creator']),
            ];
        });

        return response()->json([
            'message' => 'Devolução registada com sucesso.',
            ...$result,
        ], 201);
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
