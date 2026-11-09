<?php

declare(strict_types=1);

use App\Enums\Shop\CustomerType;
use App\Enums\Shop\OrderStatus;
use App\Enums\Shop\PaymentStatus;
use App\Enums\Shop\SaleMode;
use App\Enums\Shop\StockMovementType;
use App\Enums\Status;
use App\Http\Controllers\Shop\CheckoutController;
use App\Models\Carrier;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Shop\Basket;
use App\Services\Shop\LedgerService;
use App\Services\Shop\OrderService;
use App\Services\Shop\ShopSettings;
use App\Services\Shop\StockService;
use App\Support\Money;
use Bsinternet\Iyzico\IyzicoClient;
use Bsinternet\Iyzico\IyzicoGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->withoutVite();
    Notification::fake();
    Carrier::query()->update(['status' => Status::Passive->value]);
    ShopSettings::save([
        'iyzico_enabled' => '1', 'iyzico_api_key' => 'api-key', 'iyzico_secret_key' => 'secret-key',
        'iyzico_sandbox' => '1', 'iyzico_max_installment' => '6', 'iyzico_fee_payer' => 'customer',
    ]);

    $this->admin = User::factory()->admin()->create();
    $this->admin->assignRole('Super Admin');
    $this->member = User::query()->where('email', 'user@user.com')->firstOrFail();
    $this->member->account->update(['type' => CustomerType::Dealer, 'dealer_group_id' => null, 'discount_rate' => 0]);

    $this->try = Currency::query()->where('code', 'TRY')->first();
    $this->product = Product::create([
        'sku' => 'IY-1', 'name' => 'iyzico Ürünü', 'slug' => 'iyzico-urunu', 'price' => 100,
        'currency_id' => $this->try->id, 'tax_rate' => 20, 'sale_mode' => SaleMode::Cart, 'status' => Status::Active,
    ]);
    app(StockService::class)->change($this->product, Warehouse::query()->where('code', 'DEPO')->first(), 20, StockMovementType::In);

    $this->place = function (int $qty = 1): Order {
        $this->actingAs($this->member);
        $cart = Basket::cart();
        $cart->add($this->product->fresh(), $qty);

        return app(OrderService::class)->placeFromCart($cart, [
            'email' => 'bayi@example.com', 'phone' => '05550000000',
            'billing' => ['type' => 'corporate', 'name' => 'Ayşe Bayi', 'address' => 'Adres', 'city' => 'Bursa', 'district' => 'Nilüfer'],
            'same_address' => true, 'payment_method' => 'iyzico',
        ], $this->member->fresh(), $this->try);
    };

    $this->open = function (Order $order): string {
        Http::fake(['sandbox-api.iyzipay.com/payment/iyzipos/checkoutform/initialize/auth/ecom' => Http::response([
            'status' => 'success', 'token' => 'TKN-1', 'paymentPageUrl' => 'https://sandbox-cpp.iyzipay.com?token=TKN-1',
        ])]);

        $this->get((string) $order->paymentStart?->redirectUrl)->assertRedirect('https://sandbox-cpp.iyzipay.com?token=TKN-1');

        return $order->payments()->sole()->merchant_oid;
    };
});

it('signs requests with IYZWSv2 and formats prices', function () {
    Http::fake(['*' => Http::response(['status' => 'success'])]);

    (new IyzicoClient('api-key', 'secret-key', true))->binCheck('454671');

    Http::assertSent(function ($request) {
        $header = $request->header('Authorization')[0] ?? '';
        $random = $request->header('x-iyzi-rnd')[0] ?? '';
        $decoded = base64_decode(substr($header, strlen('IYZWSv2 ')));
        $signature = hash_hmac('sha256', $random.'/payment/bin/check'.$request->body(), 'secret-key');

        return str_starts_with($header, 'IYZWSv2 ') && $decoded === "apiKey:api-key&randomKey:{$random}&signature:{$signature}";
    });

    expect(IyzicoClient::price(120))->toBe('120.0')
        ->and(IyzicoClient::price('99.5'))->toBe('99.5')
        ->and(IyzicoClient::price('10.25'))->toBe('10.25');
});

