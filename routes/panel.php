<?php

declare(strict_types=1);

use Bsinternet\Iyzico\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

/*
| Panel — iyzico ayarları (`panel.routes.register` içinde). Rota adı = yetki adı.
*/

Route::controller(SettingsController::class)->prefix('iyzico')->name('iyzico.settings.')->group(function () {
    Route::get('/', 'edit')->name('edit');
    Route::put('/', 'update')->name('update');
});
