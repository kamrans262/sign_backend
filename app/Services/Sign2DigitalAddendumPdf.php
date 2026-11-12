<?php

namespace App\Services;

use App\Models\Addendum;
use App\Models\ServiceQuote;
use setasign\Fpdi\Fpdi;

/**
 * New addendum PDFs follow the currently approved Flutter Quotation PDF design:
 * A4 / 38pt outer margins, a padded logo-and-recipient card, blue work-item
 * table header, right-aligned totals, signature lines, and grey terms.
 *
 * This renderer is only used BEFORE an addendum is approved. The original
 * signed quotation and any already-approved or signed PDFs remain immutable.
 */
final class Sign2DigitalAddendumPdf extends Fpdi
{
    private const BLUE = [22, 98, 130];     // #166282
    private const INK = [18, 37, 54];       // #122536
    private const GREY = [138, 154, 168];   // #8A9AA8
    private const RULE = [228, 234, 240];   // #E4EAF0
    private const LEFT = 13.4;              // 38 pt
    private const WIDTH = 183.2;
    private const INNER = 20.5;             // 20 pt inside quotation card

    private static function pdfText(string $value): string
    {
        return Sign2DigitalDocumentPdf::pdfString($value);
    }

    private function ink(): void { $this->SetTextColor(...self::INK); }
    private function grey(): void { $this->SetTextColor(...self::GREY); }

    private function ensureSpace(float $height): void
    {
        if ($this->GetY() + $height > 276) {
            $this->AddPage('P', 'A4');
        }
    }

    private static function money(int $minor, string $currency): string
    {
        return $currency.' '.number_format($minor / 100, 2, '.', ',');
    }

