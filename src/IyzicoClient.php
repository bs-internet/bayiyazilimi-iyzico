<?php

declare(strict_types=1);

namespace Bsinternet\Iyzico;

use App\Exceptions\Shop\ShopException;
use App\Services\Shop\ShopSettings;
use App\Support\Money;
use Brick\Math\BigNumber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * iyzico API istemcisi (IYZWSv2 kimlik doğrulama): Checkout Form başlatma /
 * sonuç sorgulama, taksit, BIN ve iade.
 *
 * İmza: hex(HMAC-SHA256(randomKey + uriPath + gövde(JSON), secretKey));
 * Authorization: "IYZWSv2 " + base64("apiKey:{k}&randomKey:{r}&signature:{s}").
 */
final class IyzicoClient
{
    public const LIVE_URL = 'https://api.iyzipay.com';

    public const SANDBOX_URL = 'https://sandbox-api.iyzipay.com';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $secretKey,
        private readonly bool $sandbox,
    ) {}

    public static function fromSettings(): self
    {
        return new self(
            (string) ShopSettings::get('iyzico_api_key'),
            (string) ShopSettings::get('iyzico_secret_key'),
            ShopSettings::bool('iyzico_sandbox'),
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{token: string, paymentPageUrl: string}
     */
    public function initializeCheckout(array $body): array
    {
        $json = $this->post('/payment/iyzipos/checkoutform/initialize/auth/ecom', $body);

        if (($json['status'] ?? null) !== 'success' || empty($json['paymentPageUrl'])) {
            throw new ShopException(__('iyzico ödeme sayfası açılamadı: :reason.', ['reason' => $json['errorMessage'] ?? __('bağlantı hatası')]));
        }

        return ['token' => (string) $json['token'], 'paymentPageUrl' => (string) $json['paymentPageUrl']];
    }

    /**
     * Checkout Form sonucu (token ile).
     *
     * @return array<string, mixed>
     */
    public function retrieveCheckout(string $token): array
    {
        return $this->post('/payment/iyzipos/checkoutform/auth/ecom/detail', [
            'locale' => 'tr',
            'conversationId' => (string) Str::uuid(),
            'token' => $token,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function installments(BigNumber|string $price, ?string $bin): array
    {
        return $this->post('/payment/iyzipos/installment', array_filter([
            'locale' => 'tr',
            'conversationId' => (string) Str::uuid(),
            'binNumber' => $bin !== null ? substr($bin, 0, 6) : null,
            'price' => self::price($price),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function binCheck(string $bin): array
    {
        return $this->post('/payment/bin/check', [
            'locale' => 'tr',
            'conversationId' => (string) Str::uuid(),
            'binNumber' => substr($bin, 0, 6),
        ]);
    }

    public function refund(string $paymentTransactionId, BigNumber|string $amount, string $currency, string $ip): void
    {
        $json = $this->post('/payment/refund', [
            'locale' => 'tr',
            'conversationId' => (string) Str::uuid(),
            'paymentTransactionId' => $paymentTransactionId,
            'price' => self::price($amount),
            'currency' => $currency,
            'ip' => $ip,
        ]);

        if (($json['status'] ?? null) !== 'success') {
            throw new ShopException(__('iyzico iadesi yapılamadı: :reason.', ['reason' => $json['errorMessage'] ?? __('bağlantı hatası')]));
        }
    }

    /** iyzico tutar biçimi: nokta ayraçlı, gereksiz sıfırsız ("100.5"). */
    public static function price(BigNumber|int|string $amount): string
    {
        $formatted = rtrim(rtrim(Money::str($amount), '0'), '.');

        return str_contains($formatted, '.') ? $formatted : $formatted.'.0';
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        $json = (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $random = now()->getTimestampMs().Str::random(8);
        $signature = hash_hmac('sha256', $random.$path.$json, $this->secretKey);
        $authorization = base64_encode("apiKey:{$this->apiKey}&randomKey:{$random}&signature:{$signature}");

        $response = Http::withHeaders([
            'Authorization' => 'IYZWSv2 '.$authorization,
            'x-iyzi-rnd' => $random,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->timeout(20)->withBody($json, 'application/json')->post(($this->sandbox ? self::SANDBOX_URL : self::LIVE_URL).$path);

        $data = $response->json();

        return is_array($data) ? $data : ['status' => 'failure', 'errorMessage' => __('Geçersiz yanıt')];
    }
}
