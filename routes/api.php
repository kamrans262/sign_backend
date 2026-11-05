<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['status' => 'ok', 'app' => 'Sign2Digital']));

// Mobile authentication only. Administrator sign-in remains unchanged.
Route::prefix('v1/auth')->middleware('throttle:mobile-auth')->group(function (): void {
    Route::post('/register', [\App\Http\Controllers\Api\MobileAuthController::class, 'register']);
    Route::post('/login', [\App\Http\Controllers\Api\MobileAuthController::class, 'login']);
    Route::post('/password/forgot', [\App\Http\Controllers\Api\MobileAuthController::class, 'forgotPassword']);
    Route::post('/otp/resend', [\App\Http\Controllers\Api\MobileAuthController::class, 'resend']);
    Route::post('/otp/verify', [\App\Http\Controllers\Api\MobileAuthController::class, 'verify']);
    Route::post('/password/reset', [\App\Http\Controllers\Api\MobileAuthController::class, 'resetPassword']);
});


// M8: customer capability access requires an unguessable, expiring request token.
// The existing quote-view URL remains read-only, not a signing credential.
Route::get('/public/signing/{token}',
    [\App\Http\Controllers\Api\PublicSignatureController::class, 'open'])
    ->middleware('throttle:30,1')->name('signing.public.api');
Route::post('/public/signing/{token}/submit',
    [\App\Http\Controllers\Api\PublicSignatureController::class,'submit'])
    ->middleware('throttle:6,1')->name('signing.public.submit.api');
Route::get('/public/signing/{token}/receipt',
    [\App\Http\Controllers\Api\PublicSignatureController::class,'receipt'])
    ->middleware('throttle:30,1')->name('signing.public.receipt.api');
Route::get('/public/signing/{token}/signed-pdf',
    [\App\Http\Controllers\Api\PublicSignatureController::class,'signedPdf'])
    ->middleware('throttle:12,1')->name('signing.public.signedPdf.api');

Route::get('/public/signing/{token}/pdf',
    [\App\Http\Controllers\Api\PublicSignatureController::class, 'approvedPdf'])
    ->middleware('throttle:15,1')->name('signing.public.pdf');

// M10: separate, expiring addendum capability. No quote signing token is accepted.
Route::get('/public/addenda/{token}',
    [\App\Http\Controllers\Api\PublicAddendumController::class,'open'])
    ->middleware('throttle:30,1')->name('addenda.public.api');
Route::get('/public/addenda/{token}/pdf',
    [\App\Http\Controllers\Api\PublicAddendumController::class,'approvedPdf'])
    ->middleware('throttle:15,1')->name('addenda.public.pdf.api');
Route::post('/public/addenda/{token}/submit',
    [\App\Http\Controllers\Api\PublicAddendumController::class,'submit'])
    ->middleware('throttle:6,1')->name('addenda.public.submit.api');
Route::get('/public/addenda/{token}/receipt',
    [\App\Http\Controllers\Api\PublicAddendumController::class,'receipt'])
    ->middleware('throttle:30,1')->name('addenda.public.receipt.api');
Route::get('/public/addenda/{token}/signed-pdf',
    [\App\Http\Controllers\Api\PublicAddendumController::class,'signedPdf'])
    ->middleware('throttle:12,1')->name('addenda.public.signedPdf.api');

// M13 public capability; throttle and token hash checks in the controller.
Route::get('/public/work-acceptance/{token}', [\App\Http\Controllers\Api\PublicWorkAcceptanceController::class,'open'])->middleware('throttle:30,1');
Route::get('/public/work-acceptance/{token}/approved-pdf', [\App\Http\Controllers\Api\PublicWorkAcceptanceController::class,'approvedPdf'])->middleware('throttle:15,1');
Route::post('/public/work-acceptance/{token}/submit', [\App\Http\Controllers\Api\PublicWorkAcceptanceController::class,'submit'])->middleware('throttle:6,1');
Route::get('/public/work-acceptance/{token}/receipt', [\App\Http\Controllers\Api\PublicWorkAcceptanceController::class,'receipt'])->middleware('throttle:30,1');
Route::get('/public/work-acceptance/{token}/signed-pdf', [\App\Http\Controllers\Api\PublicWorkAcceptanceController::class,'signedPdf'])->middleware('throttle:12,1');


