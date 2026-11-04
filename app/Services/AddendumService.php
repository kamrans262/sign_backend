<?php

namespace App\Services;

use App\Models\Addendum;
use App\Models\SignedDocumentArtifact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * M10 authoritative money, source integrity and immutable addendum PDFs.
 * Never changes M7 approval PDFs or M8 signed quote artifact bytes.
 */
final class AddendumService
{
    public function __construct(private readonly SignatureCompletionService $completed) {}

    public function source(int $companyId, int $artifactId): SignedDocumentArtifact
    {
        $artifact = SignedDocumentArtifact::query()->where('company_id', $companyId)
            ->whereHas('request', fn ($query) => $query->where('status', 'signed'))
            ->with(['request.quote'])->findOrFail($artifactId);
        $quote = $artifact->request->quote;
        abort_unless($quote && (int)$quote->company_id === $companyId
            && (int)$quote->id === (int)$artifact->service_quote_id
            && $quote->status !== 'archived', 409,
            'A valid non-archived signed original is required.');
        // Mandatory integrity validation at addendum creation and approval.
        $this->completed->bytes($artifact);
        return $artifact;
    }

    public function scoped(int $companyId, int $id): Addendum
    {
        return Addendum::query()->where('company_id', $companyId)
            ->with('quote')->findOrFail($id);
    }

    public static function amounts(array $data): array
    {
        $work = trim((string)($data['additional_work'] ?? ''));
        $quantity = $data['quantity'] ?? null;
        $price = $data['unit_price_minor'] ?? null;
        $vat = (int)($data['vat_basis_points'] ?? 0);
        abort_unless($vat >= 0 && $vat <= 10000,422,'VAT must be between 0% and 100%.');
        if ($work === '' && ($quantity !== null || $price !== null)) {
            abort(422,'Describe the additional work before assigning a price.');
        }
        if ($work !== '' && ($quantity === null || $price === null ||
            (int)$quantity < 1 || (int)$quantity > 100000 ||
            (int)$price < 1 || (int)$price > 100000000000)) {
            abort(422,'All additional work requires quantity and a valid unit price.');
        }
        $subtotal = $work === '' ? 0 : (int)$quantity * (int)$price;
        abort_if($subtotal > 10000000000000, 422, 'Addendum total exceeds the supported range.');
        $tax = intdiv($subtotal * $vat + 5000, 10000);
        return [
            'additional_work' => $work === '' ? null : $work,
            'quantity' => $work === '' ? null : (int)$quantity,
            'unit_price_minor' => $work === '' ? null : (int)$price,
            'vat_basis_points' => $vat,
            'subtotal_minor' => $subtotal,
            'vat_minor' => $tax,
            'total_minor' => $subtotal + $tax,
        ];
    }

    public function payload(Addendum $item): array
    {
        return [
            'id'=>$item->id, 'company_id'=>$item->company_id,
            'source_document_id'=>'signed-'.$item->source_signed_artifact_id,
            'source_signed_artifact_id'=>$item->source_signed_artifact_id,
            'source_pdf_sha256'=>$item->source_pdf_sha256,
            'source_revision'=>$item->source_revision,
            'quote_id'=>$item->service_quote_id,
            'job_id'=>$item->service_job_id,'client_id'=>$item->client_id,
            'number'=>$item->number,'title'=>$item->title,
            'change_description'=>$item->change_description,
            'effective_date'=>$item->effective_date?->format('Y-m-d'),
            'additional_work'=>$item->additional_work ?? '',
            'quantity'=>$item->quantity,
            'unit_price_minor'=>$item->unit_price_minor,
            'vat_basis_points'=>$item->vat_basis_points,
            'subtotal_minor'=>$item->subtotal_minor,'vat_minor'=>$item->vat_minor,
            'total_minor'=>$item->total_minor,'currency'=>$item->currency,
            'status'=>$item->status,'revision'=>$item->revision,
            'has_approved_pdf'=>$item->approved_pdf_sha256 !== null,
            'approved_pdf_sha256'=>$item->approved_pdf_sha256,
            'approved_revision'=>$item->approved_revision,
            'has_signed_pdf'=>$item->signed_pdf_sha256 !== null,
            'signed_pdf_sha256'=>$item->signed_pdf_sha256,
            'approved_at'=>$item->approved_at?->toIso8601String(),
            'signed_at'=>$item->signed_at?->toIso8601String(),
            'created_at'=>$item->created_at?->toIso8601String(),
            'updated_at'=>$item->updated_at?->toIso8601String(),
        ];
    }

    public function event(Addendum $item, string $type, ?int $actor = null, array $meta = []): void
    {
        DB::table('addendum_events')->insert([
            'addendum_id'=>$item->id,'company_id'=>$item->company_id,
            'actor_user_id'=>$actor,'event_type'=>$type,
            'metadata'=>json_encode($meta, JSON_THROW_ON_ERROR),
            'created_at'=>now(),
        ]);
    }

    public function revision(Addendum $item, ?int $actor): void
    {
        DB::table('addendum_revisions')->insert([
            'addendum_id'=>$item->id,'company_id'=>$item->company_id,
            'revision'=>$item->revision,
            'snapshot'=>json_encode($this->payload($item),JSON_THROW_ON_ERROR),
            'actor_user_id'=>$actor,'created_at'=>now(),
        ]);
    }