it('redirects to the iyzico checkout form and completes the order after verifying the result', function () {
    expect(collect(CheckoutController::paymentMethods($this->member->fresh(), 120, $this->try))->pluck('value')->all())->toContain('iyzico');

    $order = ($this->place)();
    $oid = ($this->open)($order);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'initialize')
        && $request['price'] === '120.0' && $request['basketId'] === $oid && $request['enabledInstallments'] === [1, 2, 3, 6]);

    Http::fake(['sandbox-api.iyzipay.com/payment/iyzipos/checkoutform/auth/ecom/detail' => Http::response([
        'status' => 'success', 'paymentStatus' => 'SUCCESS', 'basketId' => $oid, 'price' => 120, 'paidPrice' => 126.5,
        'installment' => 3, 'paymentId' => 'P1', 'itemTransactions' => [['paymentTransactionId' => 'T1']],
    ])]);

    // Yanlış token dikkate alınmaz.
    $this->post(route('shop.payment.return', ['gateway' => 'iyzico', 'oid' => $oid]), ['token' => 'BASKA'])->assertRedirect();
    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Pending);

    $this->post(route('shop.payment.return', ['gateway' => 'iyzico', 'oid' => $oid]), ['token' => 'TKN-1'])->assertRedirect();

    $payment = $order->payments()->sole();
    expect($payment->status)->toBe(PaymentStatus::Paid)
        ->and($payment->installment)->toBe(3)
        ->and($payment->fee_amount)->toEqualDecimal('6.5')
        ->and($payment->payload['iyzico']['transaction_id'])->toBe('T1')
        ->and($order->fresh()->status)->toBe(OrderStatus::Processing);
});

it('fails the order when the iyzico result does not match the payment', function () {
    $order = ($this->place)(2);
    $oid = ($this->open)($order);

    Http::fake(['sandbox-api.iyzipay.com/*' => Http::response([
        'status' => 'success', 'paymentStatus' => 'SUCCESS', 'basketId' => $oid, 'price' => 1, 'paidPrice' => 1,
    ])]);

    $this->post(route('shop.payment.return', ['gateway' => 'iyzico', 'oid' => $oid]), ['token' => 'TKN-1'])->assertRedirect();

    expect($order->fresh()->status)->toBe(OrderStatus::Failed)
        ->and($this->product->fresh()->stock)->toBe(20);
});

it('maps iyzico installment prices and refunds by transaction id', function () {
    Http::fake([
        'sandbox-api.iyzipay.com/payment/iyzipos/installment' => Http::response(['status' => 'success', 'installmentDetails' => [[
            'bankName' => 'Garanti', 'cardFamilyName' => 'Bonus', 'cardType' => 'CREDIT_CARD',
            'installmentPrices' => [
                ['installmentNumber' => 1, 'totalPrice' => 1000],
                ['installmentNumber' => 3, 'totalPrice' => 1045],
                ['installmentNumber' => 9, 'totalPrice' => 1150],
            ],
        ]]]),
        'sandbox-api.iyzipay.com/payment/iyzipos/checkoutform/initialize/auth/ecom' => Http::response(['status' => 'success', 'token' => 'TKN-1', 'paymentPageUrl' => 'https://x.test']),
    ]);

    $result = (new IyzicoGateway)->installments(Money::round(1000), 'TRY', '454671');
    expect($result['card']['family'])->toBe('Bonus')
        ->and(collect($result['rows'][0]['options'])->pluck('count')->all())->toBe([1, 3])
        ->and($result['rows'][0]['options'][1]['total'])->toEqualDecimal('1045.0');

    $order = ($this->place)();
    $payment = $order->payments()->sole();
    $this->get((string) $order->paymentStart?->redirectUrl)->assertRedirect();
    // Sonuç sorgusundaki sepet numarası ödemenin referansı olmalı.
    Http::fake(['sandbox-api.iyzipay.com/payment/iyzipos/checkoutform/auth/ecom/detail' => Http::response([
        'status' => 'success', 'paymentStatus' => 'SUCCESS', 'basketId' => $payment->merchant_oid, 'price' => 120, 'paidPrice' => 120, 'installment' => 1,
        'itemTransactions' => [['paymentTransactionId' => 'T9']],
    ]), 'sandbox-api.iyzipay.com/payment/refund' => Http::response(['status' => 'success'])]);
    $this->post(route('shop.payment.return', ['gateway' => 'iyzico', 'oid' => $payment->merchant_oid]), ['token' => 'TKN-1']);

    $this->flushSession();
    $this->actingAs($this->admin)->post(route('panel.shop.payments.refund', $payment->id), ['amount' => '20'])->assertSessionHasNoErrors();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and(app(LedgerService::class)->balance($this->member->account_id))->toEqualDecimal('20.0');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/payment/refund') && $request['paymentTransactionId'] === 'T9' && $request['price'] === '20.0');
});
