<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Factura FAT-{{ str_pad((string) $sale->id, 6, '0', STR_PAD_LEFT) }}</title>
    <style>
        body {
            color: #1f2937;
            font-family: Arial, sans-serif;
            margin: 2rem auto;
            max-width: 800px;
            padding: 0 1.5rem;
        }

        header, .details, .total {
            margin-bottom: 2rem;
        }

        header, .details {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
        }

        h1 {
            margin-bottom: .25rem;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        th, td {
            border-bottom: 1px solid #d1d5db;
            padding: .75rem;
            text-align: left;
        }

        th:last-child, td:last-child {
            text-align: right;
        }

        .total {
            font-size: 1.25rem;
            font-weight: bold;
            text-align: right;
        }

        .print-button {
            background: #1f2937;
            border: 0;
            color: white;
            cursor: pointer;
            padding: .75rem 1rem;
        }

        @media print {
            body {
                margin: 0;
                max-width: none;
            }

            .print-button {
                display: none;
            }
        }
    </style>
</head>
<body>
    <header>
        <div>
            <h1>Factura</h1>
            <div>{{ config('app.name') }}</div>
        </div>
        <div>
            <strong>N.º FAT-{{ str_pad((string) $sale->id, 6, '0', STR_PAD_LEFT) }}</strong><br>
            {{ $sale->created_at->format('d/m/Y H:i') }}
        </div>
    </header>

    <section class="details">
        <div>
            <strong>Cliente</strong><br>
            {{ $sale->customer_name ?? $sale->user?->name ?? 'Cliente não associado' }}<br>
            NIF: {{ $sale->customer_nif ?? '' }}<br>
            @if ($sale->customer_email)
                {{ $sale->customer_email }}<br>
            @endif
            @if ($sale->customer_phone)
                {{ $sale->customer_phone }}<br>
            @endif
            @if ($sale->customer_address)
                {{ $sale->customer_address }}
            @endif
        </div>
        <div>
            <strong>Venda</strong><br>
            #{{ $sale->id }}<br>
            Vendedor: {{ $sale->seller_name ?? $sale->seller?->name ?? 'Não identificado' }}<br>
            Pagamento: {{ ['dinheiro' => 'Dinheiro', 'cartao' => 'Cartão', 'transferencia' => 'Transferência'][$sale->payment_method] ?? 'Não registado' }}
        </div>
    </section>

    @if ($sale->status === 'cancelled')
        <p><strong>Venda cancelada.</strong> Estado do reembolso: {{ $sale->payment_status === 'refunded' ? 'reembolsado' : 'pendente' }}.</p>
    @endif

    <table>
        <thead>
            <tr>
                <th>Artigo</th>
                <th>Categoria</th>
                <th>Quantidade</th>
                <th>Preço unitário</th>
                <th>Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($sale->items as $item)
                <tr>
                    <td>{{ $item->product->name }}</td>
                    <td>{{ $item->product->category?->name ?? 'Sem categoria' }}</td>
                    <td>{{ $item->quantity }}</td>
                    <td>{{ number_format((float) $item->unit_price, 2, ',', '.') }} AOA</td>
                    <td>{{ number_format((float) $item->subtotal, 2, ',', '.') }} AOA</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="total">Total: {{ number_format((float) $sale->total, 2, ',', '.') }} AOA</p>

    <button class="print-button" type="button" onclick="window.print()">Imprimir factura</button>
</body>
</html>
