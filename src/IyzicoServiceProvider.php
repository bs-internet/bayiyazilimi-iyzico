<?php

declare(strict_types=1);

namespace Bsinternet\Iyzico;

use App\Support\Payments\PaymentRegistry;
use App\Support\Registry\MenuRegistry;
use App\Support\Registry\PermissionRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * iyzico kart ödeme modülü. Çekirdeğe yalnız hook ve registry ile bağlanır.
 */
final class IyzicoServiceProvider extends ServiceProvider
{
    public const SOURCE = 'iyzico';

    /** Ayar anahtarları (çekirdeğin ayar deposunda, `shop` grubu). */
    public const DEFAULTS = [
        'iyzico_enabled' => '0',
        'iyzico_api_key' => '',
        'iyzico_secret_key' => '',
        'iyzico_sandbox' => '1',
        // 1: tek çekim; 2–12: en fazla taksit (iyzico 2, 3, 6, 9, 12 seçeneklerini sunar).
        'iyzico_max_installment' => '1',
        // Taksit farkını kim öder: customer / merchant. iyzico panelindeki ayarla aynı olmalı.
        'iyzico_fee_payer' => 'customer',
    ];

    public function register(): void
    {
        $this->app['config']->push('inertia.pages.paths', __DIR__.'/../resources/js/pages');

        hooks()->filter('shop.settings.defaults', fn (array $value) => [...$value, ...self::DEFAULTS]);

        // İçerik güvenlik politikası: ödeme formu gönderildikten sonra iyzico'nun
        // ödeme sayfasına yönlenilir (form-action yönlendirmeyi de denetler).
        hooks()->filter('security.csp', fn (array $value) => array_merge_recursive($value, [
            'form-action' => ['https://*.iyzipay.com'],
        ]));

        hooks()->action('shop.payment.methods.register', function (PaymentRegistry $payments): void {
            $payments->add(new IyzicoGateway);
        }, 21);

        hooks()->action('panel.routes.register', function (): void {
            Route::group([], __DIR__.'/../routes/panel.php');
        });

        hooks()->action('panel.menu.register', function (MenuRegistry $menu): void {
            $menu->addChild('panel', 'settings', 'panel.tools.definitions', [
                'key' => 'panel.iyzico.settings.edit',
                'title' => __('iyzico'),
                'href' => route('panel.iyzico.settings.edit'),
                'icon' => 'creditCard',
                'description' => __('iyzico kart ödemesi: API anahtarları, taksit, sandbox.'),
                'permission' => 'panel.iyzico.settings.edit',
                'order' => 58,
            ]);
        }, 20);

        hooks()->action('panel.permissions.register', function (PermissionRegistry $permissions): void {
            $permissions->addMany('panel', [
                ['name' => 'panel.iyzico.settings.edit', 'group' => __('Ödeme: iyzico'), 'label' => __('iyzico ayarlarını görüntüle')],
                ['name' => 'panel.iyzico.settings.update', 'group' => __('Ödeme: iyzico'), 'label' => __('iyzico ayarlarını güncelle')],
            ], source: self::SOURCE);
        });
    }

    public function boot(): void
    {
        // Modülün çeviri kataloğu (lang/tr.json; başka dil için lang/{locale}.json).
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
    }
}
