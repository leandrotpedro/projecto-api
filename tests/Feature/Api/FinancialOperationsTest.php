<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\CashSession;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_session_reconciles_opening_sales_manual_movements_and_cash_expenses(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'administrador'])->save();
        $cashier = User::factory()->create();
        $cashier->forceFill(['role' => 'caixa'])->save();
        $client = User::factory()->create();
        $category = Category::create([
            'name' => 'Caixa',
            'description' => null,
            'status' => 'activo',
        ]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produto',
            'description' => null,
            'price' => 1250,
        ]);
        $product->stock()->create(['quantity' => 10]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/cash-registers', ['name' => 'Caixa 2'])
            ->assertCreated();

        $this->actingAs($cashier, 'sanctum')
            ->getJson('/api/cash-registers')
            ->assertOk()
            ->assertJsonCount(2);
        $this->postJson('/api/expenses', [
            'description' => 'Material de limpeza',
            'amount' => 100,
            'expense_date' => now()->toDateString(),
            'payment_method' => 'dinheiro',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('cash_session');
        $register = $this->postJson('/api/cash-sessions', [
            'cash_register_id' => 1,
            'opening_amount' => 1000,
        ])->assertCreated()
            ->assertJsonPath('cash_session.status', 'open')
            ->assertJsonPath('summary.expected_amount', 1000)
            ->json('cash_session');

        $this->postJson('/api/cash-sessions', [
            'cash_register_id' => 1,
            'opening_amount' => 1000,
        ])->assertStatus(409);

        $this->postJson('/api/cash-sessions/'.$register['id'].'/movements', [
            'direction' => 'in',
            'amount' => 500,
            'description' => 'Reforço de troco',
        ])->assertCreated()
            ->assertJsonPath('summary.expected_amount', 1500);
        $this->postJson('/api/cash-sessions/'.$register['id'].'/movements', [
            'direction' => 'out',
            'amount' => 2000,
            'description' => 'Saída superior ao saldo',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('amount');
        $this->postJson('/api/cash-sessions/'.$register['id'].'/movements', [
            'direction' => 'out',
            'amount' => 200,
            'description' => 'Retirada autorizada',
        ])->assertCreated()
            ->assertJsonPath('summary.expected_amount', 1300);

        $this->postJson('/api/sales', [
            'user_id' => $client->id,
            'payment_method' => 'dinheiro',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertForbidden();
        $this->postJson('/api/expenses', [
            'description' => 'Material de limpeza',
            'amount' => 100,
            'expense_date' => now()->toDateString(),
            'payment_method' => 'dinheiro',
        ])->assertCreated()
            ->assertJsonPath('expense.cash_session_id', $register['id']);

        $this->getJson('/api/cash-sessions/'.$register['id'])
            ->assertOk()
            ->assertJsonPath('summary.cash_sales', 0)
            ->assertJsonPath('summary.cash_expenses', 100)
            ->assertJsonPath('summary.manual_in', 500)
            ->assertJsonPath('summary.manual_out', 200)
            ->assertJsonPath('summary.expected_amount', 1200);

        $this->postJson('/api/cash-sessions/'.$register['id'].'/close', [
            'counted_amount' => 1150,
            'notes' => 'Diferença de 50 AOA conferida',
        ])->assertOk()
            ->assertJsonPath('cash_session.status', 'closed')
            ->assertJsonPath('cash_session.expected_amount', '1200.00')
            ->assertJsonPath('cash_session.counted_amount', '1150.00')
            ->assertJsonPath('cash_session.discrepancy', '-50.00');

        $this->postJson('/api/cash-sessions/'.$register['id'].'/movements', [
            'direction' => 'in',
            'amount' => 1,
            'description' => 'Tentativa após fecho',
        ])->assertStatus(409);

        $this->getJson('/api/cash-movements?type=cash_adjustment')
            ->assertOk()
            ->assertJsonCount(2, 'movements.data');
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'cash_session.opened',
            'subject_type' => CashSession::class,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'cash_session.closed',
            'subject_type' => CashSession::class,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/cash-registers/1', ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('cash_register.is_active', false);
        $this->actingAs($cashier, 'sanctum')
            ->getJson('/api/cash-registers')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Caixa 2');
    }

    public function test_seller_can_register_partial_return_and_exchange_with_stock_and_cash_reconciliation(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'administrador'])->save();
        $seller = User::factory()->create();
        $seller->forceFill(['role' => 'vendedor'])->save();
        $category = Category::create(['name' => 'Trocas', 'description' => null, 'status' => 'activo']);
        $returnedProduct = Product::create([
            'category_id' => $category->id,
            'name' => 'Produto devolvido',
            'price' => 1250,
        ]);
        $returnedProduct->stock()->create(['quantity' => 10]);
        $exchangeProduct = Product::create([
            'category_id' => $category->id,
            'name' => 'Produto de troca',
            'price' => 500,
        ]);
        $exchangeProduct->stock()->create(['quantity' => 5]);

        $register = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/cash-registers', ['name' => 'Posto de devoluções'])
            ->assertCreated()
            ->json('cash_register');
        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/cash-sessions', [
                'cash_register_id' => $register['id'],
                'opening_amount' => 5000,
            ])
            ->assertCreated()
            ->assertJsonPath('summary.expected_amount', 5000);

        $this->actingAs($seller, 'sanctum');
        $sale = $this->postJson('/api/sales', [
            'customer_name' => 'Cliente da devolução',
            'customer_phone' => '923456789',
            'payment_method' => 'dinheiro',
            'items' => [['product_id' => $returnedProduct->id, 'quantity' => 2]],
        ])->assertCreated()->json('sale');

        $return = $this->postJson('/api/sales/'.$sale['id'].'/returns', [
            'reason' => 'Troca por outro modelo',
            'refund_method' => 'dinheiro',
            'items' => [[
                'sale_item_id' => $sale['items'][0]['id'],
                'quantity' => 1,
            ]],
            'exchange_items' => [[
                'product_id' => $exchangeProduct->id,
                'quantity' => 1,
            ]],
            'exchange_payment_method' => 'dinheiro',
        ])->assertCreated()
            ->assertJsonPath('return.total', '1250.00')
            ->assertJsonPath('exchange_sale.total', 500);
        $this->assertSame(
            $return->json('return.id'),
            $return->json('exchange_sale.exchange_for_return_id'),
        );

        $this->assertSame(9, $returnedProduct->stock()->firstOrFail()->quantity);
        $this->assertSame(4, $exchangeProduct->stock()->firstOrFail()->quantity);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $returnedProduct->id,
            'type' => 'return',
            'direction' => 'in',
            'quantity' => 1,
        ]);
        $this->getJson('/api/cash-sessions/current')
            ->assertOk()
            ->assertJsonPath('summary.cash_refunds', 1250)
            ->assertJsonPath('summary.cash_sales', 3000)
            ->assertJsonPath('summary.expected_amount', 6750);
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/cash-movements?type=refund')
            ->assertOk()
            ->assertJsonPath('movements.data.0.reference_id', $return->json('return.id'))
            ->assertJsonPath('movements.data.0.amount', 1250);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/reports/sales/by-employee')
            ->assertOk()
            ->assertJsonPath('by_seller.0.seller_name', $seller->name)
            ->assertJsonPath('by_operator.0.operator_name', $seller->name)
            ->assertJsonPath('by_cash_session.0.register_name', 'Posto de devoluções');
        $this->getJson('/api/reports/financial?period=daily&date='.today()->toDateString())
            ->assertOk()
            ->assertJsonPath('refunds_confirmed', 1250)
            ->assertJsonPath('net_sales', 1750);
    }

    public function test_only_authorized_staff_can_return_sales_they_can_access(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'administrador'])->save();
        $seller = User::factory()->create();
        $seller->forceFill(['role' => 'vendedor'])->save();
        $customer = User::factory()->create();
        $category = Category::create([
            'name' => 'Devoluções protegidas',
            'description' => null,
            'status' => 'activo',
        ]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produto protegido',
            'price' => 1000,
        ]);
        $product->stock()->create(['quantity' => 5]);

        $sale = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/sales', [
                'payment_method' => 'dinheiro',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertCreated()
            ->json('sale');

        $returnPayload = [
            'reason' => 'Teste de autorização',
            'refund_method' => 'dinheiro',
            'items' => [[
                'sale_item_id' => $sale['items'][0]['id'],
                'quantity' => 1,
            ]],
        ];

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/sales/'.$sale['id'].'/returns', $returnPayload)
            ->assertForbidden();

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/sales/'.$sale['id'].'/returns', $returnPayload)
            ->assertNotFound();

        $this->assertDatabaseCount('sale_returns', 0);
        $this->assertSame(4, $product->stock()->firstOrFail()->quantity);
    }

    public function test_administrators_can_record_expenses_and_see_them_in_cash_movements(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'administrador'])->save();
        $date = '2026-11-13';

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/expenses', [
                'description' => 'Conta de electricidade',
                'category' => 'Serviços',
                'amount' => 25000,
                'expense_date' => $date,
                'payment_method' => 'transferencia',
            ])
            ->assertCreated()
            ->assertJsonPath('expense.description', 'Conta de electricidade')
            ->assertJsonPath('expense.recorded_by', $admin->id);

        $this->getJson('/api/expenses?from='.$date.'&to='.$date)
            ->assertOk()
            ->assertJsonPath('data.0.amount', '25000.00');

        $this->getJson('/api/cash-movements?type=expense&from='.$date.'&to='.$date)
            ->assertOk()
            ->assertJsonPath('totals.out', 25000)
            ->assertJsonPath('totals.balance', -25000)
            ->assertJsonPath('movements.data.0.type', 'expense')
            ->assertJsonPath('movements.data.0.direction', 'out')
            ->assertJsonPath('movements.data.0.amount', 25000)
            ->assertJsonPath('movements.data.0.payment_method', 'transferencia');

        $this->getJson('/api/reports/financial?period=daily&date='.$date)
            ->assertOk()
            ->assertJsonPath('expenses_recorded', 25000)
            ->assertJsonPath('estimated_operational_balance', -25000);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'expense.created',
            'subject_type' => Expense::class,
        ]);
    }

    public function test_cash_movement_history_includes_sales_purchases_refunds_and_expenses(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'administrador'])->save();
        $date = '2026-11-13';
        $sale = Sale::create([
            'created_by' => $admin->id,
            'customer_name' => 'Cliente',
            'total' => 1000,
            'status' => 'completed',
            'payment_method' => 'dinheiro',
            'payment_status' => 'paid',
            'currency' => 'AOA',
            'paid_at' => $date.' 10:00:00',
        ]);
        $refundedSale = Sale::create([
            'created_by' => $admin->id,
            'customer_name' => 'Cliente',
            'total' => 200,
            'status' => 'cancelled',
            'payment_method' => 'cartao',
            'payment_status' => 'refunded',
            'currency' => 'AOA',
            'paid_at' => $date.' 09:00:00',
            'cancelled_at' => $date.' 11:00:00',
        ]);
        $supplier = Supplier::create(['name' => 'Fornecedor Central']);
        Purchase::create([
            'supplier_id' => $supplier->id,
            'recorded_by' => $admin->id,
            'supplier_name' => $supplier->name,
            'purchase_date' => $date,
            'received_at' => $date.' 12:00:00',
            'total' => 500,
            'payment_status' => 'paid',
            'payment_method' => 'dinheiro',
            'paid_at' => $date.' 12:00:00',
        ]);
        Purchase::create([
            'supplier_id' => $supplier->id,
            'recorded_by' => $admin->id,
            'supplier_name' => $supplier->name,
            'purchase_date' => $date,
            'received_at' => $date.' 13:00:00',
            'total' => 900,
        ]);
        Expense::create([
            'recorded_by' => $admin->id,
            'description' => 'Transporte',
            'category' => 'Logística',
            'amount' => 100,
            'expense_date' => $date,
            'payment_method' => 'dinheiro',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/cash-movements?from='.$date.'&to='.$date)
            ->assertOk()
            ->assertJsonPath('totals.in', 1200)
            ->assertJsonPath('totals.out', 800)
            ->assertJsonCount(5, 'movements.data')
            ->assertJsonPath('movements.data.0.type', 'supplier_purchase')
            ->assertJsonPath('movements.data.1.type', 'refund')
            ->assertJsonPath('movements.data.2.type', 'sale')
            ->assertJsonPath('movements.data.3.type', 'sale')
            ->assertJsonPath('movements.data.4.type', 'expense');

        $this->assertSame($sale->id, $response->json('movements.data.2.reference_id'));
        $this->assertSame($refundedSale->id, $response->json('movements.data.1.reference_id'));
        $this->getJson('/api/cash-movements?direction=in')
            ->assertOk()
            ->assertJsonCount(2, 'movements.data');
    }

    public function test_best_selling_products_are_ranked_by_sold_quantity_and_exclude_cancelled_sales(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'administrador'])->save();
        $category = Category::create([
            'name' => 'Produtos',
            'description' => null,
            'status' => 'activo',
        ]);
        $popular = Product::create([
            'category_id' => $category->id,
            'name' => 'Produto popular',
            'price' => 100,
        ]);
        $other = Product::create([
            'category_id' => $category->id,
            'name' => 'Outro produto',
            'price' => 200,
        ]);
        $sale = Sale::create([
            'created_by' => $admin->id,
            'customer_name' => 'Cliente',
            'total' => 500,
            'status' => 'completed',
            'payment_method' => 'dinheiro',
            'payment_status' => 'paid',
            'currency' => 'AOA',
            'paid_at' => '2026-11-13 10:00:00',
        ]);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $popular->id,
            'quantity' => 3,
            'unit_price' => 100,
            'subtotal' => 300,
        ]);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $other->id,
            'quantity' => 1,
            'unit_price' => 200,
            'subtotal' => 200,
        ]);
        $cancelledSale = Sale::create([
            'created_by' => $admin->id,
            'customer_name' => 'Cliente',
            'total' => 1000,
            'status' => 'cancelled',
            'payment_method' => 'dinheiro',
            'payment_status' => 'refunded',
            'currency' => 'AOA',
            'paid_at' => '2026-11-13 11:00:00',
        ]);
        SaleItem::create([
            'sale_id' => $cancelledSale->id,
            'product_id' => $other->id,
            'quantity' => 10,
            'unit_price' => 100,
            'subtotal' => 1000,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/reports/best-selling-products?from=2026-11-13&to=2026-11-13')
            ->assertOk()
            ->assertJsonPath('data.0.product_id', $popular->id)
            ->assertJsonPath('data.0.quantity_sold', 3)
            ->assertJsonPath('data.1.product_id', $other->id)
            ->assertJsonPath('data.1.quantity_sold', 1)
            ->assertJsonCount(2, 'data');
    }

    public function test_financial_operations_endpoints_are_restricted_to_administrators(): void
    {
        $client = User::factory()->create();
        $this->getJson('/api/expenses')->assertUnauthorized();
        $this->getJson('/api/cash-movements')->assertUnauthorized();
        $this->getJson('/api/reports/best-selling-products')->assertUnauthorized();

        $this->actingAs($client, 'sanctum')
            ->getJson('/api/expenses')
            ->assertForbidden();
        $this->getJson('/api/cash-movements')->assertForbidden();
        $this->getJson('/api/reports/best-selling-products')->assertForbidden();
    }

}
