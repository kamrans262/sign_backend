<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class M22SupportController extends Controller
{
    private function owned(Request $request, int $id): object
    {
        return DB::table('m22_support_tickets')
            ->where('user_id', $request->user()->id)->where('id', $id)->firstOrFail();
    }

    private function payload(object $ticket): array
    {
        return [
            'id' => (int) $ticket->id,
            'reference' => $ticket->reference,
            'category' => $ticket->category,
            'subject' => $ticket->subject,
            'description' => $ticket->description,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'company_id' => $ticket->company_id === null ? null : (int) $ticket->company_id,
            'created_at' => $ticket->created_at,
            'updated_at' => $ticket->updated_at,
        ];
    }

    public function faqs(Request $request): JsonResponse
    {
        $locale = $request->validate([
            'locale' => ['nullable', Rule::in(['en','fr','de','it'])],
            'category' => ['nullable','string','max:60'],
        ]);
        $items = DB::table('m22_support_faqs')->where('is_published', true)
            ->where('locale', $locale['locale'] ?? 'en');
        if (isset($locale['category'])) $items->where('category', $locale['category']);
        $rows = $items->orderBy('sort_order')->orderBy('id')->limit(100)
            ->get(['id','locale','category','question','answer'])->all();
        return response()->json(['data' => ['records' => $rows]],
            200, ['Cache-Control' => 'private, no-store']);
    }

    public function index(Request $request): JsonResponse
    {
        $filter = $request->validate([
            'status' => ['nullable', Rule::in(['open','in_progress','waiting_for_customer','resolved','closed'])],
            'page' => ['nullable','integer','between:1,10000'],
            'per_page' => ['nullable','integer','between:1,100'],
        ]);
        $query = DB::table('m22_support_tickets')
            ->where('user_id',$request->user()->id);
        if (isset($filter['status'])) $query->where('status',$filter['status']);
        $page = $query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($filter['per_page'] ?? 20);
        return response()->json(['data' => [
            'records' => $page->getCollection()->map(
                fn ($ticket) => $this->payload($ticket))->all(),
            'pagination' => [
                'page'=>$page->currentPage(),'total'=>$page->total(),
                'last_page'=>$page->lastPage(),'has_more'=>$page->hasMorePages(),
            ],
        ]], 200, ['Cache-Control'=>'private, no-store']);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['nullable','integer','min:1'],
            'category' => ['required', Rule::in(['general','technical','bug','billing','account','feedback'])],
            'subject' => ['required','string','min:3','max:180'],
            'description' => ['required','string','min:10','max:10000'],
            'app_version' => ['nullable','string','max:48'],
        ]);
        $user = $request->user();
        if (isset($data['company_id'])) {
            $company = Company::where('status','active')->find($data['company_id']);
            abort_unless($company && $company->users()->where('users.id',$user->id)
                ->wherePivot('status','active')->exists(), 403);
        }
        $ticket = DB::transaction(function () use ($user,$data,$request): object {
            $id = DB::table('m22_support_tickets')->insertGetId([
                'reference' => 'S2D-'.strtoupper(Str::random(12)),
                'user_id' => $user->id, 'company_id' => $data['company_id'] ?? null,
                'category' => $data['category'], 'subject' => trim($data['subject']),
                'description' => trim($data['description']), 'status' => 'open',
                'priority' => 'normal', 'app_version' => $data['app_version'] ?? null,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            AuditLogger::record('mobile.support.ticket_created',$user,$user,
                metadata:['ticket_id'=>$id],request:$request);
            return DB::table('m22_support_tickets')->find($id);
        });
        return response()->json(['data'=>['ticket'=>$this->payload($ticket)]],201,
            ['Cache-Control'=>'private, no-store']);
    }

    public function show(Request $request, int $ticket): JsonResponse
    {
        $row = $this->owned($request,$ticket);
        $messages = DB::table('m22_support_replies')->where('ticket_id',$ticket)
            ->where('internal',false)->orderBy('id')
            ->get(['id','author_user_id','body','created_at'])->all();
        $attachments = DB::table('m22_support_attachments')->where('ticket_id',$ticket)
            ->orderBy('id')->get(['id','original_name','mime_type','byte_size','reply_id'])->all();
        return response()->json(['data'=>[
            'ticket'=>$this->payload($row),
            'replies'=>$messages,'attachments'=>$attachments,
        ]],200,['Cache-Control'=>'private, no-store']);
    }

    public function reply(Request $request, int $ticket): JsonResponse
    {
        $data = $request->validate(['body'=>['required','string','min:1','max:10000']]);
        // Lock ticket so an administrator cannot close it during reply creation.
        DB::transaction(function () use ($ticket, $request, $data): void {
            $row = DB::table('m22_support_tickets')
                ->where('user_id', $request->user()->id)
                ->where('id', $ticket)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_if($row->status === 'closed', 409, 'Closed ticket cannot be changed.');
            DB::table('m22_support_replies')->insert([
                'ticket_id'=>$ticket, 'author_user_id'=>$request->user()->id,
                'body'=>trim($data['body']), 'internal'=>false,
                'created_at'=>now(), 'updated_at'=>now(),
            ]);
            DB::table('m22_support_tickets')->where('id', $ticket)->update([
                'status'=>'open', 'updated_at'=>now(),
            ]);
            AuditLogger::record('mobile.support.ticket_replied', $request->user(),
                $request->user(), metadata:['ticket_id'=>$ticket], request:$request);
        });
        return $this->show($request, $ticket);
    }

    private function validateAttachment(Request $request): array
    {
        $request->validate(['file'=>['required','file','max:5120']]);
        $file=$request->file('file');
        $mime=$file->getMimeType();
        if (!in_array($mime,['image/jpeg','image/png','application/pdf'],true)) {
            throw ValidationException::withMessages(['file'=>'Only PDF, PNG or JPEG attachments are supported.']);
        }
        $bytes=file_get_contents($file->getRealPath());
        $valid = is_string($bytes) && strlen($bytes)>8 && (
            ($mime==='application/pdf' && str_starts_with($bytes,'%PDF-') &&
                str_contains(substr($bytes,-4096),'%%EOF')) ||
            ($mime==='image/png' && str_starts_with($bytes,"\x89PNG\r\n\x1a\n") &&
                getimagesizefromstring($bytes)!==false) ||
            ($mime==='image/jpeg' && str_starts_with($bytes,"\xFF\xD8") &&
                str_ends_with($bytes,"\xFF\xD9") && getimagesizefromstring($bytes)!==false)
        );
        if (!$valid) throw ValidationException::withMessages(['file'=>'Invalid attachment content.']);
        return [$file,$mime,$bytes];
    }

    public function upload(Request $request, int $ticket): JsonResponse
    {
        $this->owned($request, $ticket);
        [$file, $mime, $bytes] = $this->validateAttachment($request);
        $extension = match ($mime) {'application/pdf'=>'pdf', 'image/png'=>'png', default=>'jpg'};
        $path = 'm22/support/'.$ticket.'/'.Str::uuid().'.'.$extension;
        if (!Storage::disk('local')->put($path, $bytes)) abort(503);
        try {
            $id = DB::transaction(function () use ($request, $ticket, $path, $mime, $file, $bytes): int {
                // Recheck the status and owner at the write boundary.
                $row = DB::table('m22_support_tickets')
                    ->where('user_id', $request->user()->id)
                    ->where('id', $ticket)->lockForUpdate()->first();
                abort_unless($row, 404);
                abort_if($row->status === 'closed', 409, 'Closed ticket cannot accept attachments.');
                return DB::table('m22_support_attachments')->insertGetId([
                    'ticket_id'=>$ticket, 'reply_id'=>null,
                    'uploaded_by_user_id'=>$request->user()->id,
                    'private_path'=>$path, 'mime_type'=>$mime,
                    'original_name'=>Str::limit(basename($file->getClientOriginalName()),160,''),
                    'byte_size'=>strlen($bytes), 'sha256'=>hash('sha256',$bytes),
                    'created_at'=>now(), 'updated_at'=>now(),
                ]);
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
        AuditLogger::record('mobile.support.attachment_uploaded', $request->user(),
            $request->user(), metadata:['ticket_id'=>$ticket,'attachment_id'=>$id],
            request:$request);
        return response()->json(['data'=>['id'=>$id]], 201);
    }

    public function attachment(Request $request,int $ticket,int $attachment)
    {
        $this->owned($request,$ticket);
        $a=DB::table('m22_support_attachments')->where('ticket_id',$ticket)
            ->where('id',$attachment)->firstOrFail();
        abort_unless(Storage::disk('local')->exists($a->private_path),404);
        return response(Storage::disk('local')->get($a->private_path),200,[
            'Content-Type'=>$a->mime_type,
            'Content-Disposition'=>'attachment; filename="Sign2Digital_Support_'.$attachment.'.'.
                (match($a->mime_type){'application/pdf'=>'pdf','image/png'=>'png',default=>'jpg'}).'"',
            'Cache-Control'=>'private, no-store',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }
}
