import { Head, router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Register = {
    id: number;
    name: string;
};

type CashSession = {
    id: number;
    cash_register_id: number;
    expected_amount: number;
    register: Register;
};

type Product = {
    id: number;
    name: string;
    sku: string | null;
    barcode: string | null;
    price: number | string;
    stock: number;
};

type CartItem = {
    product: Product;
    quantity: number;
};

type Props = {
    registers: Register[];
    cashSession: CashSession | null;
    summary: { expected_amount: number } | null;
    userRole: string;
};

function csrfToken(): string {
    const cookie = document.cookie
        .split('; ')
        .find((value) => value.startsWith('XSRF-TOKEN='));

    return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
}

async function apiRequest<T>(
    url: string,
    method: 'GET' | 'POST',
    payload?: unknown,
): Promise<T> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': csrfToken(),
        },
        ...(method === 'GET' ? {} : { body: JSON.stringify(payload ?? {}) }),
    });
    const body = await response.json();

    if (!response.ok) {
        const validationMessages = body.errors
            ? Object.values(body.errors).flat().join(' ')
            : body.message;
        throw new Error(
            validationMessages ?? 'Não foi possível concluir a operação.',
        );
    }

    return body as T;
}

export default function CashierPage({
    registers,
    cashSession,
    summary,
    userRole,
}: Props) {
    const [registerId, setRegisterId] = useState(
        registers[0]?.id.toString() ?? '',
    );
    const [openingAmount, setOpeningAmount] = useState('0');
    const [countedAmount, setCountedAmount] = useState('');
    const [barcode, setBarcode] = useState('');
    const barcodeInputRef = useRef<HTMLInputElement>(null);
    const [sellerName, setSellerName] = useState('');
    const [customerName, setCustomerName] = useState('');
    const [customerPhone, setCustomerPhone] = useState('');
    const [customerEmail, setCustomerEmail] = useState('');
    const [paymentMethod, setPaymentMethod] = useState('dinheiro');
    const [cart, setCart] = useState<CartItem[]>([]);
    const [notice, setNotice] = useState('');
    const [invoiceId, setInvoiceId] = useState<number | null>(null);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);

    async function handleOpenSession(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setBusy(true);
        setError('');
        try {
            await apiRequest('/api/cash-sessions', 'POST', {
                cash_register_id: Number(registerId),
                opening_amount: Number(openingAmount),
            });
            router.reload();
        } catch (requestError) {
            setError(
                requestError instanceof Error
                    ? requestError.message
                    : 'Erro ao abrir o caixa.',
            );
        } finally {
            setBusy(false);
        }
    }

    async function handleLookup(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const identifier = barcode.trim();
        if (!identifier) return;

        setBusy(true);
        setError('');
        try {
            const product = await apiRequest<Product>(
                `/api/products/lookup/${encodeURIComponent(identifier)}`,
                'GET',
            );
            const existing = cart.find(
                (item) => item.product.id === product.id,
            );
            if (existing && existing.quantity >= product.stock) {
                setError(
                    'A quantidade no carrinho atingiu o stock disponível.',
                );
                return;
            }
            if (!existing && product.stock < 1) {
                setError('Este produto não tem stock disponível.');
                return;
            }
            setCart((items) =>
                existing
                    ? items.map((item) =>
                          item.product.id === product.id
                              ? { ...item, quantity: item.quantity + 1 }
                              : item,
                      )
                    : [...items, { product, quantity: 1 }],
            );
            setBarcode('');
        } catch (requestError) {
            setError(
                requestError instanceof Error
                    ? requestError.message
                    : 'Produto não encontrado.',
            );
        } finally {
            setBusy(false);
            barcodeInputRef.current?.focus();
        }
    }

    function updateQuantity(productId: number, quantity: number) {
        setCart((items) =>
            items
                .map((item) =>
                    item.product.id === productId
                        ? {
                              ...item,
                              quantity: Math.min(item.product.stock, quantity),
                          }
                        : item,
                )
                .filter((item) => item.quantity > 0),
        );
    }

    async function handleSale(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!cart.length) {
            setError('Adicione pelo menos um produto à venda.');
            return;
        }

        setBusy(true);
        setError('');
        try {
            const payload: Record<string, unknown> = {
                payment_method: paymentMethod,
                items: cart.map(({ product, quantity }) => ({
                    product_id: product.id,
                    quantity,
                })),
            };
            if (sellerName.trim()) payload.seller_name = sellerName.trim();
            if (customerName.trim())
                payload.customer_name = customerName.trim();
            if (customerPhone.trim())
                payload.customer_phone = customerPhone.trim();
            if (customerEmail.trim())
                payload.customer_email = customerEmail.trim();

            const result = await apiRequest<{
                message: string;
                sale: { id: number };
            }>('/api/sales', 'POST', payload);
            setNotice(`${result.message} Venda #${result.sale.id}.`);
            setInvoiceId(result.sale.id);
            setCart([]);
            setCustomerName('');
            setCustomerPhone('');
            setCustomerEmail('');
            router.reload();
        } catch (requestError) {
            setError(
                requestError instanceof Error
                    ? requestError.message
                    : 'Erro ao registar a venda.',
            );
        } finally {
            setBusy(false);
        }
    }

    async function handleCloseSession(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!cashSession) return;

        setBusy(true);
        setError('');
        try {
            await apiRequest(
                `/api/cash-sessions/${cashSession.id}/close`,
                'POST',
                {
                    counted_amount: Number(countedAmount),
                },
            );
            router.reload();
        } catch (requestError) {
            setError(
                requestError instanceof Error
                    ? requestError.message
                    : 'Erro ao fechar o caixa.',
            );
        } finally {
            setBusy(false);
        }
    }

    const total = cart.reduce(
        (sum, item) => sum + Number(item.product.price) * item.quantity,
        0,
    );

    return (
        <>
            <Head title="Caixa" />
            <main className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-8">
                <header>
                    <h1 className="text-2xl font-semibold">
                        Operações de caixa
                    </h1>
                    <p className="text-muted-foreground">
                        Abra o turno, registe vendas por código de barras e
                        confira o fecho.
                    </p>
                </header>

                {notice && (
                    <p
                        role="status"
                        className="rounded-md bg-green-50 p-3 text-green-800"
                    >
                        {notice}{' '}
                        {invoiceId !== null && (
                            <a
                                className="font-medium underline"
                                href={`/sales/${invoiceId}/invoice`}
                                target="_blank"
                                rel="noreferrer"
                            >
                                Abrir factura
                            </a>
                        )}
                    </p>
                )}
                {error && (
                    <p
                        role="alert"
                        className="rounded-md bg-red-50 p-3 text-red-800"
                    >
                        {error}
                    </p>
                )}

                {!cashSession ? (
                    <section className="rounded-xl border p-5">
                        <h2 className="mb-4 text-lg font-medium">
                            Abrir sessão de caixa
                        </h2>
                        {registers.length === 0 ? (
                            <p>
                                Não existem postos de caixa activos. Peça a um
                                administrador ou gerente para criar um.
                            </p>
                        ) : (
                            <form
                                onSubmit={handleOpenSession}
                                className="grid gap-4 sm:grid-cols-3"
                            >
                                <label className="grid gap-2 text-sm">
                                    Posto
                                    <select
                                        className="h-10 rounded-md border bg-background px-3"
                                        value={registerId}
                                        onChange={(event) =>
                                            setRegisterId(event.target.value)
                                        }
                                    >
                                        {registers.map((register) => (
                                            <option
                                                key={register.id}
                                                value={register.id}
                                            >
                                                {register.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="grid gap-2 text-sm">
                                    Fundo inicial
                                    <Input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        value={openingAmount}
                                        onChange={(event) =>
                                            setOpeningAmount(event.target.value)
                                        }
                                        required
                                    />
                                </label>
                                <Button
                                    type="submit"
                                    disabled={busy}
                                    className="self-end"
                                >
                                    Abrir caixa
                                </Button>
                            </form>
                        )}
                    </section>
                ) : (
                    <>
                        <section className="flex flex-wrap items-center justify-between gap-3 rounded-xl border p-5">
                            <div>
                                <h2 className="text-lg font-medium">
                                    {cashSession.register.name}
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    Saldo esperado:{' '}
                                    {Number(
                                        summary?.expected_amount ?? 0,
                                    ).toLocaleString('pt-PT', {
                                        minimumFractionDigits: 2,
                                    })}{' '}
                                    AOA
                                </p>
                            </div>
                            <form
                                onSubmit={handleCloseSession}
                                className="flex flex-wrap items-end gap-2"
                            >
                                <label className="grid gap-1 text-sm">
                                    Valor contado
                                    <Input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        value={countedAmount}
                                        onChange={(event) =>
                                            setCountedAmount(event.target.value)
                                        }
                                        required
                                    />
                                </label>
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={busy}
                                >
                                    Fechar sessão
                                </Button>
                            </form>
                        </section>

                        <section className="rounded-xl border p-5">
                            <h2 className="mb-4 text-lg font-medium">
                                Nova venda
                            </h2>
                            <form
                                onSubmit={handleLookup}
                                className="mb-5 flex gap-2"
                            >
                                <Input
                                    ref={barcodeInputRef}
                                    autoFocus
                                    value={barcode}
                                    onChange={(event) =>
                                        setBarcode(event.target.value)
                                    }
                                    placeholder="Ler ou introduzir SKU / código de barras"
                                    aria-label="Código de barras ou SKU"
                                />
                                <Button type="submit" disabled={busy}>
                                    Adicionar
                                </Button>
                            </form>
                            <form onSubmit={handleSale} className="grid gap-5">
                                {cart.length === 0 ? (
                                    <p className="rounded-md bg-muted p-4 text-sm text-muted-foreground">
                                        O carrinho está vazio. Leia o código de
                                        barras de um produto.
                                    </p>
                                ) : (
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-left text-sm">
                                            <thead>
                                                <tr className="border-b">
                                                    <th className="py-2">
                                                        Produto
                                                    </th>
                                                    <th>Preço</th>
                                                    <th>Quantidade</th>
                                                    <th>Subtotal</th>
                                                    <th />
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {cart.map(
                                                    ({ product, quantity }) => (
                                                        <tr
                                                            key={product.id}
                                                            className="border-b"
                                                        >
                                                            <td className="py-3">
                                                                {product.name}
                                                            </td>
                                                            <td>
                                                                {Number(
                                                                    product.price,
                                                                ).toLocaleString(
                                                                    'pt-PT',
                                                                    {
                                                                        minimumFractionDigits: 2,
                                                                    },
                                                                )}{' '}
                                                                AOA
                                                            </td>
                                                            <td>
                                                                <Input
                                                                    className="w-24"
                                                                    type="number"
                                                                    min="0"
                                                                    max={
                                                                        product.stock
                                                                    }
                                                                    value={
                                                                        quantity
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        updateQuantity(
                                                                            product.id,
                                                                            Number(
                                                                                event
                                                                                    .target
                                                                                    .value,
                                                                            ),
                                                                        )
                                                                    }
                                                                    aria-label={`Quantidade de ${product.name}`}
                                                                />
                                                            </td>
                                                            <td>
                                                                {(
                                                                    Number(
                                                                        product.price,
                                                                    ) * quantity
                                                                ).toLocaleString(
                                                                    'pt-PT',
                                                                    {
                                                                        minimumFractionDigits: 2,
                                                                    },
                                                                )}{' '}
                                                                AOA
                                                            </td>
                                                            <td>
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    onClick={() =>
                                                                        updateQuantity(
                                                                            product.id,
                                                                            0,
                                                                        )
                                                                    }
                                                                >
                                                                    Remover
                                                                </Button>
                                                            </td>
                                                        </tr>
                                                    ),
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                                <div className="grid gap-4 sm:grid-cols-2">
                                    {userRole !== 'vendedor' && (
                                        <label className="grid gap-2 text-sm">
                                            Vendedor (nome completo)
                                            <Input
                                                value={sellerName}
                                                onChange={(event) =>
                                                    setSellerName(
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="Nome do vendedor"
                                                required={userRole === 'caixa'}
                                            />
                                        </label>
                                    )}
                                    <label className="grid gap-2 text-sm">
                                        Nome do cliente
                                        <Input
                                            value={customerName}
                                            onChange={(event) =>
                                                setCustomerName(
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="Nome completo"
                                            required={userRole === 'vendedor'}
                                        />
                                    </label>
                                    <label className="grid gap-2 text-sm">
                                        Telefone do cliente
                                        <Input
                                            type="tel"
                                            value={customerPhone}
                                            onChange={(event) =>
                                                setCustomerPhone(
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="Número de telefone"
                                            required={userRole === 'vendedor'}
                                        />
                                    </label>
                                    <label className="grid gap-2 text-sm">
                                        E-mail da conta (opcional)
                                        <Input
                                            type="email"
                                            value={customerEmail}
                                            onChange={(event) =>
                                                setCustomerEmail(
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="cliente@exemplo.com"
                                        />
                                    </label>
                                    <label className="grid gap-2 text-sm">
                                        Método de pagamento
                                        <select
                                            className="h-10 rounded-md border bg-background px-3"
                                            value={paymentMethod}
                                            onChange={(event) =>
                                                setPaymentMethod(
                                                    event.target.value,
                                                )
                                            }
                                        >
                                            <option value="dinheiro">
                                                Dinheiro
                                            </option>
                                            <option value="cartao">
                                                Cartão
                                            </option>
                                            <option value="transferencia">
                                                Transferência
                                            </option>
                                        </select>
                                    </label>
                                </div>
                                <div className="flex flex-wrap items-center justify-between gap-3 border-t pt-4">
                                    <p className="text-lg font-semibold">
                                        Total:{' '}
                                        {total.toLocaleString('pt-PT', {
                                            minimumFractionDigits: 2,
                                        })}{' '}
                                        AOA
                                    </p>
                                    <Button
                                        type="submit"
                                        disabled={busy || cart.length === 0}
                                    >
                                        Registar venda
                                    </Button>
                                </div>
                            </form>
                        </section>
                    </>
                )}
            </main>
        </>
    );
}
