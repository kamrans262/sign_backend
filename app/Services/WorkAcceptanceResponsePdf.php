<?php

namespace App\Services;

use setasign\Fpdi\Fpdi;

/**
 * Preserves imported immutable approved pages byte-for-byte in appearance.
 * Only newly appended customer-response pages receive our shared branding.
 */
final class WorkAcceptanceResponsePdf extends Fpdi
{
    private int $originalPages = 0;
    private string $reference = '';
    private string $company = '';

    public function customerSection(int $originalPages,string $reference,string $company): void
    {
        $this->originalPages = $originalPages;
        $this->reference = $reference;
        $this->company = $company;
        $this->SetMargins(20, 58, 20);
        $this->SetAutoPageBreak(true, 20);
        $this->AliasNbPages();
    }

    public function Header(): void
    {
        if ($this->PageNo() <= $this->originalPages) return;
        Sign2DigitalDocumentPdf::drawHeader(
            $this,'Customer Response',$this->reference,$this->company
        );
    }

    public function Footer(): void
    {
        if ($this->PageNo() <= $this->originalPages) return;
        Sign2DigitalDocumentPdf::drawFooter($this);
    }
}
