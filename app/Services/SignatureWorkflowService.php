<?php

namespace App\Services;

use App\Models\ServiceQuote;
use App\Models\SignatureEvent;
use App\Models\SignatureRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class SignatureWorkflowService
{
    public function approvedBytes(ServiceQuote $quote): string
    {
        if (!in_array($quote->status, ['approved', 'sent'], true)
            || !$quote->approved_pdf_path || !$quote->approved_pdf_sha256
            || (int) $quote->approved_pdf_revision !== (int) $quote->revision) {
            throw new HttpException(409, 'A verified approved quote PDF is required before signing.');
        }

        $disk = Storage::disk('local');
        if (!$disk->exists($quote->approved_pdf_path)) {
            throw new HttpException(503, 'The approved document is unavailable.');
        }
        $bytes = $disk->get($quote->approved_pdf_path);
        if (!is_string($bytes) || strlen($bytes) > 5 * 1024 * 1024
            || strlen($bytes) < 12 || !str_starts_with($bytes, '%PDF-')
            || !str_contains(substr($bytes, -4096), '%%EOF')
            || !hash_equals($quote->approved_pdf_sha256, hash('sha256', $bytes))) {
            throw new HttpException(503, 'Approved PDF integrity validation failed.');
        }
        return $bytes;
    }

    public function validForSigning(SignatureRequest $signature): ServiceQuote
    {
        if (!in_array($signature->status, ['ready', 'sent', 'viewed'], true)
            || $signature->expires_at->isPast() || $signature->revoked_at !== null) {
            throw new HttpException(410, 'This signing link has expired or is no longer active.');
        }
        $quote = $signature->quote;
        if (!$quote
            || !in_array($quote->status, ['approved', 'sent'], true)
            || (int) $quote->company_id !== (int) $signature->company_id
            || (int) $quote->revision !== (int) $signature->approved_revision
            || (int) $quote->link_version !== (int) $signature->quote_link_version
            || !is_string($quote->approved_pdf_sha256)
            || !hash_equals($signature->approved_pdf_sha256, $quote->approved_pdf_sha256)
            || (int) $quote->approved_pdf_revision !== (int) $signature->approved_revision
            || !hash_equals($signature->consent_reference,
                hash('sha256', ($quote->terms ?? '')."\n".$quote->approved_pdf_sha256))) {
            throw new HttpException(410, 'The approved document is no longer available for this request.');
        }
        if ($quote->company?->status !== 'active') {
            throw new HttpException(403,'The signing business is inactive.');
        }
        $this->approvedBytes($quote);
        return $quote;
    }

    public function event(SignatureRequest $signature, string $type, ?Request $request = null,
        ?int $actor = null, array $metadata = []): void
    {
        SignatureEvent::create([
            'signature_request_id' => $signature->id,
            'company_id' => $signature->company_id,
            'actor_user_id' => $actor,
            'event_type' => $type,
            'ip_address' => $request?->ip(),
            'user_agent' => mb_substr((string) ($request?->userAgent() ?? ''), 0, 500),
            // No raw tokens, signature content, customer terms or PDF bytes.
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    public function publicUrl(string $token): string
    {
        return URL::route('signing.public.browser', ['token' => $token]);
    }

    public static function freshToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function eventsForMobile(SignatureRequest $signature): array
    {
        $types = [
            'signature_requested' => 'signatureRequested',
            'document_sent' => 'documentSent',
            'document_viewed' => 'documentViewed',
            'document_signed' => 'documentSigned',
            'document_completed' => 'documentCompleted',
            'reminder_sent' => 'reminderSent',
        ];
        return $signature->events()->get()->map(function (SignatureEvent $event) use ($types) {
            return [
                'id' => (string) $event->id,
                'type' => $types[$event->event_type] ?? $event->event_type,
                'occurredAt' => $event->created_at->toIso8601String(),
            ];
        })->filter(fn (array $event) => in_array($event['type'], $types, true))
            ->values()->all();
    }
}