    private static function text(string $value): string
    {
        $converted = iconv('UTF-8','Windows-1252//TRANSLIT//IGNORE',
            mb_substr(trim($value),0,1000));
        return preg_replace('/[\x00-\x1F\x7F]/',' ',(string)$converted) ?? '';
    }

    public function createPdf(Addendum $item): string
    {
        abort_unless(in_array($item->status,['draft','approved'],true),409,
            'Signed and issued addenda cannot be rewritten.');
        $source = $this->source($item->company_id,$item->source_signed_artifact_id);
        abort_unless(hash_equals($item->source_pdf_sha256,$source->signed_pdf_sha256)
            && (int)$item->source_revision === (int)$source->source_revision,409,
            'The original signed document has changed.');
        $quote = $source->request->quote;
        $pdf = new Fpdi();
        $pdf->SetAutoPageBreak(true,20);
        $pdf->AddPage('P','A4');
        $pdf->SetMargins(20,20,20);
        $pdf->SetTextColor(18,37,54);
        $pdf->SetFont('Helvetica','B',19);
        $pdf->MultiCell(170,11,'Sign2Digital - Addendum '.self::text($item->number));
        $pdf->SetTextColor(22,98,130);
        $pdf->SetFont('Helvetica','B',10);
        $pdf->MultiCell(170,7,'SEPARATE CHANGE DOCUMENT - CUSTOMER SIGNATURE REQUIRED');
        $pdf->Ln(7);
        $fields = [
            'Original signed quote'=> $quote->custom_quote_number ?: $quote->reference_number,
            'Source signed document'=> 'signed-'.$item->source_signed_artifact_id,
            'Original signed SHA-256'=> $item->source_pdf_sha256,
            'Addendum revision'=> (string)$item->revision,
            'Customer'=> $quote->client_name,
            'Addendum'=> $item->title,
            'Effective date'=> $item->effective_date?->format('Y-m-d') ?? '',
            'Change description'=> $item->change_description,
            'Additional work'=> $item->additional_work ?? 'No priced additional work',
            'Quantity'=> $item->quantity === null ? 'Not applicable' : (string)$item->quantity,
            'Unit price'=> $item->unit_price_minor === null ? 'Not applicable' :
                $item->currency.' '.number_format($item->unit_price_minor / 100,2,'.',''),
            'Subtotal'=> $item->currency.' '.number_format($item->subtotal_minor / 100,2,'.',''),
            'VAT'=> number_format($item->vat_basis_points / 100,2,'.','').'%',
            'VAT amount'=> $item->currency.' '.number_format($item->vat_minor / 100,2,'.',''),
            'Total'=> $item->currency.' '.number_format($item->total_minor / 100,2,'.',''),
        ];
        foreach ($fields as $label=>$value) {
            $pdf->SetFont('Helvetica','B',10);
            $pdf->MultiCell(170,5,self::text($label).':');
            $pdf->SetFont('Helvetica','',10);
            $pdf->MultiCell(170,6,self::text((string)$value));
            $pdf->Ln(3);
        }
        $pdf->Ln(6);
        $pdf->SetFont('Helvetica','',9);
        $pdf->MultiCell(170,6,'The original signed quote remains unchanged. This separate addendum is not accepted until signed by the customer.');
        $bytes = $pdf->Output('S');
        if (!is_string($bytes) || !str_starts_with($bytes,'%PDF-') ||
            !str_contains(substr($bytes,-4096),'%%EOF') ||
            strlen($bytes) > 5 * 1024 * 1024) {
            throw new HttpException(503,'Unable to create a valid addendum PDF.');
        }
        return $bytes;
    }

    public function approvedBytes(Addendum $item): string
    {
        abort_unless(in_array($item->status,['approved','sent','signed'],true)
            && $item->approved_pdf_path && $item->approved_pdf_sha256
            && (int)$item->approved_revision === (int)$item->revision,409,
            'An approved immutable addendum PDF is required.');
        return $this->verified($item->approved_pdf_path,$item->approved_pdf_sha256);
    }

    public function signedBytes(Addendum $item): string
    {
        abort_unless($item->status==='signed' && $item->signed_pdf_path
            && $item->signed_pdf_sha256,409,'Addendum has not been signed.');
        return $this->verified($item->signed_pdf_path,$item->signed_pdf_sha256);
    }

    private function verified(string $path, string $sha): string
    {
        $disk=Storage::disk('local');
        abort_unless($disk->exists($path),503,'The private PDF is unavailable.');
        $bytes=$disk->get($path);
        abort_unless(is_string($bytes) && strlen($bytes) >= 12 &&
            strlen($bytes) <= 5*1024*1024 && str_starts_with($bytes,'%PDF-') &&
            str_contains(substr($bytes,-4096),'%%EOF') &&
            hash_equals($sha,hash('sha256',$bytes)),503,
            'Private addendum PDF integrity validation failed.');
        return $bytes;
    }

    public function history(Addendum $item): array
    {
        return DB::table('addendum_events')->where('company_id',$item->company_id)
            ->where('addendum_id',$item->id)->orderByDesc('id')
            ->get(['id','event_type as type','actor_user_id',
                'metadata','created_at as occurred_at'])->all();
    }

    public static function freshToken(): string
    {
        return SignatureWorkflowService::freshToken();
    }
}
