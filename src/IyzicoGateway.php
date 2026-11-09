<?php

declare(strict_types=1);

namespace Bsinternet\Iyzico;

use App\Exceptions\Shop\ShopException;
use App\Models\Payment;
use App\Services\Shop\ShopSettings;
use App\Support\Money;
use App\Support\Payments\HostedCardGateway;
use App\Support\Payments\PaymentContext;
use App\Support\Payments\ReconcilesPayments;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * iyzico Checkout Form ile kart ödemesi: müşteri iyzico'nun güvenli ödeme
 * sayfasına yönlendirilir (3D Secure), dönüşte (`/odeme/donus/iyzico`, token)
 * sonuç iyzico'dan sorgulanıp doğrulanır.
 */
final class IyzicoGateway extends HostedCardGateway implements ReconcilesPayments
{
    public const KEY = 'iyzico';

    public function __construct(
        private readonly ?IyzicoClient $client = null,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('Kredi / Banka Kartı (iyzico)');
    }

    public function description(PaymentContext $context): ?string
    {
        $max = ShopSettings::int('iyzico_max_installment');

        return $max > 1
            ? __('Ödemenizi iyzico güvenli ödeme sayfasında 3D Secure ile yapın, :max taksite kadar. Kart bilgileriniz saklanmaz.', ['max' => $max])
            : __('Ödemenizi iyzico güvenli ödeme sayfasında 3D Secure ile yapın. Kart bilgileriniz saklanmaz.');
    }

    protected function configured(): bool
    {
        return ShopSettings::bool('iyzico_enabled')
            && ShopSettings::get('iyzico_api_key') !== ''
            && ShopSettings::get('iyzico_secret_key') !== '';
    }

    public function renderPayment(Payment $payment, Request $request): Response
    {
        $buyer = $this->buyer($payment);
        $price = IyzicoClient::price($payment->amount);
        [$first, $last] = self::splitName($buyer['name']);
        $address = ['contactName' => mb_substr($buyer['name'], 0, 100), 'city' => $buyer['city'], 'country' => 'Turkey', 'address' => mb_substr($buyer['address'], 0, 250)];

        $result = $this->client()->initializeCheckout([
            'locale' => 'tr',
            'conversationId' => $payment->merchant_oid,
            'price' => $price,
            'paidPrice' => $price,
            'currency' => $payment->currency_code === 'TRY' ? 'TRY' : $payment->currency_code,
            'basketId' => $payment->merchant_oid,
            'paymentGroup' => 'PRODUCT',
            'callbackUrl' => route('shop.payment.return', ['gateway' => self::KEY, 'oid' => $payment->merchant_oid]),
            'enabledInstallments' => self::enabledInstallments(),
            'buyer' => [
                'id' => (string) ($payment->account_id ?? $payment->merchant_oid),
                'name' => $first,
                'surname' => $last,
                'gsmNumber' => $buyer['phone'],
                'email' => $buyer['email'],
                // iyzico zorunlu tutar; T.C. kimlik no istenmediğinde örnek değer.
                'identityNumber' => '11111111111',
                'registrationAddress' => $address['address'],
                'ip' => (string) $request->ip(),
                'city' => $address['city'],
                'country' => 'Turkey',
            ],
            'shippingAddress' => $address,
            'billingAddress' => $address,
            'basketItems' => [[
                'id' => $payment->merchant_oid,
                'name' => mb_substr($buyer['description'], 0, 100),
                'category1' => __('Genel'),
                'itemType' => 'PHYSICAL',
                'price' => $price,
            ]],
        ]);

        // Sayfa her açılışta yeni token alır; öncekiler de saklanır ki eski sekmede
        // tamamlanan ödeme (dönüş / web kancası / mutabakat) yine eşleşsin.
        $payload = $payment->payload ?? [];
        $tokens = array_values(array_unique([...(array) ($payload['iyzico_tokens'] ?? []), ...(isset($payload['iyzico_token']) ? [$payload['iyzico_token']] : []), $result['token']]));
        $payment->forceFill(['payload' => [...$payload, 'iyzico_token' => $result['token'], 'iyzico_tokens' => $tokens]])->save();

        return redirect()->away($result['paymentPageUrl']);
    }

