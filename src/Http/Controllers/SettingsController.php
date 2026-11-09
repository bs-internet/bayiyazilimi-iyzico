<?php

declare(strict_types=1);

namespace Bsinternet\Iyzico\Http\Controllers;

use App\Events\Shop\CatalogChangedEvent;
use App\Http\Controllers\Controller;
use App\Services\Shop\ShopSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tanımlamalar › iyzico: API anahtarı / gizli anahtar (maskeli; boş
 * bırakılırsa değişmez), sandbox, taksit ve taksit farkını kim öder.
 */
class SettingsController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('panel/tools/definitions/iyzico/index', [
            'settings' => [
                'enabled' => ShopSettings::bool('iyzico_enabled'),
                'has_api_key' => ShopSettings::get('iyzico_api_key') !== '',
                'has_secret_key' => ShopSettings::get('iyzico_secret_key') !== '',
                'sandbox' => ShopSettings::bool('iyzico_sandbox'),
                'max_installment' => ShopSettings::int('iyzico_max_installment'),
                'fee_payer' => ShopSettings::get('iyzico_fee_payer'),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'api_key' => ['nullable', 'string', 'max:128'],
            'secret_key' => ['nullable', 'string', 'max:128'],
            'max_installment' => ['required', 'integer', Rule::in([1, 2, 3, 6, 9, 12])],
            'fee_payer' => ['required', Rule::in(['customer', 'merchant'])],
        ], [], ['api_key' => __('API anahtarı'), 'secret_key' => __('gizli anahtar'), 'max_installment' => __('en fazla taksit'), 'fee_payer' => __('taksit farkı')]);

        $values = [
            'iyzico_enabled' => $request->boolean('enabled'),
            'iyzico_sandbox' => $request->boolean('sandbox'),
            'iyzico_max_installment' => (string) (int) $data['max_installment'],
            'iyzico_fee_payer' => $data['fee_payer'],
        ];

        foreach (['api_key', 'secret_key'] as $secret) {
            if (! empty($data[$secret])) {
                $values["iyzico_{$secret}"] = trim((string) $data[$secret]);
            }
        }

        ShopSettings::save($values);

        CatalogChangedEvent::dispatch(null, 'settings', 'updated', __('iyzico ayarlarını güncelledi.'), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => __('iyzico ayarları kaydedildi.')]);
    }
}