    private function lines(string $value, float $width): array
    {
        $paragraphs = explode("\n", self::pdfText($value));
        $lines = [];
        foreach ($paragraphs as $paragraph) {
            $words = preg_split('/\s+/', trim($paragraph), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($words === []) {
                $lines[] = '';
                continue;
            }
            $line = '';
            foreach ($words as $word) {
                $candidate = $line === '' ? $word : $line.' '.$word;
                if ($this->GetStringWidth($candidate) <= $width) {
                    $line = $candidate;
                    continue;
                }
                if ($line !== '') {
                    $lines[] = $line;
                    $line = '';
                }
                // Split long URLs / identifiers without clipping the right edge.
                foreach (str_split($word) as $character) {
                    if ($line !== '' && $this->GetStringWidth($line.$character) > $width) {
                        $lines[] = $line;
                        $line = '';
                    }
                    $line .= $character;
                }
            }
            if ($line !== '') $lines[] = $line;
        }
        return $lines === [] ? [''] : $lines;
    }

    private function documentCard(Addendum $item, ServiceQuote $quote): void
    {
        $top = 13.4;
        $this->SetFont('Helvetica', '', 10);
        $recipient = array_values(array_filter([
            (string) $quote->client_name,
            (string) ($quote->billing_address ?? ''),
            (string) ($quote->client_email ?? ''),
            (string) ($quote->client_phone ?? ''),
        ], static fn ($value) => trim($value) !== ''));

        $billLines = [];
        foreach ($recipient as $value) {
            array_push($billLines, ...$this->lines($value, 93));
        }
        $bottom = max(65, 47 + count($billLines) * 5.2 + 8);
        $this->SetDrawColor(...self::RULE);
        $this->SetLineWidth(0.25);
        $this->Rect(self::LEFT, $top, self::WIDTH, $bottom - $top);

        $logo = public_path('admin-assets/logo.png');
        $size = is_file($logo) ? @getimagesize($logo) : false;
        if (is_array($size) && ($size['mime'] ?? '') === 'image/png'
            && $size[0] > 0 && $size[1] > 0) {
            $ratio = $size[0] / $size[1];
            $w = min(46.6, 15.2 * $ratio);
            $this->Image($logo, self::INNER, 20.5, $w, $w / $ratio, 'PNG');
        } else {
            // Same blue-brand fallback as the customer quotation preview.
            $this->SetXY(self::INNER, 22);
            $this->SetFont('Helvetica', 'B', 17);
            $this->SetTextColor(...self::BLUE);
            $this->Cell(75, 9, 'Sign2Digital');
        }

        $this->ink();
        $this->SetFont('Helvetica', 'B', 18);
        $this->SetXY(128, 20.5);
        $this->Cell(61.5, 8, 'Addendum', 0, 1, 'R');
        $this->grey();
        $this->SetFont('Helvetica', '', 12);
        $this->SetXY(124, 30);
        $this->Cell(65.5, 6, self::pdfText((string) $item->number), 0, 1, 'R');

        $this->ink();
        $this->SetFont('Helvetica', 'B', 14);
        $this->SetXY(self::INNER, 41);
        $this->Cell(90, 7, 'Bill To:');
        $this->grey();
        $this->SetFont('Helvetica', '', 11);
        $y = 47;
        foreach ($billLines as $line) {
            $this->SetXY(self::INNER, $y);
            $this->Cell(93, 5.2, $line);
            $y += 5.2;
        }

        $reference = (string) ($quote->custom_quote_number ?: $quote->reference_number);
        $date = $item->effective_date?->format('d/m/Y') ?? 'Not specified';
        $this->dateRow('Date:', $date, 43);
        $this->dateRow('Original:', $reference, 52);
        $this->SetY($bottom + 7);
    }

    private function dateRow(string $label, string $value, float $y): void
    {
        $this->ink();
        $this->SetFont('Helvetica', 'B', 11);
        $this->SetXY(131, $y);
        $this->Cell(23, 6, self::pdfText($label), 0, 0, 'R');
        $this->grey();
        $this->SetFont('Helvetica', '', 11);
        $this->SetXY(157, $y);
        // Keep values against the same right inset as the document number.
        $this->Cell(32.5, 6, self::pdfText($value), 0, 0, 'R');
    }

    private function prose(string $heading, string $text): void
    {
        if (trim($text) === '') return;
        $this->ensureSpace(22);
        $this->ink();
        $this->SetFont('Helvetica', 'B', 14);
        $this->SetX(self::LEFT);
        $this->MultiCell(self::WIDTH, 7, self::pdfText($heading));
        $this->Ln(3);
        $this->grey();
        $this->SetFont('Helvetica', '', 11);
        $this->SetX(self::LEFT);
        $this->MultiCell(self::WIDTH, 5.8, self::pdfText($text));
        $this->Ln(5);
    }

    private function tableHeader(): void
    {
        $this->ensureSpace(25);
        $x = self::LEFT;
        $y = $this->GetY();
        $widths = [78.5, 19.0, 42.85, 42.85];
        $this->SetFillColor(...self::BLUE);
        $this->Rect($x, $y, self::WIDTH, 11, 'F');
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Helvetica', 'B', 11);
        foreach (['Description', 'Qty', 'Unit Price', 'Total'] as $i => $label) {
            $this->SetXY($x + ($i === 0 ? 3 : 0), $y + 2);
            $this->Cell($widths[$i] - ($i === 0 ? 3 : 0), 7, $label,
                0, 0, $i === 0 ? 'L' : ($i === 3 ? 'R' : 'C'));
            $x += $widths[$i];
        }
        $this->SetY($y + 11);
    }

    private function itemTable(Addendum $item): void
    {
        $this->tableHeader();
        $this->SetFont('Helvetica', '', 10);
        $description = trim((string) ($item->additional_work ?? ''));
        $rows = $this->lines($description === '' ? 'No priced additional work' : $description, 72);
        $first = true;
        while ($rows !== []) {
            $available = 276 - $this->GetY() - 3;
            if ($available < 12) {
                $this->AddPage('P', 'A4');
                $this->tableHeader();
                $available = 276 - $this->GetY() - 3;
            }
            $count = min(count($rows), max(1, (int) floor(($available - 6) / 5.3)));
            $segment = array_splice($rows, 0, $count);
            $rowHeight = max(12, count($segment) * 5.3 + 6);
            $x = self::LEFT;
            $y = $this->GetY();
            $this->SetDrawColor(...self::RULE);
            $this->Rect($x, $y, self::WIDTH, $rowHeight);
            foreach ([78.5, 19, 42.85] as $part) {
                $x += $part;
                $this->Line($x, $y, $x, $y + $rowHeight);
            }
            $this->ink();
            $this->SetFont('Helvetica', '', 10);
            $this->SetXY(self::LEFT + 3, $y + 3);
            foreach ($segment as $line) {
                $this->Cell(73, 5.3, $line, 0, 2);
            }
            if ($first) {
                $this->grey();
                $this->SetXY(self::LEFT + 78.5, $y + 3);
                $this->Cell(19, 6, $item->quantity === null ? '-' : (string) $item->quantity, 0, 0, 'C');
                $unitPrice = $item->unit_price_minor === null
                    ? '-' : self::money((int) $item->unit_price_minor, (string) $item->currency);
                $this->Cell(42.85, 6, $unitPrice, 0, 0, 'C');
                $this->ink();
                $this->Cell(39.85, 6, self::money((int) $item->subtotal_minor, (string) $item->currency), 0, 0, 'R');
            }
            $first = false;
            $this->SetY($y + $rowHeight);
        }
        $this->Ln(4);
    }

    private function totalRow(string $label, string $amount, bool $total = false): void
    {
        $this->ensureSpace(10);
        $y = $this->GetY();
        if ($total) {
            $this->SetFillColor(...self::BLUE);
            $this->Rect(self::LEFT, $y, self::WIDTH, 9, 'F');
            $this->SetTextColor(255, 255, 255);
        } else {
            $this->grey();
        }
        $this->SetFont('Helvetica', $total ? 'B' : '', $total ? 12 : 10);
        $this->SetXY(112, $y + 1);
        $this->Cell(36, 7, self::pdfText($label), 0, 0, 'R');
        if (!$total) $this->ink();
        $this->SetXY(151, $y + 1);
        $this->Cell(38.5, 7, self::pdfText($amount), 0, 0, 'R');
        $this->SetY($y + 9);
    }

    private function signatures(): void
    {
        $this->ensureSpace(49);
        $this->Ln(23); // Quotation PDF's 65pt signature gap.
        $y = $this->GetY();
        $this->SetDrawColor(...self::GREY);
        $this->SetLineWidth(.5);
        foreach ([self::INNER, 112] as $x) {
            $this->Line($x, $y, $x + 60, $y);
        }
        $this->grey();
        $this->SetFont('Helvetica', '', 10);
        $this->SetXY(self::INNER, $y + 3);
        $this->Cell(60, 6, 'Customer Signature');
        $this->SetXY(112, $y + 3);
        $this->Cell(60, 6, 'Date');
        $this->SetY($y + 13);
    }

    private function sourceCard(Addendum $item, ServiceQuote $quote): void
    {
        $this->ensureSpace(51);
        $y = $this->GetY() + 5;
        $this->SetDrawColor(...self::RULE);
        $this->Rect(self::LEFT, $y, self::WIDTH, 47);
        $this->ink();
        $this->SetXY(self::INNER, $y + 4);
        $this->SetFont('Helvetica', 'B', 14);
        $this->Cell(170, 7, 'Original Signed Document');
        $entries = [
            'Original quote' => (string) ($quote->custom_quote_number ?: $quote->reference_number),
            'Signed document' => 'signed-'.$item->source_signed_artifact_id,
            'Addendum revision' => (string) $item->revision,
            'Original SHA-256' => (string) $item->source_pdf_sha256,
        ];
        $cursor = $y + 13;
        foreach ($entries as $label => $value) {
            $this->ink();
            $this->SetFont('Helvetica', 'B', 9);
            $this->SetXY(self::INNER, $cursor);
            $this->Cell(42, 6, $label);
            $this->grey();
            $this->SetFont('Helvetica', '', $label === 'Original SHA-256' ? 8 : 10);
            $this->SetXY(65, $cursor);
            $this->Cell(122, 6, self::pdfText($value));
            $cursor += 8;
        }
        $this->SetY($y + 52);
    }

    public function render(Addendum $item, ServiceQuote $quote): string
    {
        $this->SetTitle(self::pdfText('Addendum '.$item->number));
        $this->SetAuthor('Sign2Digital');
        $this->SetMargins(self::LEFT, self::LEFT, self::LEFT);
        $this->SetAutoPageBreak(true, 18);
        $this->AddPage('P', 'A4');

        $this->documentCard($item, $quote);
        $this->prose((string) $item->title, (string) $item->change_description);
        $this->itemTable($item);
        $currency = (string) $item->currency;
        $this->totalRow('Subtotal', self::money((int) $item->subtotal_minor, $currency));
        $this->totalRow(
            'Tax ('.number_format($item->vat_basis_points / 100, 2). '%)',
            self::money((int) $item->vat_minor, $currency)
        );
        $this->totalRow('Total', self::money((int) $item->total_minor, $currency), true);
        $this->signatures();
        $this->sourceCard($item, $quote);
        $this->prose('General Terms & Conditions',
            'The original signed quote remains unchanged. This separate addendum '
            .'is not accepted until signed by the customer.');

        return $this->Output('S');
    }
}
