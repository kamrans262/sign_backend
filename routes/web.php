<?php

use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('admin.login'));

// M8: form submissions use the Laravel web CSRF middleware; API clients
// sign through the separate, throttled capability route.
Route::post('/sign/{token}/submit',
    [\App\Http\Controllers\Api\PublicSignatureController::class,'submit'])
    ->middleware('throttle:6,1')->name('signing.public.submit.browser');
Route::get('/sign/{token}/receipt',
    [\App\Http\Controllers\Api\PublicSignatureController::class,'receipt'])
    ->middleware('throttle:30,1')->name('signing.public.receipt.browser');
Route::get('/sign/{token}/signed-pdf',
    [\App\Http\Controllers\Api\PublicSignatureController::class,'signedPdf'])
    ->middleware('throttle:12,1')->name('signing.public.signedPdf.browser');

// M8: separate signature-request capability. The legacy approved-quote
// viewing URL below never grants permission to sign.
Route::get('/sign/{token}',
    [\App\Http\Controllers\Api\PublicSignatureController::class, 'browser'])
    ->middleware('throttle:30,1')->name('signing.public.browser');

// Customer quote view: time-limited, tamper-evident signed URLs only.
Route::get('/quote-view/{quote}', [\App\Http\Controllers\Api\ServiceQuoteController::class, 'publicShow'])
    ->middleware('signed')->whereNumber('quote')->name('quotes.public');

// Route-backed assets: shared-hosting public_html does not have to duplicate
// files from Laravel's public/ directory. Only the two known files are served.
Route::get('/admin/assets/{file}', function (string $file) {
    $mime = match ($file) {
        'admin.css' => 'text/css; charset=UTF-8',
        'logo.png' => 'image/png',
        default => abort(404),
    };

    return response()->file(public_path('admin-assets/'.$file), [
        'Content-Type' => $mime,
        'Cache-Control' => 'no-store',
        'X-Content-Type-Options' => 'nosniff',
    ]);
})->whereIn('file', ['admin.css', 'logo.png'])->name('admin.asset');

// M10: customer signing page with separate addendum-only capability token.
Route::get('/addendum-sign/{token}',
    [\App\Http\Controllers\Api\PublicAddendumController::class,'open'])
    ->middleware('throttle:30,1')->name('addenda.public.browser');
Route::get('/addendum-sign/{token}/pdf',
    [\App\Http\Controllers\Api\PublicAddendumController::class,'approvedPdf'])
    ->middleware('throttle:15,1')->name('addenda.public.pdf');
Route::post('/addendum-sign/{token}',
    [\App\Http\Controllers\Api\PublicAddendumController::class,'submit'])
    ->middleware('throttle:6,1')->name('addenda.public.submit');
Route::get('/addendum-sign/{token}/receipt',
    [\App\Http\Controllers\Api\PublicAddendumController::class,'receipt'])
    ->middleware('throttle:30,1')->name('addenda.public.receipt');
