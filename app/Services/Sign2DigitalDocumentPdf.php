<?php

namespace App\Services;

/**
 * Shared, non-quotation document styling using the existing approved quotation
 * design tokens: ink #122536, blue #166282, grey #8A9AA8, rule #E4EAF0.
 *
 * Original Flutter quotation generator and frozen issued PDFs are untouched.
 * This class is used only when issuing NEW Site Reports, Meeting Minutes and
 * Work Acceptance PDFs. Approved/signed historical PDFs are immutable.
 */
final class Sign2DigitalDocumentPdf extends \FPDF
{
    private string $documentName;
    private string $reference;
    private string $businessName;

    public function __construct(string $documentName, string $reference, string $businessName = '')
    {
        parent::__construct('P', 'mm', 'A4');
        $this->documentName = $documentName;
        $this->reference = $reference;
        $this->businessName = $businessName;
        $this->SetMargins(20, 58, 20);
        $this->SetAutoPageBreak(true, 20);
        $this->AliasNbPages();
    }

    public static function text(string $text): string
    {
        $converted = iconv(
            'UTF-8', 'Windows-1252//TRANSLIT//IGNORE',
            mb_substr(str_replace(["\r\n", "\r"], "\n", $text), 0, 16000)
        );
        return preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', ' ', (string) $converted) ?? '';
    }

    public static function drawHeader(\FPDF $pdf,string $documentName,
        string $reference,string $businessName=''): void
    {
        $pdf->SetDrawColor(228, 234, 240);
        $pdf->SetLineWidth(.4);
        $pdf->Rect(14, 13, 182, 40);
        $logo = public_path('admin-assets/logo.png');
        $size = is_file($logo) ? @getimagesize($logo) : false;
        if (is_array($size) && ($size['mime'] ?? '') === 'image/png'
            && $size[0] > 0 && $size[1] > 0) {
            $ratio = $size[0] / $size[1];
            $w = min(48.0, 15.0 * $ratio);
            $pdf->Image($logo, 20, 18, $w, $w / $ratio, 'PNG');
        } else {
            $pdf->SetXY(20, 20);
            $pdf->SetTextColor(22, 98, 130);
            $pdf->SetFont('Arial', 'B', 17);
            $pdf->Cell(95, 8, 'Sign2Digital');
        }
        if ($businessName !== '') {
            $pdf->SetXY(20, 39);
            $pdf->SetTextColor(138, 154, 168);
            $pdf->SetFont('Arial', '', 9);
            $pdf->Cell(99, 6, self::text($businessName));
        }
        $pdf->SetXY(124, 20);
        $pdf->SetTextColor(18, 37, 54);
        $pdf->SetFont('Arial', 'B', 15);
        $pdf->Cell(66, 8, self::text($documentName), 0, 2, 'R');
        $pdf->SetTextColor(138, 154, 168);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(66, 6, self::text($reference), 0, 2, 'R');
        $pdf->SetY(58);
    }

    public static function drawFooter(\FPDF $pdf): void
    {
        $pdf->SetY(-16);
        $pdf->SetDrawColor(228, 234, 240);
        $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
        $pdf->Ln(3);
        $pdf->SetTextColor(138, 154, 168);
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(125, 6, 'Sign2Digital', 0, 0, 'L');
        $pdf->Cell(45, 6, 'Page '.$pdf->PageNo().'/{nb}', 0, 0, 'R');
    }

    public function Header(): void
    {
        self::drawHeader($this,$this->documentName,
            $this->reference,$this->businessName);
    }

    public function Footer(): void
    {
        self::drawFooter($this);
    }

    public function metadata(string $label, string $value): void
    {
        if ($value === '') return;
        $this->SetTextColor(138, 154, 168);
        $this->SetFont('Arial', 'B', 9);
        $this->MultiCell(0, 5, self::text($label));
        $this->SetTextColor(18, 37, 54);
        $this->SetFont('Arial', '', 10);
        $this->MultiCell(0, 6, self::text($value));
        $this->Ln(2);
    }

    public function section(string $heading, string $body = ''): void
    {
        if ($body === '') return;
        if ($this->GetY() > 245) $this->AddPage();
        $this->Ln(4);
        $this->SetFillColor(22, 98, 130);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 9, self::text($heading), 0, 1, 'L', true);
        $this->Ln(3);
        $this->SetTextColor(18, 37, 54);
        $this->SetFont('Arial', '', 10);
        $this->MultiCell(0, 6, self::text($body));
        $this->Ln(2);
    }

    public function footnote(string $note): void
    {
        $this->Ln(6);
        $this->SetTextColor(138, 154, 168);
        $this->SetFont('Arial', 'I', 8);
        $this->MultiCell(0, 5, self::text($note));
    }
}
