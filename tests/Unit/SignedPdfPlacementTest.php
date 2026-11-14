<?php

namespace Tests\Unit;

use App\Services\SignedPdfService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use setasign\Fpdi\Fpdi;

final class SignedPdfPlacementTest extends TestCase
{
    private function signature(): string
    {
        // Deterministic visible PNG, generated without a GD dependency.
        $width = 120;
        $height = 50;
        $pixels = '';
        for ($y = 0; $y < $height; $y++) {
            $line = "\x00";
            for ($x = 0; $x < $width; $x++) {
                $ink = $x > 8 && $x < 111 && abs($y - (36 - $x / 9)) <= 2;
                $line .= $ink ? "\x12\x25\x36" : "\xFF\xFF\xFF";
            }
            $pixels .= $line;
        }
        $chunk = static fn (string $name, string $data): string =>
            pack('N', strlen($data)) . $name . $data .
            pack('N', crc32($name . $data));
        return "\x89PNG\r\n\x1a\n" .
            $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0)) .
            $chunk('IDAT', gzcompress($pixels, 6)) .
            $chunk('IEND', '');
    }

    private function approved(bool $anchored): string
    {
        $pdf = new Fpdi();
        $pdf->SetCompression(false);
        $pdf->AddPage('P', 'A4');
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->Cell(100, 12, 'Approved document');
        $pdf->Line(20, 100, 80, 100);
        $pdf->Line(112, 100, 172, 100);
        if ($anchored) {
            $pdf->SetSubject('S2D-SIG-V1:1:20.00:100.00:112.00:60.00');
        }
        return $pdf->Output('S');
    }

    private function sign(string $source): string
    {
        return (new SignedPdfService())->build(
            $source, 'Q-001', hash('sha256', $source),
            'document-test', 'request-test',
            new DateTimeImmutable('2026-11-15T14:00:00Z'),
            [[
                'role' => 'Customer', 'name' => 'Test Customer',
                'method' => 'draw', 'png' => $this->signature(),
            ]],
            str_repeat('a', 64), 'Quote'
        );
    }

    private function drawingStreams(string $bytes): string
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $bytes, $streams);
        $decoded = '';
        foreach ($streams[1] as $stream) {
            $inflated = @gzuncompress($stream);
            $decoded .= is_string($inflated) ? $inflated : $stream;
        }
        return $decoded;
    }

    public function test_anchored_signed_pdf_displays_date_on_original_signature_line(): void
    {
        $approved = $this->approved(true);
        $signed = $this->sign($approved);

        $this->assertStringStartsWith('%PDF-', $signed);
        $this->assertStringContainsString('(15/11/2026)', $this->drawingStreams($signed));
        $reader = new Fpdi();
        $this->assertSame(2, $reader->setSourceFile(
            \setasign\Fpdi\PdfParser\StreamReader::createByString($signed)
        ));
        // The frozen original remains distinct and is never rewritten.
        $this->assertSame($approved, $this->approved(true));
        $this->assertNotSame(hash('sha256', $approved), hash('sha256', $signed));
    }

    public function test_historical_unanchored_pdf_keeps_original_evidence_only(): void
    {
        $signed = $this->sign($this->approved(false));
        $this->assertStringNotContainsString(
            '(15/11/2026)', $this->drawingStreams($signed)
        );
        $this->assertStringContainsString('Sign2Digital - Signature Record',
            $this->drawingStreams($signed));
    }
}
