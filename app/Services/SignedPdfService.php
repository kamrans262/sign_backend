<?php

namespace App\Services;

use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

/**
 * Builds a new signed evidence PDF from the exact M7 approval-frozen PDF.
 *
 * This is an evidenced electronic signature (not a qualified PKI/PAdES
 * certificate). All approved pages are imported unchanged as visual templates;
 * signer evidence is appended in a distinct page, never silently substituted.
 */
final class SignedPdfService
{
    private static function label(string $value, int $limit = 230): string
    {
        $value = mb_substr(trim($value), 0, $limit);
        $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value);
        return preg_replace('/[\x00-\x1F\x7F]/', ' ', (string) $converted) ?? '';
    }

    private function text(Fpdi $pdf, string $label, string $value): void
    {
        $pdf->SetFont('Helvetica','B',10);
        $pdf->MultiCell(168,5,self::label($label));
        $pdf->SetFont('Helvetica','',10);
        $pdf->MultiCell(168,5,self::label($value));
        $pdf->Ln(3);
    }

    public static function validatePng(string $bytes): void
    {
        if (strlen($bytes) < 200 || strlen($bytes) > 300 * 1024 ||
            !str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            throw new \InvalidArgumentException('Provide a genuine PNG signature under 300 KB.');
        }
        $dimensions = @getimagesizefromstring($bytes);
        if (!is_array($dimensions) || ($dimensions[2] ?? 0) !== IMAGETYPE_PNG
            || ($dimensions[0] ?? 0) < 60 || ($dimensions[0] ?? 0) > 2400
            || ($dimensions[1] ?? 0) < 24 || ($dimensions[1] ?? 0) > 1200) {
            throw new \InvalidArgumentException('The signature PNG dimensions are invalid.');
        }
    }

    /**
     * Optional machine-readable position in a frozen PDF. Existing historical
     * PDFs without an anchor continue to use their original evidence page.
     * All positions use millimetres measured from the upper left.
     */
    private function signatureAnchor(string $source, int $pageCount): ?array
    {
        if (preg_match(
            '~\/Subject\s*\(S2D-SIG-V1:(\d+):([\d.]+):([\d.]+):([\d.]+):([\d.]+)\)~',
            $source, $found
        ) === 1) {
            $anchor = [
                'page' => (int) $found[1], 'x' => (float) $found[2],
                'y' => (float) $found[3], 'date_x' => (float) $found[4],
                'width' => (float) $found[5],
            ];
            return $this->validAnchor($anchor, $pageCount) ? $anchor : null;
        }

        // Mobile quotation PDF v1.4 marks the signature row with a tiny
        // non-visible link rectangle. Do not infer placement for legacy PDFs.
        if (!str_contains($source, 'sign2digital.invalid/signature-anchor-v1')) {
            return null;
        }
        preg_match_all('~(\d+)\s+0\s+obj\b(.*?)\bendobj~s', $source, $parts,
            PREG_SET_ORDER);
        $objects = [];
        foreach ($parts as $part) $objects[(int) $part[1]] = $part[2];
        $marker = null;
        $rect = null;
        foreach ($objects as $id => $body) {
            if (!str_contains($body, 'sign2digital.invalid/signature-anchor-v1')) {
                continue;
            }
            if (preg_match('~\/Rect\s*\[\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s*\]~',
                $body, $matches) === 1) {
                $marker = $id;
                $rect = array_map('floatval', array_slice($matches, 1));
                break;
            }
        }
        if ($marker === null || $rect === null) return null;

        $pageIndex = 0;
        $matchedPage = null;
        foreach ($objects as $body) {
            if (!preg_match('~\/Type\s*\/Page\b~', $body)) continue;
            $pageIndex++;
            // Some PDF writers put link annotations directly inside the
            // page object rather than referencing a separate object.
            if (str_contains($body, 'sign2digital.invalid/signature-anchor-v1')) {
                $prefix = substr($body, 0,
                    strpos($body, 'sign2digital.invalid/signature-anchor-v1'));
                preg_match_all('~\/Rect\s*\[\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s*\]~',
                    $prefix, $inline, PREG_SET_ORDER);
                if ($inline) {
                    $last = $inline[count($inline) - 1];
                    $rect = array_map('floatval', array_slice($last, 1));
                    $matchedPage = $pageIndex;
                    break;
                }
            }
            $annots = '';
            if (preg_match('~\/Annots\s*\[(.*?)\]~s', $body, $refs)) {
                $annots = $refs[1];
            } elseif (preg_match('~\/Annots\s+(\d+)\s+0\s+R~', $body, $refs)) {
                $annots = $objects[(int)$refs[1]] ?? '';
            }
            if (preg_match('~\b'.preg_quote((string)$marker, '~').'\s+0\s+R\b~',
                $annots)) {
                $matchedPage = $pageIndex;
                break;
            }
        }
        if ($matchedPage === null && $pageCount === 1) $matchedPage = 1;
        if ($matchedPage === null) return null;
        $factor = 25.4 / 72.0;
        // The URL annotation records only the signature row's vertical
        // position and page. The Flutter PDF layout centers the tiny link
        // widget within its column, so its rectangle's X is NOT the left edge
        // of the Customer Signature line. Always use the actual frozen quote
        // layout: 38pt A4 margin, 138pt signature line on the left.
        // This also fixes already approved quotes made with a centered marker.
        $anchor = [
            'page' => $matchedPage,
            'x' => 38 * $factor,
            'y' => (841.89 - $rect[3]) * $factor + 0.35,
            'date_x' => (595.28 - 38 - 138) * $factor,
            'width' => 138 * $factor,
        ];
        return $this->validAnchor($anchor, $pageCount) ? $anchor : null;
    }

    private function validAnchor(array $anchor, int $pageCount): bool
    {
        return $anchor['page'] >= 1 && $anchor['page'] <= $pageCount
            && $anchor['x'] >= 10 && $anchor['x'] <= 165
            && $anchor['y'] >= 28 && $anchor['y'] <= 270
            && $anchor['date_x'] > $anchor['x'] + 25
            && $anchor['date_x'] <= 185
            && $anchor['width'] >= 30 && $anchor['width'] <= 75
            && $anchor['y'] - 19 >= 10;
    }

    /**
     * Place verified customer ink above the existing line without replacing
     * any frozen source page. The appended evidence record remains intact.
     */
    private function stampCustomer(Fpdi $pdf, array $anchor, array $signer,
        \DateTimeInterface $date): void
    {
        $x = $anchor['x'];
        $top = $anchor['y'] - 18;
        $width = $anchor['width'];
        if (isset($signer['png'])) {
            self::validatePng($signer['png']);
            $temp = tempnam(sys_get_temp_dir(), 's2d_stamp_');
            if ($temp === false) throw new \RuntimeException('Signature preview unavailable.');
            try {
                if (file_put_contents($temp, $signer['png']) !== strlen($signer['png'])) {
                    throw new \RuntimeException('Signature preview unavailable.');
                }
                @chmod($temp, 0600);
                $pdf->Image($temp, $x, $top, $width, 16, 'PNG');
            } finally {
                @unlink($temp);
            }
        } elseif (isset($signer['strokes'])) {
            $pdf->SetDrawColor(18, 37, 54);
            $pdf->SetLineWidth(.4);
            foreach ($signer['strokes'] as $stroke) {
                $previous = null;
                foreach ($stroke as $point) {
                    $current = [
                        $x + (float)$point['x'] * $width,
                        $top + (float)$point['y'] * 16,
                    ];
                    if ($previous) $pdf->Line(
                        $previous[0], $previous[1], $current[0], $current[1]
                    );
                    $previous = $current;
                }
            }
        } else {
            throw new \InvalidArgumentException('Customer signature is missing.');
        }

        $pdf->SetFont('Helvetica', '', 11);
        $pdf->SetTextColor(18, 37, 54);
        $pdf->SetXY($anchor['date_x'] + 1, $anchor['y'] - 8);
        $pdf->Cell(58, 7, $date->format('d/m/Y'));
    }

    /**
     * @param array<int,array{role:string,name:string,method:string,png?:string,strokes?:array}> $signers
     */
    public function build(string $approvedBytes, string $quoteNumber, string $sourceHash,
        string $documentId, string $requestId, \DateTimeInterface $signedAt,
        array $signers, string $consentReference, string $documentType = 'Quote'): string
    {
        if (strlen($approvedBytes) > 5 * 1024 * 1024 ||
            !str_starts_with($approvedBytes,'%PDF-') || count($signers) < 1 ||
            count($signers) > 2 || !preg_match('/^[a-f0-9]{64}$/D', $sourceHash) ||
            !hash_equals($sourceHash,hash('sha256',$approvedBytes))) {
            throw new \InvalidArgumentException('Invalid frozen approval source.');
        }
        if (!class_exists(Fpdi::class)) {
            throw new \RuntimeException('The PDF importing library is not installed.');
        }

        $pdf = new Fpdi();
        $pdf->SetAutoPageBreak(false);
        $pdf->SetTitle(self::label('Sign2Digital Signed '.$documentType.' '.$quoteNumber));
        $pdf->SetAuthor('Sign2Digital');

        // Parser errors propagate. Never generate a new quote layout from
        // unverified structured data when the frozen source is unreadable.
        $source = StreamReader::createByString($approvedBytes);
        $count = $pdf->setSourceFile($source);
        if ($count < 1 || $count > 100) {
            throw new \RuntimeException('The approved PDF has an unsupported page count.');
        }
        $anchor = $this->signatureAnchor($approvedBytes, $count);
        $customer = null;
        foreach ($signers as $signer) {
            if (strcasecmp((string)($signer['role'] ?? ''), 'customer') === 0) {
                $customer = $signer;
                break;
            }
        }
        for ($i=1;$i<=$count;$i++) {
            $template = $pdf->importPage($i);
            $size = $pdf->getTemplateSize($template);
            $pdf->AddPage($size['orientation'], [$size['width'],$size['height']]);
            $pdf->useTemplate($template,0,0,$size['width'],$size['height'],true);
            if ($anchor !== null && $customer !== null && $anchor['page'] === $i) {
                $this->stampCustomer($pdf, $anchor, $customer, $signedAt);
            }
        }

        $pdf->AddPage('P','A4');
        $pdf->SetMargins(20,20,20);
        $pdf->SetXY(20,20);
        $pdf->SetTextColor(18,37,54);
        $pdf->SetFont('Helvetica','B',17);
        $pdf->Cell(168,11,'Sign2Digital - Signature Record',0,1);
        $pdf->SetDrawColor(22,98,130);
        $pdf->SetLineWidth(0.5);
        $pdf->Line(20,34,188,34);
        $pdf->SetY(39);
        $this->text($pdf,'Document',self::label($documentType.' '.$quoteNumber));
        $this->text($pdf,'Sign2Digital document ID',$documentId);
        $this->text($pdf,'Signature request ID',$requestId);
        $this->text($pdf,'Approved document SHA-256',$sourceHash);
        $this->text($pdf,'Consent reference',$consentReference);
        $this->text($pdf,'Signed at (UTC)', $signedAt->format('Y-m-d H:i:s').' UTC');
        $pdf->Ln(2);

        $tempPaths = [];
        try {
            foreach ($signers as $index => $signer) {
                $pdf->SetFont('Helvetica','B',12);
                $pdf->SetX(20);
                $pdf->Cell(168,8,self::label(($index+1).'. '.$signer['role'].' signature'),0,1);
                $pdf->SetFont('Helvetica','',10);
                $pdf->SetX(20);
                $pdf->Cell(168,6,self::label('Name: '.$signer['name'].' | Method: '.$signer['method']),0,1);
                $top=$pdf->GetY()+2;
                if (isset($signer['png'])) {
                    self::validatePng($signer['png']);
                    $tmp=tempnam(sys_get_temp_dir(),'s2d_sig_');
                    if ($tmp === false || file_put_contents($tmp, $signer['png']) !== strlen($signer['png'])) {
                        throw new \RuntimeException('Signature image processing failed.');
                    }
                    @chmod($tmp,0600);
                    $tempPaths[]=$tmp;
                    $pdf->Image($tmp,22,$top,65,20,'PNG');
                } elseif (isset($signer['strokes'])) {
                    $pdf->SetDrawColor(18,37,54);
                    $pdf->SetLineWidth(0.55);
                    foreach ($signer['strokes'] as $stroke) {
                        $prior=null;
                        foreach ($stroke as $point) {
                            $xy=[22+((float)$point['x'])*65, $top+((float)$point['y'])*20];
                            if ($prior) $pdf->Line($prior[0],$prior[1],$xy[0],$xy[1]);
                            $prior=$xy;
                        }
                    }
                } else {
                    throw new \InvalidArgumentException('Missing signer evidence.');
                }
                $pdf->SetDrawColor(138,154,168);
                $pdf->Line(20,$top+23,90,$top+23);
                $pdf->SetY($top+30);
            }
            if ($pdf->GetY() > 278) throw new \RuntimeException('Signature record exceeds one page.');
            $pdf->SetY(278);
            $pdf->SetFont('Helvetica','',8);
            $pdf->MultiCell(168,4,'Evidence of electronic consent. This PDF is not a qualified cryptographic digital signature.');
            $result=$pdf->Output('S');
            if (!is_string($result) || !str_starts_with($result,'%PDF-') ||
                !str_contains(substr($result,-4096),'%%EOF')) {
                throw new \RuntimeException('Generated signed PDF failed validation.');
            }
            return $result;
        } finally {
            foreach ($tempPaths as $path) @unlink($path);
        }
    }
}
