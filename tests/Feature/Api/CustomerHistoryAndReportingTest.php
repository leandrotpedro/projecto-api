<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerHistoryAndReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_only_view_their_own_purchase_history(): void
    {
        [$product] = $this->createProductWithStock(5);
        $customer = User::factory()->create();
        $otherCustomer = User::factory()->create();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/sales', [
                'payment_method' => 'dinheiro',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertCreated();

        $otherSale = Sale::create([
            'user_id' => $otherCustomer->id,
            'customer_name' => $otherCustomer->name,
            'customer_email' => $otherCustomer->email,
            'created_by' => $otherCustomer->id,
            'total' => 1,
            'status' => 'completed',
            'payment_method' => 'dinheiro',
            'payment_status' => 'paid',
            'currency' => 'AOA',
            'paid_at' => now(),
        ]);

        $this->getJson('/api/me/purchases')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $customer->id)
            ->assertJsonMissing(['id' => $otherSale->id]);
    }

    public function test_admin_can_view_a_customers_history_and_client_cannot_access_anothers_history(): void
    {
        $customer = User::factory()->create();
        $client = User::factory()->create();

        $this->actingAs($client, 'sanctum')
            ->getJson('/api/users/'.$customer->id.'/purchases')
            ->assertForbidden();

        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'administrador'])->save();
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/users/'.$customer->id.'/purchases')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_customer_can_make_more_than_two_purchases(): void
    {
        [$product, $stock] = $this->createProductWithStock(3);
        $customer = User::factory()->create();

        $response = $this->actingAs($customer, 'sanctum');
        for ($purchaseNumber = 0; $purchaseNumber < 3; $purchaseNumber++) {
            $response->postJson('/api/sales', [
                'payment_method' => 'dinheiro',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])->assertCreated();
        }

        $this->assertDatabaseCount('sales', 3);
        $this->assertSame(0, $stock->fresh()->quantity);
        $this->getJson('/api/me/purchases')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_low_stock_alert_endpoint_returns_stock_and_out_of_stock_alerts(): void
    {
        [$lowProduct] = $this->createProductWithStock(2, 'Low product');
        [$emptyProduct, $emptyStock] = $this->createProductWithStock(1, 'Empty product');
        $emptyStock->update(['quantity' => 0]);

        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'administrador'])->save();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/stock/alerts?threshold=2')
            ->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonPath('alerts.0.product_id', $emptyProduct->id)
            ->assertJsonPath('alerts.0.alert', 'out_of_stock')
            ->assertJsonPath('alerts.1.product_id', $lowProduct->id)
            ->assertJsonPath('alerts.1.alert', 'low_stock');
    }

    public function test_financial_report_summarizes_daily_weekly_and_monthly_activity(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'administrador'])->save();
        $supplier = Supplier::create(['name' => 'Fornecedor']);
        $date = '2026-11-10';
        $paidAt = '2026-11-10 10:00:00';

        Sale::create([
            'created_by' => $admin->id,
            'customer_name' => 'Cliente',
            'total' => 1000,
            'status' => 'completed',
            'payment_method' => 'dinheiro',
            'payment_status' => 'paid',
            'currency' => 'AOA',
            'paid_at' => $paidAt,
            'created_at' => $paidAt,
            'updated_at' => $paidAt,
        ]);
        Sale::create([
            'created_by' => $admin->id,
            'customer_name' => 'Cliente',
            'total' => 100,
            'status' => 'cancelled',
            'payment_method' => 'dinheiro',
            'payment_status' => 'refunded',
            'currency' => 'AOA',
            'paid_at' => $paidAt,
            'cancelled_at' => $paidAt,
            'created_at' => $paidAt,
            'updated_at' => $paidAt,
        ]);
        Purchase::create([
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'purchase_date' => $date,
            'received_at' => $paidAt,
            'total' => 300,
            'payment_status' => 'paid',
            'payment_method' => 'dinheiro',
            'paid_at' => $paidAt,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/reports/financial?period=daily&date='.$date)
            ->assertOk()
            ->assertJsonPath('sales_received', 1100)
            ->assertJsonPath('refunds_confirmed', 100)
            ->assertJsonPath('net_sales', 1000)
            ->assertJsonPath('stock_purchases_recorded', 300)
            ->assertJsonPath('estimated_operational_balance', 700)
            ->assertJsonPath('from', $date)
            ->assertJsonPath('to', $date);

        $this->getJson('/api/reports/financial?period=weekly&date='.$date)
            ->assertOk()
            ->assertJsonPath('period', 'weekly')
            ->assertJsonPath('from', '2026-11-09')
            ->assertJsonPath('to', '2026-11-15');

        $this->getJson('/api/reports/financial?period=monthly&date='.$date)
            ->assertOk()
            ->assertJsonPath('period', 'monthly')
            ->assertJsonPath('from', '2026-11-01')
            ->assertJsonPath('to', '2026-11-30');
    }

    /**
     * @return array{Product, Stock}
     */
    private function createProductWithStock(int $quantity, string $name = 'Product'): array
    {
        $category = Category::firstOrCreate(
            ['name' => 'Categoria'],
            ['description' => null, 'status' => 'activo'],
        );
        $product = Product::create([
            'category_id' => $category->id,
            'name' => $name.' '.uniqid(),
            'price' => 100,
        ]);
        $stock = Stock::create([
            'product_id' => $product->id,
            'quantity' => $quantity,
        ]);

        return [$product, $stock];
    }
}
