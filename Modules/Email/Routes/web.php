<?php

use Illuminate\Support\Facades\Route;
use Modules\Email\Http\Controllers\EmailTemplateController;

Route::middleware(['auth', 'can:email.templates.view'])->prefix('setup/email-templates')->name('setup.email-templates.')->group(function (): void {
    Route::get('/', [EmailTemplateController::class, 'index'])->name('index');
    Route::get('/open', [EmailTemplateController::class, 'open'])->name('open');
    Route::get('/{branch}/{code}', [EmailTemplateController::class, 'edit'])->whereNumber('branch')->name('edit');
    Route::post('/{branch}/{code}', [EmailTemplateController::class, 'save'])->whereNumber('branch')->middleware('can:email.templates.edit')->name('save');
    Route::post('/{branch}/{code}/preview', [EmailTemplateController::class, 'preview'])->whereNumber('branch')->name('preview');
});