    /**
     * iyzico web kancası (isteğe bağlı): token ile sonuç sorgulanır.
     */
    public function handleCallback(Request $request): Response
    {
        $token = (string) ($request->input('token') ?? '');
        $payment = $token !== '' ? $this->paymentByToken($token) : null;

        if ($payment !== null) {
            $this->settle($payment, $token);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Müşterinin dönüşü (POST token): sonuç iyzico'dan doğrulanır, sipariş sayfasına gidilir.
     */
    public function handleReturn(Request $request): Response
    {
        $payment = $this->paymentByReference($request->query('oid'));
        $token = (string) ($request->input('token') ?? '');

        if ($payment !== null && $token !== '' && in_array($token, self::tokensOf($payment), true)) {
            $this->settle($payment, $token);
        }

        return $this->finish($payment);
    }

    public function installments(BigDecimal $amount, string $currency, ?string $bin = null): array
    {
        $client = $this->client();
        $max = max(1, min(12, ShopSettings::int('iyzico_max_installment')));
        $customerPays = ShopSettings::get('iyzico_fee_payer') !== 'merchant';
        $json = $client->installments($amount, $bin);
        $card = null;
        $rows = [];

        foreach ((array) ($json['installmentDetails'] ?? []) as $detail) {
            $card ??= $bin !== null ? [
                'bank' => $detail['bankName'] ?? null,
                'family' => $detail['cardFamilyName'] ?? null,
                'type' => isset($detail['cardType']) ? ($detail['cardType'] === 'CREDIT_CARD' ? __('Kredi kartı') : __('Banka kartı')) : null,
            ] : null;

            $options = [];

            foreach ((array) ($detail['installmentPrices'] ?? []) as $price) {
                $count = (int) ($price['installmentNumber'] ?? 1);

                if ($count > $max) {
                    continue;
                }

                $total = Money::round($customerPays && isset($price['totalPrice']) ? (string) $price['totalPrice'] : $amount);
                $options[] = ['count' => $count, 'total' => $total, 'monthly' => Money::div($total, max(1, $count))];
            }

            if ($options !== []) {
                $rows[] = ['family' => (string) ($detail['cardFamilyName'] ?? $detail['bankName'] ?? __('Kart')), 'options' => $options];
            }
        }

        return ['card' => $card, 'rows' => $rows];
    }

    public function refund(Payment $payment, BigDecimal $amount): void
    {
        $transactionId = $payment->payload['iyzico']['transaction_id'] ?? null;

        if ($payment->provider !== self::KEY || ! $transactionId) {
            throw new ShopException(__('Bu ödemenin iyzico işlem numarası bulunamadı.'));
        }

        $this->client()->refund((string) $transactionId, $amount, $payment->currency_code, (string) (request()->ip() ?? '127.0.0.1'));
    }

    /**
     * Zaman aşımından önce mutabakat: ödemenin verilmiş tüm token'ları iyzico'dan
     * sorgulanır; başarılı olan varsa ödeme tamamlanır (dönüşü / web kancası
     * kaybolmuş ödeme "başarısız" sayılıp para kaybolmasın).
     */
    public function reconcile(Payment $payment): void
    {
        foreach (array_reverse(self::tokensOf($payment)) as $token) {
            $result = $this->client()->retrieveCheckout($token);

            if (self::succeeded($result, $payment)) {
                $this->settle($payment, $token, $result);

                return;
            }
        }
    }

    /**
     * Sonucu iyzico'dan sorgular ve ödemeyi sonuçlandırır (tutar, para birimi ve sepet doğrulanır).
     *
     * @param  array<string, mixed>|null  $result  önceden sorgulanmış sonuç
     */
    private function settle(Payment $payment, string $token, ?array $result = null): void
    {
        $result ??= $this->client()->retrieveCheckout($token);
        $success = self::succeeded($result, $payment);

        $payload = ['iyzico' => [
            'payment_id' => $result['paymentId'] ?? null,
            'transaction_id' => $result['itemTransactions'][0]['paymentTransactionId'] ?? null,
            'paid_price' => $result['paidPrice'] ?? null,
            'installment' => $result['installment'] ?? null,
            'card_family' => $result['cardFamily'] ?? null,
            'last_four' => $result['lastFourDigits'] ?? null,
        ]];

        if ($success) {
            $this->complete($payment, isset($result['paidPrice']) ? (string) $result['paidPrice'] : $payment->amount, (int) ($result['installment'] ?? 1), $payload);
        } else {
            $this->fail($payment, (string) ($result['errorMessage'] ?? __('Ödeme tamamlanamadı')), $payload);
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private static function succeeded(array $result, Payment $payment): bool
    {
        return ($result['status'] ?? null) === 'success'
            && ($result['paymentStatus'] ?? null) === 'SUCCESS'
            && ($result['basketId'] ?? null) === $payment->merchant_oid
            && (! isset($result['currency']) || strtoupper((string) $result['currency']) === strtoupper((string) $payment->currency_code))
            && Money::of((string) ($result['price'] ?? 0), $payment->currency_code)->isEqualTo(Money::of($payment->amount, $payment->currency_code));
    }

    /**
     * Ödemeye verilmiş tüm token'lar (eskiden yalnız sonuncusu saklanırdı).
     *
     * @return list<string>
     */
    private static function tokensOf(Payment $payment): array
    {
        $payload = $payment->payload ?? [];

        return array_values(array_unique(array_filter([
            ...(array) ($payload['iyzico_tokens'] ?? []),
            (string) ($payload['iyzico_token'] ?? ''),
        ])));
    }

    private function paymentByToken(string $token): ?Payment
    {
        return Payment::query()->withoutGlobalScopes()
            ->where('provider', self::KEY)
            ->where(fn ($q) => $q->where('payload->iyzico_token', $token)->orWhereJsonContains('payload->iyzico_tokens', $token))
            ->first();
    }

    /**
     * @return array<int, int>
     */
    private static function enabledInstallments(): array
    {
        $max = ShopSettings::int('iyzico_max_installment');

        return $max <= 1 ? [1] : array_values(array_filter([1, 2, 3, 6, 9, 12], fn (int $n) => $n <= $max));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $last = count($parts) > 1 ? (string) array_pop($parts) : '-';

        return [implode(' ', $parts) ?: __('Müşteri'), $last];
    }

    private function client(): IyzicoClient
    {
        return $this->client ?? IyzicoClient::fromSettings();
    }
}
