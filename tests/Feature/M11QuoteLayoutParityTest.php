<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Quote presentation contract: signed PDFs preserve their immutable approved
 * quote pages and append the separate signer-evidence page. The quote itself
 * keeps exactly one signature/date placeholder pair, after deposit and before
 * terms, with no extra blank pair at the bottom.
 */
final class M11QuoteLayoutParityTest extends TestCase
{
    public function test_web_quote_dates_align_to_the_right_edge_and_do_not_change_original_document_sections(): void
    {
        $template = file_get_contents(resource_path('views/public/quote.blade.php'));
        $this->assertIsString($template);
        $this->assertStringContainsString('justify-self: end;', $template);
        $this->assertStringContainsString('width: min(100%, 195px);', $template);
        $this->assertStringContainsString('.date-line strong { font-weight: 600; white-space: nowrap; text-align: right; }', $template);
        $this->assertStringContainsString('.date-line span { min-width: 0; color: var(--placeholder); line-height: 1.5; text-align: right; }', $template);
        $this->assertStringContainsString('grid-template-columns: 76px minmax(0,1fr); gap: 7px;', $template);
        $this->assertStringContainsString('<div class="date-line"><strong>Date:</strong>', $template);
        $this->assertStringContainsString('<div class="date-line"><strong>Valid Until:</strong>', $template);
    }

    public function test_signature_placeholders_occur_once_after_deposit_and_before_payment_and_terms(): void
    {
        $template = file_get_contents(resource_path('views/public/quote.blade.php'));
        $this->assertIsString($template);
        $this->assertSame(1, substr_count($template, '<div class="signature-fields"'));
        $this->assertSame(1, substr_count($template, '<div class="signature-caption">Customer Signature</div>'));
        $this->assertSame(1, substr_count($template, '<div class="signature-caption">Date</div>'));

        $deposit = strpos($template, 'Remaining after deposit</span>');
        $signature = strpos($template, '<div class="signature-fields"');
        $payment = strpos($template, '<section class="payment-card"');
        $terms = strpos($template, '<section class="terms-section"');
        $this->assertIsInt($deposit);
        $this->assertIsInt($signature);
        $this->assertIsInt($payment);
        $this->assertIsInt($terms);
        $this->assertTrue($deposit < $signature && $signature < $payment && $payment < $terms);
        $this->assertStringNotContainsString('class="signature-fields"', substr($template, $terms));
    }

    public function test_actual_signing_form_and_immutable_signed_evidence_are_preserved(): void
    {
        $quoteView = file_get_contents(resource_path('views/public/quote.blade.php'));
        $signingView = file_get_contents(resource_path('views/public/signing-form.blade.php'));
        $signedPdfSource = file_get_contents(app_path('Services/SignedPdfService.php'));
        $this->assertIsString($quoteView);
        $this->assertIsString($signingView);
        $this->assertIsString($signedPdfSource);
        $this->assertStringContainsString("@include('public.signing-form')", $quoteView);
        $this->assertStringContainsString('id="sign2digital-signing"', $signingView);
        $this->assertStringContainsString("'Sign2Digital - Signature Record'", $signedPdfSource);
        $this->assertStringContainsString('$pdf->useTemplate(', $signedPdfSource);
        $this->assertStringContainsString(
            'Evidence of electronic consent. This PDF is not a qualified cryptographic digital signature.',
            $signedPdfSource
        );
    }
}
