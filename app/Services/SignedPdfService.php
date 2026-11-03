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
     * @param array<int,array{role:string,name:string,method:string,png?:string,strokes?:array}> $signers
     */
    public function build(string $approvedBytes, string $quoteNumber, string $sourceHash,
        string $documentId, string $requestId, \DateTimeInterface $signedAt,
        array $signers, string $consentReference): string
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
        $pdf->SetTitle(self::label('Sign2Digital Signed Quote '.$quoteNumber));
        $pdf->SetAuthor('Sign2Digital');

        // Parser errors propagate. Never generate a new quote layout from
        // unverified structured data when the frozen source is unreadable.
        $source = StreamReader::createByString($approvedBytes);
        $count = $pdf->setSourceFile($source);
        if ($count < 1 || $count > 100) {
            throw new \RuntimeException('The approved PDF has an unsupported page count.');
        }
        for ($i=1;$i<=$count;$i++) {
            $template = $pdf->importPage($i);
            $size = $pdf->getTemplateSize($template);
            $pdf->AddPage($size['orientation'], [$size['width'],$size['height']]);
            $pdf->useTemplate($template,0,0,$size['width'],$size['height'],true);
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
        $this->text($pdf,'Document','Quote '.self::label($quoteNumber));
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