Route::middleware(['auth:sanctum', 'active.user'])->group(function (): void {
    Route::post('/v1/auth/logout', [\App\Http\Controllers\Api\MobileAuthController::class, 'logout']);
    // Existing route retained for older clients.
    Route::get('/user', fn (Request $request) => $request->user());

    Route::prefix('v1')->group(function (): void {
        Route::get('/me', function (Request $request) {
            $user = $request->user();
            $companies = $user->companies()
                ->wherePivot('status', 'active')
                ->where('companies.status', 'active')
                ->orderBy('companies.name')
                ->get(['companies.id', 'companies.name', 'companies.status']);
            return response()->json([
                'data' => [
                    'user' => $user->only(['id', 'name', 'email', 'phone_number', 'country_iso_code', 'dialing_code', 'email_verified_at']),
                    'companies' => $companies->map(fn ($company) => [
                        'id' => $company->id,
                        'name' => $company->name,
                        'role' => $company->pivot->role,
                    ]),
                ],
            ]);
        });

        Route::patch('/me', function (Request $request) {
            $user = $request->user();
            $data = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', \Illuminate\Validation\Rule::in([$user->email])],
                'phone_number' => ['nullable', 'string', 'max:40'],
                'country_iso_code' => ['nullable', 'string', 'size:2'],
                'dialing_code' => ['nullable', 'string', 'max:8'],
            ]);
            // Email changes require re-verification and are not silently accepted.
            $user->fill(\Illuminate\Support\Arr::only($data, [
                'name', 'phone_number', 'country_iso_code', 'dialing_code',
            ]));
            $user->save();
            \App\Support\AuditLogger::record('mobile.account.profile_updated', $user, $user, request: $request);
            return response()->json(['data' => ['user' => $user->only([
                'id', 'name', 'email', 'phone_number', 'country_iso_code', 'dialing_code',
            ])]]);
        });



        // M3: all company actions require a real Sanctum-authenticated account.
        // Access to each company is additionally checked on the server using
        // an active membership, not only by matching the URL or a header.
        Route::get('/companies', [\App\Http\Controllers\Api\CompanyManagementController::class, 'index']);
        Route::post('/companies', [\App\Http\Controllers\Api\CompanyManagementController::class, 'store']);
        Route::get('/companies/invitations/mine', [\App\Http\Controllers\Api\CompanyManagementController::class, 'myInvitations']);
        Route::post('/companies/invitations/accept', [\App\Http\Controllers\Api\CompanyManagementController::class, 'acceptInvitation'])
            ->middleware('throttle:5,1');
        Route::get('/companies/{company}', [\App\Http\Controllers\Api\CompanyManagementController::class, 'show'])
            ->whereNumber('company');
        Route::patch('/companies/{company}', [\App\Http\Controllers\Api\CompanyManagementController::class, 'update'])
            ->whereNumber('company');
        Route::post('/companies/{company}/switch', [\App\Http\Controllers\Api\CompanyManagementController::class, 'switch'])
            ->whereNumber('company');
        Route::get('/companies/{company}/members', [\App\Http\Controllers\Api\CompanyManagementController::class, 'members'])
            ->whereNumber('company');
        Route::patch('/companies/{company}/members/{user}', [\App\Http\Controllers\Api\CompanyManagementController::class, 'updateMember'])
            ->whereNumber('company')->whereNumber('user');
        Route::delete('/companies/{company}/members/{user}', [\App\Http\Controllers\Api\CompanyManagementController::class, 'removeMember'])
            ->whereNumber('company')->whereNumber('user');
        Route::get('/companies/{company}/invitations', [\App\Http\Controllers\Api\CompanyManagementController::class, 'invitations'])
            ->whereNumber('company');
        Route::post('/companies/{company}/invitations', [\App\Http\Controllers\Api\CompanyManagementController::class, 'invite'])
            ->whereNumber('company')->middleware('throttle:10,1');
        Route::delete('/companies/{company}/invitations/{invitation}', [\App\Http\Controllers\Api\CompanyManagementController::class, 'revokeInvitation'])
            ->whereNumber('company')->whereNumber('invitation');
        Route::post('/companies/{company}/files/{kind}', [\App\Http\Controllers\Api\CompanyManagementController::class, 'uploadFile'])
            ->whereNumber('company')->whereIn('kind', ['logo', 'terms']);
        Route::get('/companies/{company}/files/{kind}', [\App\Http\Controllers\Api\CompanyManagementController::class, 'downloadFile'])
            ->whereNumber('company')->whereIn('kind', ['logo', 'terms']);
        Route::delete('/companies/{company}/files/{kind}', [\App\Http\Controllers\Api\CompanyManagementController::class, 'deleteFile'])
            ->whereNumber('company')->whereIn('kind', ['logo', 'terms']);

        // M4 Client Management: tenant header and DB permissions are enforced
        // independently for reads and writes, including bulk phone import.
        Route::middleware(['company.access', 'company.permission:clients.read'])->group(function (): void {
            Route::get('/clients', [\App\Http\Controllers\Api\ClientController::class, 'index']);
            Route::get('/clients/{client}', [\App\Http\Controllers\Api\ClientController::class, 'show'])
                ->whereNumber('client');
        });
        Route::middleware(['company.access', 'company.permission:clients.write'])->group(function (): void {
            Route::post('/clients', [\App\Http\Controllers\Api\ClientController::class, 'store']);
            Route::post('/clients/import', [\App\Http\Controllers\Api\ClientController::class, 'import'])
                ->middleware('throttle:10,1');
            Route::patch('/clients/{client}', [\App\Http\Controllers\Api\ClientController::class, 'update'])
                ->whereNumber('client');
            Route::delete('/clients/{client}', [\App\Http\Controllers\Api\ClientController::class, 'destroy'])
                ->whereNumber('client');
        });

        // M5: real company-isolated customer jobs (not Laravel queue jobs).
        Route::middleware(['company.access', 'company.permission:jobs.read'])->group(function (): void {
            Route::get('/jobs', [\App\Http\Controllers\Api\ServiceJobController::class, 'index']);
            Route::get('/jobs/{job}', [\App\Http\Controllers\Api\ServiceJobController::class, 'show'])->whereNumber('job');
        });
        Route::middleware(['company.access', 'company.permission:jobs.write'])->group(function (): void {
            Route::post('/jobs', [\App\Http\Controllers\Api\ServiceJobController::class, 'store']);
            Route::patch('/jobs/{job}', [\App\Http\Controllers\Api\ServiceJobController::class, 'update'])->whereNumber('job');
            Route::delete('/jobs/{job}', [\App\Http\Controllers\Api\ServiceJobController::class, 'destroy'])->whereNumber('job');
            Route::post('/jobs/{job}/restore', [\App\Http\Controllers\Api\ServiceJobController::class, 'restore'])->whereNumber('job');
        });

        // M11: private, tenant-scoped job photographs and notes.
        Route::middleware(['company.access', 'company.permission:jobs.read'])->group(function (): void {
            Route::get('/jobs/{job}/photos', [\App\Http\Controllers\Api\JobMediaController::class, 'photos'])->whereNumber('job');
            Route::get('/jobs/{job}/photos/{photo}/file', [\App\Http\Controllers\Api\JobMediaController::class, 'photoFile'])->whereNumber('job')->whereNumber('photo');
            Route::get('/jobs/{job}/notes', [\App\Http\Controllers\Api\JobMediaController::class, 'notes'])->whereNumber('job');
            Route::get('/jobs/{job}/notes/{note}', [\App\Http\Controllers\Api\JobMediaController::class, 'showNote'])->whereNumber('job')->whereNumber('note');
            Route::get('/jobs/{job}/notes/{note}/audio', [\App\Http\Controllers\Api\JobMediaController::class, 'noteAudio'])->whereNumber('job')->whereNumber('note');
        });
        Route::middleware(['company.access', 'company.permission:jobs.write'])->group(function (): void {
            Route::post('/jobs/{job}/photos', [\App\Http\Controllers\Api\JobMediaController::class, 'uploadPhoto'])->whereNumber('job')->middleware('throttle:20,1');
            Route::patch('/jobs/{job}/photos/{photo}', [\App\Http\Controllers\Api\JobMediaController::class, 'updatePhoto'])->whereNumber('job')->whereNumber('photo');
            Route::delete('/jobs/{job}/photos/{photo}', [\App\Http\Controllers\Api\JobMediaController::class, 'deletePhoto'])->whereNumber('job')->whereNumber('photo');
            Route::post('/jobs/{job}/notes', [\App\Http\Controllers\Api\JobMediaController::class, 'createNote'])->whereNumber('job')->middleware('throttle:20,1');
            Route::patch('/jobs/{job}/notes/{note}', [\App\Http\Controllers\Api\JobMediaController::class, 'updateNote'])->whereNumber('job')->whereNumber('note');
            Route::delete('/jobs/{job}/notes/{note}', [\App\Http\Controllers\Api\JobMediaController::class, 'deleteNote'])->whereNumber('job')->whereNumber('note');
        });

        // M11: private note -> AI suggestion -> explicit review -> immutable M9 PDF.
        Route::middleware(['company.access', 'company.permission:jobs.read'])->group(function (): void {
            Route::get('/jobs/{job}/notes/{note}/documents', [\App\Http\Controllers\Api\JobNoteDocumentController::class, 'index'])->whereNumber('job')->whereNumber('note');
        });
        Route::middleware(['company.access', 'company.permission:jobs.write', 'company.permission:documents.write'])->group(function (): void {
            Route::post('/jobs/{job}/notes/{note}/transcribe', [\App\Http\Controllers\Api\JobNoteDocumentController::class, 'transcribe'])
                ->whereNumber('job')->whereNumber('note')->middleware('throttle:6,1');
            Route::post('/jobs/{job}/notes/{note}/documents/prepare', [\App\Http\Controllers\Api\JobNoteDocumentController::class, 'prepare'])
                ->whereNumber('job')->whereNumber('note')->middleware('throttle:6,1');
            Route::patch('/jobs/{job}/notes/{note}/documents/{draft}', [\App\Http\Controllers\Api\JobNoteDocumentController::class, 'update'])
                ->whereNumber('job')->whereNumber('note')->whereNumber('draft');
            Route::post('/jobs/{job}/notes/{note}/documents/{draft}/approve', [\App\Http\Controllers\Api\JobNoteDocumentController::class, 'approve'])
                ->whereNumber('job')->whereNumber('note')->whereNumber('draft')->middleware('throttle:10,1');
        });

        // M6: tenant-scoped quotes; AI is always proxied by Laravel, never by client keys.
        Route::middleware(['company.access','company.permission:quotes.read'])->group(function (): void {
            Route::get('/quotes', [\App\Http\Controllers\Api\ServiceQuoteController::class,'index']);
            Route::get('/quotes/{quote}', [\App\Http\Controllers\Api\ServiceQuoteController::class,'show'])->whereNumber('quote');
            Route::get('/quotes/{quote}/revisions', [\App\Http\Controllers\Api\ServiceQuoteController::class,'revisions'])->whereNumber('quote');
            Route::get('/quotes/{quote}/approved-pdf', [\App\Http\Controllers\Api\ServiceQuoteController::class,'approvedPdf'])->whereNumber('quote');
        });
        Route::middleware(['company.access','company.permission:quotes.write'])->group(function (): void {
            Route::post('/quotes', [\App\Http\Controllers\Api\ServiceQuoteController::class,'store']);
            Route::patch('/quotes/{quote}', [\App\Http\Controllers\Api\ServiceQuoteController::class,'update'])->whereNumber('quote');
            Route::post('/quotes/{quote}/approve', [\App\Http\Controllers\Api\ServiceQuoteController::class,'approve'])->whereNumber('quote');
            Route::post('/quotes/{quote}/share', [\App\Http\Controllers\Api\ServiceQuoteController::class,'share'])->whereNumber('quote')
                ->middleware('throttle:10,1');
            Route::post('/quotes/{quote}/share-email-pdf', [\App\Http\Controllers\Api\ServiceQuoteController::class,'shareEmailPdf'])
                ->whereNumber('quote')->middleware('throttle:10,1');
            Route::delete('/quotes/{quote}', [\App\Http\Controllers\Api\ServiceQuoteController::class,'archive'])->whereNumber('quote');
            Route::post('/quotes/prepare/{method}', [\App\Http\Controllers\Api\QuoteAiController::class,'prepare'])
                ->whereIn('method',['text','audio','image'])->middleware('throttle:10,1');
        });

        // M15: authenticated, tenant-bound time, labour, timers and cost summaries.
        Route::middleware(['company.access','company.permission:jobs.read'])->group(function (): void {
            Route::get('/labour/entries', [\App\Http\Controllers\Api\LabourController::class,'index']);
            Route::get('/labour/entries/{entry}', [\App\Http\Controllers\Api\LabourController::class,'show'])->whereNumber('entry');
            Route::get('/labour/entries/{entry}/evidence/{evidence}', [\App\Http\Controllers\Api\LabourController::class,'evidenceFile'])->whereNumber('entry')->whereNumber('evidence')->middleware('throttle:30,1');
            Route::get('/labour/timer/current', [\App\Http\Controllers\Api\LabourTimerController::class,'current']);
            Route::get('/jobs/{job}/labour-workers', [\App\Http\Controllers\Api\LabourController::class,'workforce'])->whereNumber('job');
            Route::get('/jobs/{job}/labour-summary', [\App\Http\Controllers\Api\LabourController::class,'summary'])->whereNumber('job');
            Route::get('/jobs/{job}/cost-summary', [\App\Http\Controllers\Api\LabourController::class,'summary'])->whereNumber('job');
        });
        Route::middleware(['company.access','company.permission:jobs.write'])->group(function (): void {
            Route::post('/labour/entries', [\App\Http\Controllers\Api\LabourController::class,'store'])->middleware('throttle:30,1');
            Route::patch('/labour/entries/{entry}', [\App\Http\Controllers\Api\LabourController::class,'update'])->whereNumber('entry');
            Route::post('/labour/entries/{entry}/approve', [\App\Http\Controllers\Api\LabourController::class,'approve'])->whereNumber('entry')->middleware('throttle:10,1');
            Route::post('/labour/entries/{entry}/void', [\App\Http\Controllers\Api\LabourController::class,'void'])->whereNumber('entry')->middleware('throttle:10,1');
            Route::post('/labour/entries/{entry}/evidence', [\App\Http\Controllers\Api\LabourController::class,'upload'])->whereNumber('entry')->middleware('throttle:20,1');
            Route::delete('/labour/entries/{entry}/evidence/{evidence}', [\App\Http\Controllers\Api\LabourController::class,'removeEvidence'])->whereNumber('entry')->whereNumber('evidence');
            Route::post('/labour/timer/{action}', [\App\Http\Controllers\Api\LabourTimerController::class,'action'])->whereIn('action',['start','pause','resume','stop'])->middleware('throttle:30,1');
            Route::post('/labour/ai/voice', [\App\Http\Controllers\Api\LabourAiController::class,'voice'])->middleware('throttle:6,1');
        });
        // M18: manual and AI-assisted general document authoring (Stage 1 only).
        Route::middleware(['company.access','company.permission:documents.read'])->group(function (): void {
            Route::get('/general-documents', [\App\Http\Controllers\Api\GeneralDocumentController::class,'index']);
            Route::get('/general-documents/{document}', [\App\Http\Controllers\Api\GeneralDocumentController::class,'show'])->whereNumber('document');
            Route::get('/general-documents/{document}/revisions', [\App\Http\Controllers\Api\GeneralDocumentController::class,'revisions'])->whereNumber('document');
            Route::get('/general-documents/{document}/attachments/{attachment}', [\App\Http\Controllers\Api\GeneralDocumentController::class,'attachment'])->whereNumber('document')->whereNumber('attachment')->middleware('throttle:30,1');
        });
        Route::middleware(['company.access','company.permission:documents.write'])->group(function (): void {
            Route::post('/general-documents', [\App\Http\Controllers\Api\GeneralDocumentController::class,'store'])->middleware('throttle:20,1');
            Route::patch('/general-documents/{document}', [\App\Http\Controllers\Api\GeneralDocumentController::class,'update'])->whereNumber('document');
            Route::post('/general-documents/{document}/review', [\App\Http\Controllers\Api\GeneralDocumentController::class,'review'])->whereNumber('document');
            Route::post('/general-documents/{document}/approve', [\App\Http\Controllers\Api\GeneralDocumentController::class,'approve'])->whereNumber('document');
            Route::post('/general-documents/{document}/archive', [\App\Http\Controllers\Api\GeneralDocumentController::class,'archive'])->whereNumber('document');
            Route::post('/general-documents/{document}/attachments', [\App\Http\Controllers\Api\GeneralDocumentController::class,'upload'])->whereNumber('document')->middleware('throttle:20,1');
            Route::delete('/general-documents/{document}/attachments/{attachment}', [\App\Http\Controllers\Api\GeneralDocumentController::class,'removeAttachment'])->whereNumber('document')->whereNumber('attachment');
            Route::post('/general-documents/ai/prepare', [\App\Http\Controllers\Api\GeneralDocumentController::class,'ai'])->middleware('throttle:6,1');
            Route::post('/general-documents/voice/transcribe', [\App\Http\Controllers\Api\GeneralDocumentController::class,'transcribe'])->middleware('throttle:6,1');
        });
        // M18.2: issue, immutable PDF, secure links, signature requests and email.
        Route::middleware(['company.access','company.permission:documents.read'])->group(function (): void {
            Route::get('/general-documents/{document}/issued', [\App\Http\Controllers\Api\GeneralDocumentIssueController::class,'show'])->whereNumber('document');
            Route::get('/general-documents/{document}/pdf', [\App\Http\Controllers\Api\GeneralDocumentIssueController::class,'pdfView'])->whereNumber('document')->middleware('throttle:30,1');
            Route::get('/general-documents/{document}/signatures/{signature}/signed-pdf', [\App\Http\Controllers\Api\GeneralDocumentIssueController::class,'signedPdf'])->whereNumber('document')->whereNumber('signature')->middleware('throttle:20,1');
        });
        Route::middleware(['company.access','company.permission:documents.write'])->group(function (): void {
            Route::post('/general-documents/{document}/issue', [\App\Http\Controllers\Api\GeneralDocumentIssueController::class,'issue'])->whereNumber('document')->middleware('throttle:10,1');
            Route::post('/general-documents/{document}/customer-link', [\App\Http\Controllers\Api\GeneralDocumentIssueController::class,'customerLink'])->whereNumber('document')->middleware('throttle:10,1');
            Route::delete('/general-documents/{document}/customer-links/{link}', [\App\Http\Controllers\Api\GeneralDocumentIssueController::class,'revokeLink'])->whereNumber('document')->whereNumber('link');
            Route::post('/general-documents/{document}/signature-requests', [\App\Http\Controllers\Api\GeneralDocumentIssueController::class,'signature'])->whereNumber('document')->middleware('throttle:10,1');
            Route::delete('/general-documents/{document}/signature-requests/{signature}', [\App\Http\Controllers\Api\GeneralDocumentIssueController::class,'revokeSignature'])->whereNumber('document')->whereNumber('signature');
            Route::post('/general-documents/{document}/send', [\App\Http\Controllers\Api\GeneralDocumentIssueController::class,'send'])->whereNumber('document')->middleware('throttle:6,1');
        });
        // M17: invoice reads and explicit manual payment ledger, no gateway.
        Route::middleware(['company.access','company.permission:documents.read',
            'company.permission:jobs.read'])->group(function (): void {
            Route::get('/invoices', [\App\Http\Controllers\Api\InvoiceController::class,'index']);
            Route::get('/jobs/{job}/invoice-sources', [\App\Http\Controllers\Api\InvoiceController::class,'sources'])->whereNumber('job');
            Route::get('/jobs/{job}/invoice-deposits', [\App\Http\Controllers\Api\InvoiceController::class,'availableDeposits'])->whereNumber('job');
            Route::get('/invoices/{invoice}', [\App\Http\Controllers\Api\InvoiceController::class,'show'])->whereNumber('invoice');
            Route::get('/invoices/{invoice}/document', [\App\Http\Controllers\Api\InvoiceController::class,'document'])->whereNumber('invoice');
            Route::get('/invoices/{invoice}/pdf', [\App\Http\Controllers\Api\InvoiceController::class,'pdf'])->whereNumber('invoice')->middleware('throttle:30,1');
            Route::get('/invoices/{invoice}/pdf/download', [\App\Http\Controllers\Api\InvoiceController::class,'download'])->whereNumber('invoice')->middleware('throttle:30,1');
            Route::get('/invoices/{invoice}/reconciliation', [\App\Http\Controllers\Api\InvoiceController::class,'reconciliation'])->whereNumber('invoice');
            Route::get('/invoices/{invoice}/attachments/{attachment}', [\App\Http\Controllers\Api\InvoiceController::class,'attachment'])->whereNumber('invoice')->whereNumber('attachment')->middleware('throttle:30,1');
        });
        Route::middleware(['company.access','company.permission:documents.write',
            'company.permission:jobs.read'])->group(function (): void {
            Route::post('/invoices', [\App\Http\Controllers\Api\InvoiceController::class,'store'])->middleware('throttle:20,1');
            Route::patch('/invoices/{invoice}', [\App\Http\Controllers\Api\InvoiceController::class,'update'])->whereNumber('invoice');
            Route::post('/invoices/{invoice}/approve', [\App\Http\Controllers\Api\InvoiceController::class,'approve'])->whereNumber('invoice')->middleware('throttle:10,1');
            Route::post('/invoices/{invoice}/issue', [\App\Http\Controllers\Api\InvoiceController::class,'issue'])->whereNumber('invoice')->middleware('throttle:10,1');
            Route::post('/invoices/{invoice}/customer-link', [\App\Http\Controllers\Api\InvoiceController::class,'customerLink'])->whereNumber('invoice')->middleware('throttle:10,1');
            Route::post('/invoices/{invoice}/send', [\App\Http\Controllers\Api\InvoiceController::class,'send'])->whereNumber('invoice')->middleware('throttle:5,1');
            Route::post('/invoices/{invoice}/payments', [\App\Http\Controllers\Api\InvoiceController::class,'payment'])->whereNumber('invoice')->middleware('throttle:10,1');
            Route::post('/invoices/{invoice}/payments/{payment}/void', [\App\Http\Controllers\Api\InvoiceController::class,'voidPayment'])->whereNumber('invoice')->whereNumber('payment')->middleware('throttle:10,1');
            Route::post('/invoices/{invoice}/void', [\App\Http\Controllers\Api\InvoiceController::class,'voidInvoice'])->whereNumber('invoice')->middleware('throttle:10,1');
            Route::post('/invoices/{invoice}/attachments', [\App\Http\Controllers\Api\InvoiceController::class,'upload'])->whereNumber('invoice')->middleware('throttle:20,1');
            Route::delete('/invoices/{invoice}/attachments/{attachment}', [\App\Http\Controllers\Api\InvoiceController::class,'removeAttachment'])->whereNumber('invoice')->whereNumber('attachment');
        });
        // M16: CHF manual deposits; no payment gateway or automatic bank settlement.
        Route::middleware(['company.access','company.permission:documents.read',
            'company.permission:jobs.read'])->group(function (): void {
            Route::get('/deposits', [\App\Http\Controllers\Api\DepositController::class,'index']);
            Route::get('/deposits/{deposit}', [\App\Http\Controllers\Api\DepositController::class,'show'])->whereNumber('deposit');
            Route::get('/deposits/{deposit}/receipts/{receipt}', [\App\Http\Controllers\Api\DepositController::class,'receipt'])->whereNumber('deposit')->whereNumber('receipt')->middleware('throttle:30,1');
            Route::get('/jobs/{job}/deposit-summary', [\App\Http\Controllers\Api\DepositController::class,'summary'])->whereNumber('job');
        });
        Route::middleware(['company.access','company.permission:documents.write',
            'company.permission:jobs.read'])->group(function (): void {
            Route::post('/deposits', [\App\Http\Controllers\Api\DepositController::class,'store'])->middleware('throttle:20,1');
            Route::post('/deposits/ai/voice', [\App\Http\Controllers\Api\DepositAiController::class,'voice'])->middleware('throttle:6,1');
            Route::post('/deposits/from-quote/{quote}', [\App\Http\Controllers\Api\DepositController::class,'fromQuote'])->whereNumber('quote')->middleware('throttle:10,1');
            Route::patch('/deposits/{deposit}', [\App\Http\Controllers\Api\DepositController::class,'update'])->whereNumber('deposit');
            Route::post('/deposits/{deposit}/receive', [\App\Http\Controllers\Api\DepositController::class,'receive'])->whereNumber('deposit')->middleware('throttle:10,1');
            Route::post('/deposits/{deposit}/refund', [\App\Http\Controllers\Api\DepositController::class,'refund'])->whereNumber('deposit')->middleware('throttle:10,1');
            Route::delete('/deposits/{deposit}', [\App\Http\Controllers\Api\DepositController::class,'destroy'])->whereNumber('deposit');
            Route::post('/deposits/{deposit}/receipts', [\App\Http\Controllers\Api\DepositController::class,'upload'])->whereNumber('deposit')->middleware('throttle:20,1');
            Route::delete('/deposits/{deposit}/receipts/{receipt}', [\App\Http\Controllers\Api\DepositController::class,'removeReceipt'])->whereNumber('deposit')->whereNumber('receipt');
        });
        // M14: company-isolated purchases, private receipts and job budget aggregates.
        Route::middleware(['company.access','company.permission:documents.read'])->group(function (): void {
            Route::get('/expenses', [\App\Http\Controllers\Api\ExpenseController::class,'index']);
            Route::get('/expenses/{expense}', [\App\Http\Controllers\Api\ExpenseController::class,'show'])->whereNumber('expense');
            Route::get('/expenses/{expense}/receipts/{receipt}', [\App\Http\Controllers\Api\ExpenseController::class,'receipt'])->whereNumber('expense')->whereNumber('receipt')->middleware('throttle:30,1');
            Route::get('/jobs/{job}/expense-totals', [\App\Http\Controllers\Api\ExpenseController::class,'totals'])->whereNumber('job');
        });
        Route::middleware(['company.access','company.permission:documents.write','company.permission:jobs.read'])->group(function (): void {
            Route::post('/expenses', [\App\Http\Controllers\Api\ExpenseController::class,'store'])->middleware('throttle:20,1');
            Route::patch('/expenses/{expense}', [\App\Http\Controllers\Api\ExpenseController::class,'update'])->whereNumber('expense');
            Route::post('/expenses/{expense}/receipts', [\App\Http\Controllers\Api\ExpenseController::class,'upload'])->whereNumber('expense')->middleware('throttle:20,1');
            Route::delete('/expenses/{expense}/receipts/{receipt}', [\App\Http\Controllers\Api\ExpenseController::class,'removeReceipt'])->whereNumber('expense')->whereNumber('receipt');
            Route::post('/expenses/{expense}/request-reimbursement', [\App\Http\Controllers\Api\ExpenseController::class,'requestReimbursement'])->whereNumber('expense')->middleware('throttle:6,1');
            Route::post('/expenses/ai/voice', [\App\Http\Controllers\Api\ExpenseAiController::class,'voice'])->middleware('throttle:6,1');
            Route::post('/expenses/ai/receipt', [\App\Http\Controllers\Api\ExpenseAiController::class,'receipt'])->middleware('throttle:6,1');
        });
        // M13: separate customer acceptance lifecycle; quote signing remains unchanged.
        Route::middleware(['company.access','company.permission:documents.read'])->group(function (): void {
            Route::get('/work-acceptances', [\App\Http\Controllers\Api\WorkAcceptanceController::class,'index']);
            Route::get('/work-acceptances/{acceptance}', [\App\Http\Controllers\Api\WorkAcceptanceController::class,'show'])->whereNumber('acceptance');
            Route::get('/work-acceptances/{acceptance}/customer-photos', [\App\Http\Controllers\Api\WorkAcceptanceController::class,'customerPhotos'])->whereNumber('acceptance');
            Route::get('/work-acceptances/{acceptance}/customer-photos/{photo}', [\App\Http\Controllers\Api\WorkAcceptanceController::class,'customerPhoto'])->whereNumber('acceptance')->whereNumber('photo')->middleware('throttle:30,1');
            Route::get('/work-acceptances/{acceptance}/signature', [\App\Http\Controllers\Api\WorkAcceptanceController::class,'signature'])->whereNumber('acceptance')->middleware('throttle:30,1');
            Route::get('/work-acceptances/{acceptance}/pdf/{kind}', [\App\Http\Controllers\Api\WorkAcceptanceController::class,'pdf'])->whereNumber('acceptance')->whereIn('kind',['approved','signed'])->middleware('throttle:30,1');
            Route::get('/work-acceptances/{acceptance}/requests', [\App\Http\Controllers\Api\WorkAcceptanceController::class,'requests'])->whereNumber('acceptance');
        });
        Route::middleware(['company.access','company.permission:documents.write',
            'company.permission:jobs.read'])->group(function (): void {
            Route::post('/work-acceptances', [\App\Http\Controllers\Api\WorkAcceptanceController::class,'store'])->middleware('throttle:20,1');
            Route::patch('/work-acceptances/{acceptance}', [\App\Http\Controllers\Api\WorkAcceptanceController::class,'update'])->whereNumber('acceptance');
            Route::post('/work-acceptances/{acceptance}/approve', [\App\Http\Controllers\Api\WorkAcceptanceController::class,'approve'])->whereNumber('acceptance')->middleware('throttle:10,1');
            Route::post('/work-acceptances/{acceptance}/send', [\App\Http\Controllers\Api\WorkAcceptanceController::class,'send'])->whereNumber('acceptance')->middleware('throttle:6,1');
            Route::post('/work-acceptances/{acceptance}/requests/{signature}/revoke', [\App\Http\Controllers\Api\WorkAcceptanceController::class,'revoke'])->whereNumber('acceptance')->whereNumber('signature');
        });
        // M12: opt-in report dictation, no private audio persisted or published.
        Route::middleware(['company.access','company.permission:jobs.read',
            'company.permission:documents.write'])->post('/reports/voice/transcribe',
            [\App\Http\Controllers\Api\SiteReportVoiceController::class,'transcribe'])
            ->middleware('throttle:6,1');

        // M12: site reports and meeting minutes; approved PDFs are immutable M9 documents.
        Route::middleware(['company.access','company.permission:documents.read'])->group(function (): void {
            Route::get('/reports', [\App\Http\Controllers\Api\SiteReportController::class,'index']);
            Route::get('/reports/{report}', [\App\Http\Controllers\Api\SiteReportController::class,'show'])->whereNumber('report');
            Route::get('/reports/{report}/photos/{photo}', [\App\Http\Controllers\Api\SiteReportController::class,'photo'])
                ->whereNumber('report')->whereNumber('photo')->middleware('throttle:50,1');
            Route::get('/reports/{report}/pdf', [\App\Http\Controllers\Api\SiteReportController::class,'pdf'])
                ->whereNumber('report')->middleware('throttle:30,1');
        });
        Route::middleware(['company.access','company.permission:documents.write','company.permission:jobs.read'])->group(function (): void {
            Route::post('/reports', [\App\Http\Controllers\Api\SiteReportController::class,'create'])
                ->middleware('throttle:20,1');
            Route::patch('/reports/{report}', [\App\Http\Controllers\Api\SiteReportController::class,'update'])->whereNumber('report');
            Route::post('/reports/{report}/prepare-ai', [\App\Http\Controllers\Api\SiteReportController::class,'prepare'])
                ->whereNumber('report')->middleware('throttle:6,1');
            Route::post('/reports/{report}/approve', [\App\Http\Controllers\Api\SiteReportController::class,'approve'])
                ->whereNumber('report')->middleware('throttle:10,1');
            Route::post('/reports/{report}/send', [\App\Http\Controllers\Api\SiteReportController::class,'send'])
                ->whereNumber('report')->middleware('throttle:6,1');
            Route::delete('/reports/{report}', [\App\Http\Controllers\Api\SiteReportController::class,'archive'])
                ->whereNumber('report');
        });

        // M10: signed-source addenda are separate from M7/M8 original artifacts.
        Route::middleware(['company.access','company.permission:documents.read'])->group(function (): void {
            Route::get('/addenda', [\App\Http\Controllers\Api\AddendumController::class,'index']);
            Route::get('/addenda/{addendum}', [\App\Http\Controllers\Api\AddendumController::class,'show'])->whereNumber('addendum');
            Route::get('/addenda/{addendum}/history', [\App\Http\Controllers\Api\AddendumController::class,'history'])->whereNumber('addendum');
            Route::get('/addenda/{addendum}/pdf', [\App\Http\Controllers\Api\AddendumController::class,'pdf'])
                ->whereNumber('addendum')->middleware('throttle:30,1');
            Route::get('/addenda/{addendum}/signature-requests',
                [\App\Http\Controllers\Api\AddendumController::class,'requests'])->whereNumber('addendum');
        });
        Route::middleware(['company.access','company.permission:documents.write'])->group(function (): void {
            Route::post('/addenda/prepare/voice', [\App\Http\Controllers\Api\AddendumAiController::class,'prepareVoice'])
                ->middleware('throttle:10,1');
            Route::post('/addenda', [\App\Http\Controllers\Api\AddendumController::class,'store'])
                ->middleware('throttle:15,1');
            Route::patch('/addenda/{addendum}', [\App\Http\Controllers\Api\AddendumController::class,'update'])
                ->whereNumber('addendum');
            Route::post('/addenda/{addendum}/approve', [\App\Http\Controllers\Api\AddendumController::class,'approve'])
                ->whereNumber('addendum')->middleware('throttle:10,1');
            Route::post('/addenda/{addendum}/signature-requests',
                [\App\Http\Controllers\Api\AddendumController::class,'requestSignature'])
                ->whereNumber('addendum')->middleware('throttle:10,1');
            Route::post('/addenda/{addendum}/signature-requests/{signature}/revoke',
                [\App\Http\Controllers\Api\AddendumController::class,'revoke'])
                ->whereNumber('addendum')->whereNumber('signature')->middleware('throttle:10,1');
        });

        // M19 — equipment inventory, secured loan lifecycle and private photos.
        Route::middleware(['company.access','company.permission:equipment.read'])->group(function (): void {
            Route::get('/equipment',[\App\Http\Controllers\Api\EquipmentController::class,'index']);
            Route::get('/equipment/{equipment}',[\App\Http\Controllers\Api\EquipmentController::class,'show'])->whereNumber('equipment');
            Route::get('/equipment/{equipment}/photos/{photo}',[\App\Http\Controllers\Api\EquipmentController::class,'photoBytes'])
                ->whereNumber('equipment')->whereNumber('photo')->middleware('throttle:30,1');
            Route::get('/equipment-loans',[\App\Http\Controllers\Api\EquipmentLoanController::class,'index']);
            Route::get('/equipment-loans/{loan}',[\App\Http\Controllers\Api\EquipmentLoanController::class,'show'])->whereNumber('loan');
            Route::get('/equipment-loans/{loan}/reminders/preview',
                [\App\Http\Controllers\Api\EquipmentLoanController::class,'reminderPreview'])->whereNumber('loan');
            Route::get('/equipment-loans/{loan}/reminders',
                [\App\Http\Controllers\Api\EquipmentLoanController::class,'reminderHistory'])->whereNumber('loan');
            Route::get('/equipment-loans/{loan}/events',[\App\Http\Controllers\Api\EquipmentLoanController::class,'history'])->whereNumber('loan');
        });
        Route::middleware(['company.access','company.permission:equipment.write'])->group(function (): void {
            Route::post('/equipment/voice/prepare',[\App\Http\Controllers\Api\EquipmentController::class,'voice'])->middleware('throttle:4,1');
            Route::post('/equipment',[\App\Http\Controllers\Api\EquipmentController::class,'store'])->middleware('throttle:15,1');
            Route::patch('/equipment/{equipment}',[\App\Http\Controllers\Api\EquipmentController::class,'update'])->whereNumber('equipment');
            Route::post('/equipment/{equipment}/archive',[\App\Http\Controllers\Api\EquipmentController::class,'archive'])->whereNumber('equipment');
            Route::post('/equipment/{equipment}/usage',[\App\Http\Controllers\Api\EquipmentController::class,'usage'])->whereNumber('equipment');
            Route::post('/equipment/{equipment}/maintenance',[\App\Http\Controllers\Api\EquipmentController::class,'maintenance'])->whereNumber('equipment');
            Route::post('/equipment/{equipment}/photos',[\App\Http\Controllers\Api\EquipmentController::class,'photo'])->whereNumber('equipment')->middleware('throttle:10,1');
            Route::delete('/equipment/{equipment}/photos/{photo}',[\App\Http\Controllers\Api\EquipmentController::class,'removePhoto'])
                ->whereNumber('equipment')->whereNumber('photo');
            Route::post('/equipment-loans/voice/prepare',[\App\Http\Controllers\Api\EquipmentLoanController::class,'voice'])->middleware('throttle:4,1');
            Route::post('/equipment-loans',[\App\Http\Controllers\Api\EquipmentLoanController::class,'store'])->middleware('throttle:12,1');
            Route::patch('/equipment-loans/{loan}',[\App\Http\Controllers\Api\EquipmentLoanController::class,'update'])->whereNumber('loan');
            Route::post('/equipment-loans/{loan}/extend',[\App\Http\Controllers\Api\EquipmentLoanController::class,'extend'])->whereNumber('loan')->middleware('throttle:12,1');
            Route::post('/equipment-loans/{loan}/reminders/send',
                [\App\Http\Controllers\Api\EquipmentLoanController::class,'sendReminder'])->whereNumber('loan')->middleware('throttle:equipment-reminders');
            Route::put('/equipment-loans/{loan}/reminders/policy',
                [\App\Http\Controllers\Api\EquipmentLoanController::class,'reminderPolicy'])->whereNumber('loan')->middleware('throttle:12,1');
            Route::post('/equipment-loans/{loan}/return',[\App\Http\Controllers\Api\EquipmentLoanController::class,'returnLoan'])->whereNumber('loan')->middleware('throttle:12,1');
        });

        // M20: real company/owner-scoped reminders and in-app notifications.
        // Stage 1 never activates Firebase, SMTP or M19 borrower-email sending.
        Route::middleware('company.access')->group(function (): void {
            Route::get('/reminders', [\App\Http\Controllers\Api\M20ReminderController::class,'index']);
            Route::post('/reminders', [\App\Http\Controllers\Api\M20ReminderController::class,'store'])->middleware('throttle:30,1');
            Route::get('/reminders/{reminder}', [\App\Http\Controllers\Api\M20ReminderController::class,'show'])->whereNumber('reminder');
            Route::patch('/reminders/{reminder}', [\App\Http\Controllers\Api\M20ReminderController::class,'update'])->whereNumber('reminder');
            Route::post('/reminders/{reminder}/status', [\App\Http\Controllers\Api\M20ReminderController::class,'status'])->whereNumber('reminder');
            Route::post('/push-devices', [\App\Http\Controllers\Api\M20PushDeviceController::class,'register'])->middleware('throttle:20,1');
            Route::get('/notification-preferences', [\App\Http\Controllers\Api\M20NotificationPreferencesController::class,'show']);
            Route::put('/notification-preferences', [\App\Http\Controllers\Api\M20NotificationPreferencesController::class,'update']);
            Route::get('/notifications', [\App\Http\Controllers\Api\M20NotificationController::class,'index']);
            Route::post('/notifications/read-all', [\App\Http\Controllers\Api\M20NotificationController::class,'markAllRead']);
            Route::post('/notifications/{notification}/read', [\App\Http\Controllers\Api\M20NotificationController::class,'markRead'])->whereNumber('notification');
            Route::delete('/notifications/{notification}', [\App\Http\Controllers\Api\M20NotificationController::class,'dismiss'])->whereNumber('notification');
        });

        // Device removal on logout must cover all of this user's companies.
        Route::delete('/push-devices', [\App\Http\Controllers\Api\M20PushDeviceController::class,'revoke'])
            ->middleware('throttle:30,1');

        // M9: unified document catalog; M7 and M8 originals are read-only sources.
        Route::middleware(['company.access','company.permission:documents.read'])->group(function (): void {
            Route::get('/documents', [\App\Http\Controllers\Api\DocumentController::class,'index']);
            Route::get('/documents/{document}', [\App\Http\Controllers\Api\DocumentController::class,'show']);
            Route::get('/documents/{document}/history', [\App\Http\Controllers\Api\DocumentController::class,'history']);
            Route::get('/documents/{document}/versions', [\App\Http\Controllers\Api\DocumentController::class,'versions']);
            Route::get('/documents/{document}/attachments', [\App\Http\Controllers\Api\DocumentController::class,'attachments']);
            Route::get('/documents/{document}/pdf', [\App\Http\Controllers\Api\DocumentController::class,'download'])
                ->middleware('throttle:30,1');
            Route::get('/documents/{document}/attachments/{attachment}/download',
                [\App\Http\Controllers\Api\DocumentController::class,'downloadAttachment'])
                ->whereNumber('attachment')->middleware('throttle:30,1');
        });
        Route::middleware(['company.access','company.permission:documents.write'])->group(function (): void {
            Route::post('/documents', [\App\Http\Controllers\Api\DocumentController::class,'store'])
                ->middleware('throttle:15,1');
            Route::post('/documents/{document}/versions',
                [\App\Http\Controllers\Api\DocumentController::class,'addVersion'])
                ->middleware('throttle:15,1');
            Route::post('/documents/{document}/attachments',
                [\App\Http\Controllers\Api\DocumentController::class,'addAttachment'])
                ->middleware('throttle:15,1');
        });

        // M8: in-person signing has separate authenticated customer/business identities.
        Route::middleware(['company.access','company.permission:quotes.write'])->group(function (): void {
            Route::post('/quotes/{quote}/in-person-signing',
                [\App\Http\Controllers\Api\InPersonSigningController::class,'start'])
                ->whereNumber('quote')->middleware('throttle:10,1');
            Route::post('/signature-requests/{signature}/complete-in-person',
                [\App\Http\Controllers\Api\InPersonSigningController::class,'submit'])
                ->whereNumber('signature')->middleware('throttle:6,1');
        });
        Route::middleware(['company.access','company.permission:documents.read'])->group(function (): void {
            Route::get('/signed-documents',
                [\App\Http\Controllers\Api\SignedDocumentController::class,'index']);
            Route::get('/signed-documents/{document}',
                [\App\Http\Controllers\Api\SignedDocumentController::class,'show'])->whereNumber('document');
            Route::get('/signed-documents/{document}/pdf',
                [\App\Http\Controllers\Api\SignedDocumentController::class,'download'])
                ->whereNumber('document');
        });
        Route::middleware(['company.access','company.permission:documents.write'])->group(function (): void {
            Route::post('/signed-documents/{document}/resend',
                [\App\Http\Controllers\Api\SignedDocumentController::class,'resend'])
                ->whereNumber('document')->middleware('throttle:5,1');
        });

        // M8: tenant-scoped signing-request lifecycle.
        Route::middleware(['company.access', 'company.permission:quotes.read'])->group(function (): void {
            Route::get('/quotes/{quote}/signature-requests',
                [\App\Http\Controllers\Api\SignatureRequestController::class,'index'])->whereNumber('quote');
            Route::get('/signature-requests/{signature}',
                [\App\Http\Controllers\Api\SignatureRequestController::class,'show'])->whereNumber('signature');
        });
        Route::middleware(['company.access', 'company.permission:quotes.write'])->group(function (): void {
            Route::post('/quotes/{quote}/signature-requests',
                [\App\Http\Controllers\Api\SignatureRequestController::class,'store'])
                ->whereNumber('quote')->middleware('throttle:10,1');
            Route::post('/signature-requests/{signature}/revoke',
                [\App\Http\Controllers\Api\SignatureRequestController::class,'revoke'])
                ->whereNumber('signature')->middleware('throttle:10,1');
            Route::post('/signature-requests/{signature}/rotate-link',
                [\App\Http\Controllers\Api\SignatureRequestController::class,'rotateLink'])
                ->whereNumber('signature')->middleware('throttle:10,1');
            Route::post('/signature-requests/{signature}/resend',
                [\App\Http\Controllers\Api\SignatureRequestController::class,'resend'])
                ->whereNumber('signature')->middleware('throttle:10,1');
        });

        Route::middleware('company.access')->get('/company-context', function (Request $request) {
            $company = $request->attributes->get('company');
            return response()->json(['data' => [
                'id' => $company->id,
                'name' => $company->name,
                'status' => $company->status,
            ]]);
        });
    });
});
