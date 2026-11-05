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

// M17: 256-bit bearer capabilities, hash-only storage, seven-day expiration.
// Never accept guessed numeric invoice IDs in unauthenticated routes.
Route::get('/invoice-view/{token}', [\App\Http\Controllers\Api\PublicInvoiceController::class,'show'])
    ->where('token','[a-f0-9]{64}')->middleware('throttle:30,1')->name('invoices.customer');
Route::get('/invoice-view/{token}/pdf', [\App\Http\Controllers\Api\PublicInvoiceController::class,'pdf'])
    ->where('token','[a-f0-9]{64}')->middleware('throttle:15,1')->name('invoices.customer.pdf');

// M18: independent public document and signing capabilities; never quote IDs.
Route::get('/general-document/{token}', [\App\Http\Controllers\Api\PublicGeneralDocumentController::class,'show'])
    ->where('token','[a-f0-9]{64}')->middleware('throttle:30,1')->name('general-document.public.show');
Route::get('/general-document/{token}/pdf', [\App\Http\Controllers\Api\PublicGeneralDocumentController::class,'pdf'])
    ->where('token','[a-f0-9]{64}')->middleware('throttle:15,1')->name('general-document.public.pdf');
Route::get('/general-sign/{token}', [\App\Http\Controllers\Api\PublicGeneralDocumentController::class,'signing'])
    ->where('token','[a-f0-9]{64}')->middleware('throttle:30,1')->name('general-document.sign.show');
Route::get('/general-sign/{token}/pdf', [\App\Http\Controllers\Api\PublicGeneralDocumentController::class,'signingPdf'])
    ->where('token','[a-f0-9]{64}')->middleware('throttle:15,1')->name('general-document.sign.pdf');
Route::post('/general-sign/{token}/submit', [\App\Http\Controllers\Api\PublicGeneralDocumentController::class,'submit'])
    ->where('token','[a-f0-9]{64}')->middleware('throttle:6,1')->name('general-document.sign.submit');
Route::get('/general-sign/{token}/receipt', [\App\Http\Controllers\Api\PublicGeneralDocumentController::class,'receipt'])
    ->where('token','[a-f0-9]{64}')->middleware('throttle:30,1')->name('general-document.sign.receipt');
Route::get('/general-sign/{token}/signed-pdf', [\App\Http\Controllers\Api\PublicGeneralDocumentController::class,'signedPdf'])
    ->where('token','[a-f0-9]{64}')->middleware('throttle:12,1')->name('general-document.sign.signedPdf');

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

// M13 customer work acceptance, independent of quote signing links.
Route::get('/accept-work/{token}', [\App\Http\Controllers\Api\PublicWorkAcceptanceController::class,'open'])
    ->middleware('throttle:30,1')->name('acceptance.public.open');
Route::get('/accept-work/{token}/approved-pdf', [\App\Http\Controllers\Api\PublicWorkAcceptanceController::class,'approvedPdf'])
    ->middleware('throttle:15,1')->name('acceptance.public.approvedPdf');
Route::post('/accept-work/{token}/submit', [\App\Http\Controllers\Api\PublicWorkAcceptanceController::class,'submit'])
    ->middleware('throttle:6,1')->name('acceptance.public.submit');
Route::get('/accept-work/{token}/receipt', [\App\Http\Controllers\Api\PublicWorkAcceptanceController::class,'receipt'])
    ->middleware('throttle:30,1')->name('acceptance.public.receipt');