Route::get('/addendum-sign/{token}/signed-pdf',
    [\App\Http\Controllers\Api\PublicAddendumController::class,'signedPdf'])
    ->middleware('throttle:12,1')->name('addenda.public.signedPdf');

Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('login.submit');

    Route::middleware(['auth', 'platform.admin'])->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::resource('users', UserController::class)->except(['show', 'destroy']);
        Route::resource('companies', CompanyController::class)->except(['show', 'destroy']);
        Route::resource('jobs', \App\Http\Controllers\Admin\ServiceJobController::class)->except(['show']);
        Route::get('/quotes', [\App\Http\Controllers\Admin\ServiceQuoteController::class, 'index'])->name('quotes.index');
        Route::get('/quotes/create', [\App\Http\Controllers\Admin\ServiceQuoteController::class, 'create'])->name('quotes.create');
        Route::post('/quotes', [\App\Http\Controllers\Admin\ServiceQuoteController::class, 'store'])->name('quotes.store');
        Route::get('/quotes/{quote}/edit', [\App\Http\Controllers\Admin\ServiceQuoteController::class, 'edit'])->whereNumber('quote')->name('quotes.edit');
        Route::put('/quotes/{quote}', [\App\Http\Controllers\Admin\ServiceQuoteController::class, 'update'])->whereNumber('quote')->name('quotes.update');
        Route::post('/quotes/{quote}/approve', [\App\Http\Controllers\Admin\ServiceQuoteController::class, 'approve'])->whereNumber('quote')->name('quotes.approve');
        Route::get('/quotes/{quote}/approved-pdf', [\App\Http\Controllers\Admin\ServiceQuoteController::class, 'approvedPdf'])->whereNumber('quote')->name('quotes.approvedPdf');
        Route::delete('/quotes/{quote}', [\App\Http\Controllers\Admin\ServiceQuoteController::class, 'archive'])->whereNumber('quote')->name('quotes.archive');
        Route::post('/quotes/{quote}/restore', [\App\Http\Controllers\Admin\ServiceQuoteController::class, 'restore'])->whereNumber('quote')->name('quotes.restore');
        Route::post('/jobs/{job}/restore', [\App\Http\Controllers\Admin\ServiceJobController::class, 'restore'])
            ->whereNumber('job')->name('jobs.restore');
        Route::resource('clients', \App\Http\Controllers\Admin\ClientController::class)->except(['show']);
        Route::post('/clients/{client}/restore', [\App\Http\Controllers\Admin\ClientController::class, 'restore'])
            ->whereNumber('client')->name('clients.restore');
        Route::post('/companies/{company}/members', [CompanyController::class, 'addMember'])->name('companies.members.store');
        Route::get('/companies/{company}/members/{user}/edit', [CompanyController::class, 'editMember'])->name('companies.members.edit');
        Route::patch('/companies/{company}/members/{user}', [CompanyController::class, 'updateMember'])->name('companies.members.update');
        Route::delete('/companies/{company}/members/{user}', [CompanyController::class, 'removeMember'])->name('companies.members.destroy');
        Route::post('/companies/{company}/invitations', [\App\Http\Controllers\Admin\CompanyResourcesController::class, 'invite'])->name('companies.invitations.store');
        Route::delete('/companies/{company}/invitations/{invitation}', [\App\Http\Controllers\Admin\CompanyResourcesController::class, 'revoke'])->name('companies.invitations.destroy');
        Route::post('/companies/{company}/files/{kind}', [\App\Http\Controllers\Admin\CompanyResourcesController::class, 'upload'])->whereIn('kind', ['logo', 'terms'])->name('companies.files.upload');
        Route::get('/companies/{company}/files/{kind}', [\App\Http\Controllers\Admin\CompanyResourcesController::class, 'download'])->whereIn('kind', ['logo', 'terms'])->name('companies.files.download');
        Route::delete('/companies/{company}/files/{kind}', [\App\Http\Controllers\Admin\CompanyResourcesController::class, 'remove'])->whereIn('kind', ['logo', 'terms'])->name('companies.files.destroy');
        Route::get('/addenda', [\App\Http\Controllers\Admin\AddendumController::class,'index'])->name('addenda.index');
        Route::get('/addenda/create', [\App\Http\Controllers\Admin\AddendumController::class,'create'])->name('addenda.create');
        Route::post('/addenda', [\App\Http\Controllers\Admin\AddendumController::class,'store'])->name('addenda.store');
        Route::get('/addenda/{addendum}', [\App\Http\Controllers\Admin\AddendumController::class,'show'])->whereNumber('addendum')->name('addenda.show');
        Route::get('/addenda/{addendum}/edit', [\App\Http\Controllers\Admin\AddendumController::class,'edit'])->whereNumber('addendum')->name('addenda.edit');
        Route::put('/addenda/{addendum}', [\App\Http\Controllers\Admin\AddendumController::class,'update'])->whereNumber('addendum')->name('addenda.update');
        Route::post('/addenda/{addendum}/approve', [\App\Http\Controllers\Admin\AddendumController::class,'approve'])->whereNumber('addendum')->name('addenda.approve');
        Route::get('/addenda/{addendum}/pdf', [\App\Http\Controllers\Admin\AddendumController::class,'pdf'])->whereNumber('addendum')->name('addenda.pdf');
        Route::post('/addenda/{addendum}/signature-requests',
            [\App\Http\Controllers\Admin\AddendumController::class,'requestSignature'])
            ->whereNumber('addendum')->middleware('throttle:10,1')->name('addenda.signature');
        Route::post('/addenda/{addendum}/signature-requests/{signature}/revoke',
            [\App\Http\Controllers\Admin\AddendumController::class,'revoke'])
            ->whereNumber('addendum')->whereNumber('signature')
            ->middleware('throttle:10,1')->name('addenda.revoke');
        Route::get('/documents', [\App\Http\Controllers\Admin\DocumentController::class,'index'])->name('documents.index');
        Route::post('/documents', [\App\Http\Controllers\Admin\DocumentController::class,'store'])->name('documents.store');
        Route::get('/documents/{document}', [\App\Http\Controllers\Admin\DocumentController::class,'show'])->name('documents.show');
        Route::get('/documents/{document}/pdf', [\App\Http\Controllers\Admin\DocumentController::class,'download'])->name('documents.pdf');
        Route::post('/documents/{document}/versions', [\App\Http\Controllers\Admin\DocumentController::class,'addVersion'])->name('documents.versions.store');
        Route::post('/documents/{document}/attachments', [\App\Http\Controllers\Admin\DocumentController::class,'addAttachment'])->name('documents.attachments.store');
        Route::get('/documents/{document}/attachments/{attachment}/download',
            [\App\Http\Controllers\Admin\DocumentController::class,'downloadAttachment'])
            ->whereNumber('attachment')->name('documents.attachments.download');
        Route::get('/signatures', [\App\Http\Controllers\Admin\SignatureRequestController::class,'index'])->name('signatures.index');
        Route::get('/signatures/{signature}', [\App\Http\Controllers\Admin\SignatureRequestController::class,'show'])
            ->whereNumber('signature')->name('signatures.show');
        Route::post('/signatures/{signature}/revoke',
            [\App\Http\Controllers\Admin\SignatureRequestController::class,'revoke'])
            ->whereNumber('signature')->middleware('throttle:10,1')->name('signatures.revoke');
        Route::get('/signatures/{signature}/signed-pdf',
            [\App\Http\Controllers\Admin\SignatureRequestController::class,'signedPdf'])
            ->whereNumber('signature')->name('signatures.signedPdf');
        Route::post('/signatures/{signature}/resend',
            [\App\Http\Controllers\Admin\SignatureRequestController::class,'resend'])
            ->whereNumber('signature')->middleware('throttle:5,1')->name('signatures.resend');
        Route::post('/signatures/{signature}/resend-signed',
            [\App\Http\Controllers\Admin\SignatureRequestController::class,'resendSigned'])
            ->whereNumber('signature')->middleware('throttle:5,1')->name('signatures.resendSigned');
        Route::get('/audit', AuditController::class)->name('audit');
        Route::get('/mobile-auth', \App\Http\Controllers\Admin\MobileAuthController::class)->name('mobile-auth');
    });
});