Route::get('/accept-work/{token}/signed-pdf', [\App\Http\Controllers\Api\PublicWorkAcceptanceController::class,'signedPdf'])
    ->middleware('throttle:12,1')->name('acceptance.public.signedPdf');

Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('login.submit');

    Route::middleware(['auth', 'platform.admin'])->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::resource('users', UserController::class)->except(['show', 'destroy']);
        Route::resource('companies', CompanyController::class)->except(['show', 'destroy']);
        Route::resource('jobs', \App\Http\Controllers\Admin\ServiceJobController::class)->except(['show']);
        // M11 dedicated Photos & Notes Management.
        Route::get('/job-media', [\App\Http\Controllers\Admin\JobMediaController::class, 'index'])->name('job-media.index');
        Route::post('/job-media/photos', [\App\Http\Controllers\Admin\JobMediaController::class, 'uploadPhoto'])->name('job-media.photos.create');
        Route::post('/job-media/notes', [\App\Http\Controllers\Admin\JobMediaController::class, 'createNote'])->name('job-media.notes.create');
        Route::patch('/job-media/{kind}/{id}', [\App\Http\Controllers\Admin\JobMediaController::class, 'update'])->whereNumber('id')->name('job-media.update');
        Route::post('/job-media/{kind}/{id}/archive', [\App\Http\Controllers\Admin\JobMediaController::class, 'archive'])->whereNumber('id')->name('job-media.archive');
        Route::get('/job-media/{kind}/{id}/download', [\App\Http\Controllers\Admin\JobMediaController::class, 'download'])->whereNumber('id')->name('job-media.download');
        // M15: dedicated, platform-admin-only Time & Labour Management.
        Route::get('/time-labour', [\App\Http\Controllers\Admin\LabourController::class,'index'])->name('labour.index');
        Route::get('/time-labour/export', [\App\Http\Controllers\Admin\LabourController::class,'export'])->name('labour.export');
        Route::get('/time-labour/create', [\App\Http\Controllers\Admin\LabourController::class,'create'])->name('labour.create');
        Route::post('/time-labour', [\App\Http\Controllers\Admin\LabourController::class,'store'])->name('labour.store');
        Route::get('/time-labour/{entry}/edit', [\App\Http\Controllers\Admin\LabourController::class,'edit'])->whereNumber('entry')->name('labour.edit');
        Route::patch('/time-labour/{entry}', [\App\Http\Controllers\Admin\LabourController::class,'update'])->whereNumber('entry')->name('labour.update');
        Route::post('/time-labour/{entry}/approve', [\App\Http\Controllers\Admin\LabourController::class,'approve'])->whereNumber('entry')->name('labour.approve');
        Route::post('/time-labour/{entry}/void', [\App\Http\Controllers\Admin\LabourController::class,'void'])->whereNumber('entry')->name('labour.void');
        Route::post('/time-labour/{entry}/evidence', [\App\Http\Controllers\Admin\LabourController::class,'upload'])->whereNumber('entry')->name('labour.upload');
        Route::get('/time-labour/{entry}/evidence/{evidence}', [\App\Http\Controllers\Admin\LabourController::class,'evidenceFile'])->whereNumber('entry')->whereNumber('evidence')->name('labour.evidence.file');
        Route::delete('/time-labour/{entry}/evidence/{evidence}', [\App\Http\Controllers\Admin\LabourController::class,'removeEvidence'])->whereNumber('entry')->whereNumber('evidence')->name('labour.evidence.remove');
        Route::post('/time-labour/timer/{company}/{worker}/stop', [\App\Http\Controllers\Admin\LabourController::class,'stopTimer'])->whereNumber('company')->whereNumber('worker')->name('labour.timer.stop');
        Route::post('/time-labour/timer/{company}/{worker}/recover', [\App\Http\Controllers\Admin\LabourController::class,'recoverTimer'])->whereNumber('company')->whereNumber('worker')->name('labour.timer.recover');
        // M18 General AI Documents: reuse existing Admin document navigation and UI.
        Route::get('/general-documents', [\App\Http\Controllers\Admin\GeneralDocumentController::class,'index'])->name('general-documents.index');
        Route::get('/general-documents/create', [\App\Http\Controllers\Admin\GeneralDocumentController::class,'create'])->name('general-documents.create');
        Route::post('/general-documents', [\App\Http\Controllers\Admin\GeneralDocumentController::class,'store'])->name('general-documents.store');
        Route::get('/general-documents/{document}/edit', [\App\Http\Controllers\Admin\GeneralDocumentController::class,'edit'])->whereNumber('document')->name('general-documents.edit');
        Route::patch('/general-documents/{document}', [\App\Http\Controllers\Admin\GeneralDocumentController::class,'update'])->whereNumber('document')->name('general-documents.update');
        Route::post('/general-documents/{document}/{action}', [\App\Http\Controllers\Admin\GeneralDocumentController::class,'transition'])->whereNumber('document')->whereIn('action',['review','approve','archive'])->name('general-documents.transition');
        Route::post('/general-documents/{document}/attachments', [\App\Http\Controllers\Admin\GeneralDocumentController::class,'upload'])->whereNumber('document')->name('general-documents.upload');
        Route::get('/general-documents/{document}/attachments/{attachment}', [\App\Http\Controllers\Admin\GeneralDocumentController::class,'attachment'])->whereNumber('document')->whereNumber('attachment')->name('general-documents.attachment');
        Route::delete('/general-documents/{document}/attachments/{attachment}', [\App\Http\Controllers\Admin\GeneralDocumentController::class,'removeAttachment'])->whereNumber('document')->whereNumber('attachment')->name('general-documents.removeAttachment');
        Route::post('/general-documents/{document}/issue', [\App\Http\Controllers\Admin\GeneralDocumentController::class,'issue'])->whereNumber('document')->name('general-documents.issue');
        Route::get('/general-documents/{document}/issued-pdf', [\App\Http\Controllers\Admin\GeneralDocumentController::class,'issuedPdf'])->whereNumber('document')->name('general-documents.issuedPdf');
        Route::post('/general-documents/{document}/customer-link', [\App\Http\Controllers\Admin\GeneralDocumentController::class,'customerLink'])->whereNumber('document')->name('general-documents.customerLink');
        Route::post('/general-documents/{document}/signatures', [\App\Http\Controllers\Admin\GeneralDocumentController::class,'signature'])->whereNumber('document')->name('general-documents.signature');
        Route::get('/general-documents/{document}/signatures/{signature}/pdf', [\App\Http\Controllers\Admin\GeneralDocumentController::class,'signedPdf'])->whereNumber('document')->whereNumber('signature')->name('general-documents.signedPdf');
        Route::post('/general-documents/{document}/signature-requests/{signature}/revoke', [\App\Http\Controllers\Admin\GeneralDocumentController::class,'revokeSignature'])->whereNumber('document')->whereNumber('signature')->name('general-documents.revokeSignature');
        Route::post('/general-documents/{document}/customer-links/{link}/revoke', [\App\Http\Controllers\Admin\GeneralDocumentController::class,'revokeLink'])->whereNumber('document')->whereNumber('link')->name('general-documents.revokeLink');
        Route::post('/general-documents/{document}/send', [\App\Http\Controllers\Admin\GeneralDocumentController::class,'send'])->whereNumber('document')->name('general-documents.send');
        // M17: Admin invoice ledger and manual reconciliation.
        Route::get('/invoices', [\App\Http\Controllers\Admin\InvoiceController::class,'index'])->name('invoices.index');
        Route::get('/invoices/export', [\App\Http\Controllers\Admin\InvoiceController::class,'export'])->name('invoices.export');
        Route::get('/invoices/create', [\App\Http\Controllers\Admin\InvoiceController::class,'create'])->name('invoices.create');
        Route::post('/invoices', [\App\Http\Controllers\Admin\InvoiceController::class,'store'])->name('invoices.store');
        Route::get('/invoices/{invoice}/edit', [\App\Http\Controllers\Admin\InvoiceController::class,'edit'])->whereNumber('invoice')->name('invoices.edit');
        Route::patch('/invoices/{invoice}', [\App\Http\Controllers\Admin\InvoiceController::class,'update'])->whereNumber('invoice')->name('invoices.update');
        Route::post('/invoices/{invoice}/approve', [\App\Http\Controllers\Admin\InvoiceController::class,'approve'])->whereNumber('invoice')->name('invoices.approve');
        Route::post('/invoices/{invoice}/issue', [\App\Http\Controllers\Admin\InvoiceController::class,'issue'])->whereNumber('invoice')->name('invoices.issue');
        Route::get('/invoices/{invoice}/issued-pdf', [\App\Http\Controllers\Admin\InvoiceController::class,'issuedPdf'])->whereNumber('invoice')->name('invoices.issuedPdf');
        Route::post('/invoices/{invoice}/send', [\App\Http\Controllers\Admin\InvoiceController::class,'send'])->whereNumber('invoice')->name('invoices.send');
        Route::post('/invoices/{invoice}/payments', [\App\Http\Controllers\Admin\InvoiceController::class,'payment'])->whereNumber('invoice')->name('invoices.payment');
        Route::post('/invoices/{invoice}/payments/{payment}/void', [\App\Http\Controllers\Admin\InvoiceController::class,'voidPayment'])->whereNumber('invoice')->whereNumber('payment')->name('invoices.voidPayment');
        Route::post('/invoices/{invoice}/void', [\App\Http\Controllers\Admin\InvoiceController::class,'voidInvoice'])->whereNumber('invoice')->name('invoices.voidInvoice');
        Route::post('/invoices/{invoice}/attachments', [\App\Http\Controllers\Admin\InvoiceController::class,'upload'])->whereNumber('invoice')->name('invoices.upload');
        Route::get('/invoices/{invoice}/attachments/{attachment}', [\App\Http\Controllers\Admin\InvoiceController::class,'attachment'])->whereNumber('invoice')->whereNumber('attachment')->name('invoices.attachment');
        Route::delete('/invoices/{invoice}/attachments/{attachment}', [\App\Http\Controllers\Admin\InvoiceController::class,'removeAttachment'])->whereNumber('invoice')->whereNumber('attachment')->name('invoices.removeAttachment');
        // M16: manual deposit management under existing platform administrator guard.
        Route::get('/deposits', [\App\Http\Controllers\Admin\DepositController::class,'index'])->name('deposits.index');
        Route::get('/deposits/export', [\App\Http\Controllers\Admin\DepositController::class,'export'])->name('deposits.export');
        Route::get('/deposits/create', [\App\Http\Controllers\Admin\DepositController::class,'create'])->name('deposits.create');
        Route::post('/deposits', [\App\Http\Controllers\Admin\DepositController::class,'store'])->name('deposits.store');
        Route::post('/deposits/from-quote/{quote}', [\App\Http\Controllers\Admin\DepositController::class,'fromQuote'])->whereNumber('quote')->name('deposits.fromQuote');
        Route::get('/deposits/{deposit}/edit', [\App\Http\Controllers\Admin\DepositController::class,'edit'])->whereNumber('deposit')->name('deposits.edit');
        Route::patch('/deposits/{deposit}', [\App\Http\Controllers\Admin\DepositController::class,'update'])->whereNumber('deposit')->name('deposits.update');
        Route::post('/deposits/{deposit}/receive', [\App\Http\Controllers\Admin\DepositController::class,'receive'])->whereNumber('deposit')->name('deposits.receive');
        Route::post('/deposits/{deposit}/refund', [\App\Http\Controllers\Admin\DepositController::class,'refund'])->whereNumber('deposit')->name('deposits.refund');
        Route::delete('/deposits/{deposit}', [\App\Http\Controllers\Admin\DepositController::class,'destroy'])->whereNumber('deposit')->name('deposits.destroy');
        Route::post('/deposits/{deposit}/receipts', [\App\Http\Controllers\Admin\DepositController::class,'upload'])->whereNumber('deposit')->name('deposits.upload');
        Route::get('/deposits/{deposit}/receipts/{receipt}', [\App\Http\Controllers\Admin\DepositController::class,'receipt'])->whereNumber('deposit')->whereNumber('receipt')->name('deposits.receipt');
        Route::delete('/deposits/{deposit}/receipts/{receipt}', [\App\Http\Controllers\Admin\DepositController::class,'removeReceipt'])->whereNumber('deposit')->whereNumber('receipt')->name('deposits.removeReceipt');
        // M14: dedicated Expenses Management behind platform administrator authentication.
        Route::get('/expenses', [\App\Http\Controllers\Admin\ExpenseController::class,'index'])->name('expenses.index');
        Route::get('/expenses/create', [\App\Http\Controllers\Admin\ExpenseController::class,'create'])->name('expenses.create');
        Route::post('/expenses', [\App\Http\Controllers\Admin\ExpenseController::class,'store'])->name('expenses.store');
        Route::get('/expenses/{expense}/edit', [\App\Http\Controllers\Admin\ExpenseController::class,'edit'])->whereNumber('expense')->name('expenses.edit');
        Route::patch('/expenses/{expense}', [\App\Http\Controllers\Admin\ExpenseController::class,'update'])->whereNumber('expense')->name('expenses.update');
        Route::post('/expenses/{expense}/approve', [\App\Http\Controllers\Admin\ExpenseController::class,'approve'])->whereNumber('expense')->name('expenses.approve');
        Route::post('/expenses/{expense}/request-reimbursement', [\App\Http\Controllers\Admin\ExpenseController::class,'requestReimbursement'])->whereNumber('expense')->name('expenses.requestReimbursement');
        Route::post('/expenses/{expense}/reimburse', [\App\Http\Controllers\Admin\ExpenseController::class,'reimburse'])->whereNumber('expense')->name('expenses.reimburse');
        Route::post('/expenses/{expense}/receipts', [\App\Http\Controllers\Admin\ExpenseController::class,'upload'])->whereNumber('expense')->name('expenses.upload');
        Route::get('/expenses/{expense}/receipts/{receipt}', [\App\Http\Controllers\Admin\ExpenseController::class,'receipt'])->whereNumber('expense')->whereNumber('receipt')->name('expenses.receipt');
        Route::delete('/expenses/{expense}/receipts/{receipt}', [\App\Http\Controllers\Admin\ExpenseController::class,'removeReceipt'])->whereNumber('expense')->whereNumber('receipt')->name('expenses.removeReceipt');
        // M13 Work Acceptance Management; all routes inherit platform admin auth.
        Route::get('/work-acceptances', [\App\Http\Controllers\Admin\WorkAcceptanceController::class,'index'])->name('acceptances.index');
        Route::get('/work-acceptances/create', [\App\Http\Controllers\Admin\WorkAcceptanceController::class,'create'])->name('acceptances.create');
        Route::post('/work-acceptances', [\App\Http\Controllers\Admin\WorkAcceptanceController::class,'store'])->name('acceptances.store');
        Route::get('/work-acceptances/{acceptance}/edit', [\App\Http\Controllers\Admin\WorkAcceptanceController::class,'edit'])->whereNumber('acceptance')->name('acceptances.edit');
        Route::patch('/work-acceptances/{acceptance}', [\App\Http\Controllers\Admin\WorkAcceptanceController::class,'update'])->whereNumber('acceptance')->name('acceptances.update');
        Route::post('/work-acceptances/{acceptance}/approve', [\App\Http\Controllers\Admin\WorkAcceptanceController::class,'approve'])->whereNumber('acceptance')->name('acceptances.approve');
        Route::post('/work-acceptances/{acceptance}/send', [\App\Http\Controllers\Admin\WorkAcceptanceController::class,'send'])->whereNumber('acceptance')->middleware('throttle:6,1')->name('acceptances.send');
        Route::post('/work-acceptances/{acceptance}/requests/{signature}/revoke', [\App\Http\Controllers\Admin\WorkAcceptanceController::class,'revoke'])->whereNumber('acceptance')->whereNumber('signature')->name('acceptances.revoke');
        Route::get('/work-acceptances/{acceptance}/customer-photos/{photo}', [\App\Http\Controllers\Admin\WorkAcceptanceController::class,'customerPhoto'])->whereNumber('acceptance')->whereNumber('photo')->name('acceptances.customerPhoto');
        Route::get('/work-acceptances/{acceptance}/pdf/{kind}', [\App\Http\Controllers\Admin\WorkAcceptanceController::class,'pdf'])->whereNumber('acceptance')->whereIn('kind',['approved','signed'])->name('acceptances.pdf');
        // M12: Reports Management uses existing platform-admin guard.
        Route::get('/reports', [\App\Http\Controllers\Admin\SiteReportController::class,'index'])->name('reports.index');
        Route::get('/reports/create', [\App\Http\Controllers\Admin\SiteReportController::class,'create'])->name('reports.create');
        Route::post('/reports', [\App\Http\Controllers\Admin\SiteReportController::class,'store'])->name('reports.store');
        Route::get('/reports/{report}/edit', [\App\Http\Controllers\Admin\SiteReportController::class,'edit'])->whereNumber('report')->name('reports.edit');
        Route::patch('/reports/{report}', [\App\Http\Controllers\Admin\SiteReportController::class,'update'])->whereNumber('report')->name('reports.update');
        Route::post('/reports/{report}/approve', [\App\Http\Controllers\Admin\SiteReportController::class,'approve'])->whereNumber('report')->name('reports.approve');
        Route::post('/reports/{report}/send', [\App\Http\Controllers\Admin\SiteReportController::class,'send'])->whereNumber('report')->middleware('throttle:6,1')->name('reports.send');
        Route::post('/reports/{report}/archive', [\App\Http\Controllers\Admin\SiteReportController::class,'archive'])->whereNumber('report')->name('reports.archive');
        Route::get('/reports/{report}/pdf', [\App\Http\Controllers\Admin\SiteReportController::class,'pdf'])->whereNumber('report')->name('reports.pdf');
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
