<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class M22SupportAdminController extends Controller
{
    public function index(Request $request): View
    {
        $f=$request->validate([
            'q'=>['nullable','string','max:120'],
            'status'=>['nullable',Rule::in(['open','in_progress','waiting_for_customer','resolved','closed'])],
            'priority'=>['nullable',Rule::in(['low','normal','high','urgent'])],
            'company_id'=>['nullable','integer','exists:companies,id'],
            'user_id'=>['nullable','integer','exists:users,id'],
        ]);
        $q=DB::table('m22_support_tickets as t')
            ->join('users as u','u.id','=','t.user_id')
            ->leftJoin('companies as c','c.id','=','t.company_id')
            ->select('t.*','u.name as owner_name','u.email as owner_email','c.name as company_name');
        if (!empty($f['q'])) {
            $needle='%'.addcslashes($f['q'],'%_').'%';
            $q->where(fn($b)=>$b->where('t.reference','like',$needle)
                ->orWhere('t.subject','like',$needle)
                ->orWhere('u.email','like',$needle));
        }
        if (!empty($f['status'])) $q->where('t.status',$f['status']);
        if (!empty($f['priority'])) $q->where('t.priority',$f['priority']);
        if (!empty($f['company_id'])) $q->where('t.company_id',$f['company_id']);
        if (!empty($f['user_id'])) $q->where('t.user_id',$f['user_id']);
        return view('admin.support.index',[
            'tickets'=>$q->orderByDesc('t.id')->paginate(20)->withQueryString(),
            'filters'=>$f,
            'companies'=>DB::table('companies')->orderBy('name')->get(['id','name']),
        ]);
    }

    public function show(int $ticket): View
    {
        $item=DB::table('m22_support_tickets as t')
            ->join('users as u','u.id','=','t.user_id')
            ->leftJoin('companies as c','c.id','=','t.company_id')
            ->where('t.id',$ticket)
            ->select('t.*','u.name as owner_name','u.email as owner_email','c.name as company_name')
            ->first();
        abort_unless($item,404);
        $replies=DB::table('m22_support_replies as r')
            ->join('users as u','u.id','=','r.author_user_id')
            ->where('ticket_id',$ticket)->orderBy('r.id')
            ->get(['r.*','u.name as author_name']);
        $attachments=DB::table('m22_support_attachments')->where('ticket_id',$ticket)
            ->orderBy('id')->get();
        $admins=User::query()->where('is_platform_admin',true)
            ->where('is_active',true)->orderBy('name')->get(['id','name']);
        return view('admin.support.show',compact('item','replies','attachments','admins'));
    }

    public function update(Request $request,int $ticket): RedirectResponse
    {
        $data=$request->validate([
            'status'=>['required',Rule::in(['open','in_progress','waiting_for_customer','resolved','closed'])],
            'priority'=>['required',Rule::in(['low','normal','high','urgent'])],
            'assigned_admin_id'=>['nullable','integer','exists:users,id'],
        ]);
        if (isset($data['assigned_admin_id'])) {
            abort_unless(User::whereKey($data['assigned_admin_id'])
                ->where('is_active',true)->where('is_platform_admin',true)->exists(),422);
        }
        $row=DB::table('m22_support_tickets')->find($ticket);
        abort_unless($row,404);
        DB::table('m22_support_tickets')->where('id',$ticket)->update([
            'status'=>$data['status'],'priority'=>$data['priority'],
            'assigned_admin_id'=>$data['assigned_admin_id']??null,
            'updated_at'=>now(),
        ]);
        AuditLogger::record('admin.support.ticket_updated',$request->user(),null,
            metadata:['ticket_id'=>$ticket,'status'=>$data['status'],
                'priority'=>$data['priority']],request:$request);
        return back()->with('success','Support ticket updated.');
    }

    public function reply(Request $request,int $ticket): RedirectResponse
    {
        DB::table('m22_support_tickets')->find($ticket) ?: abort(404);
        $data=$request->validate([
            'body'=>['required','string','min:1','max:10000'],
            'internal'=>['nullable','boolean'],
        ]);
        $internal=$request->boolean('internal');
        DB::transaction(function() use($request,$ticket,$data,$internal): void {
            DB::table('m22_support_replies')->insert([
                'ticket_id'=>$ticket,'author_user_id'=>$request->user()->id,
                'body'=>trim($data['body']),'internal'=>$internal,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            if (!$internal) DB::table('m22_support_tickets')->where('id',$ticket)
                ->update(['status'=>'waiting_for_customer','updated_at'=>now()]);
            AuditLogger::record($internal?'admin.support.private_note':'admin.support.reply',
                $request->user(),null,metadata:['ticket_id'=>$ticket],request:$request);
        });
        return back()->with('success',$internal?'Private note saved.':'Reply saved.');
    }

    public function attachment(int $ticket,int $attachment)
    {
        DB::table('m22_support_tickets')->find($ticket) ?: abort(404);
        $item=DB::table('m22_support_attachments')->where('ticket_id',$ticket)
            ->where('id',$attachment)->first();
        abort_unless($item && Storage::disk('local')->exists($item->private_path),404);
        $ext=match($item->mime_type){'application/pdf'=>'pdf','image/png'=>'png',default=>'jpg'};
        return response(Storage::disk('local')->get($item->private_path),200,[
            'Content-Type'=>$item->mime_type,
            'Content-Disposition'=>'attachment; filename="Support_'.$attachment.'.'.$ext.'"',
            'Cache-Control'=>'private, no-store',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }

    public function faqs(Request $request): View
    {
        $query=DB::table('m22_support_faqs')->orderBy('locale')->orderBy('sort_order')->orderByDesc('id');
        return view('admin.support.faqs',['faqs'=>$query->paginate(30)->withQueryString()]);
    }

    public function editFaq(?int $faq=null): View
    {
        $item=$faq?DB::table('m22_support_faqs')->find($faq):null;
        if ($faq!==null) abort_unless($item,404);
        return view('admin.support.faq-form',['item'=>$item]);
    }

    private function faqData(Request $request): array
    {
        return $request->validate([
            'locale'=>['required',Rule::in(['en','fr','de','it'])],
            'category'=>['required','string','max:60','regex:/^[a-z0-9_-]+$/'],
            'question'=>['required','string','max:250'],
            'answer'=>['required','string','max:10000'],
            'sort_order'=>['required','integer','between:0,100000'],
            'is_published'=>['nullable','boolean'],
        ]);
    }

    public function saveFaq(Request $request,?int $faq=null): RedirectResponse
    {
        if ($faq!==null) abort_unless(DB::table('m22_support_faqs')->where('id',$faq)->exists(),404);
        $data=$this->faqData($request);
        $row=[
            'locale'=>$data['locale'],'category'=>$data['category'],
            'question'=>trim($data['question']),'answer'=>trim($data['answer']),
            'sort_order'=>$data['sort_order'],'is_published'=>$request->boolean('is_published'),
            'updated_by_user_id'=>$request->user()->id,'updated_at'=>now(),
        ];
        if ($faq===null) {
            $row['created_at']=now();
            $id=DB::table('m22_support_faqs')->insertGetId($row);
        } else {
            $id=$faq;DB::table('m22_support_faqs')->where('id',$faq)->update($row);
        }
        AuditLogger::record('admin.support.faq_saved',$request->user(),null,
            metadata:['faq_id'=>$id,'published'=>$row['is_published']],request:$request);
        return redirect()->route('admin.support.faqs')->with('success','FAQ saved.');
    }

    public function deleteFaq(Request $request,int $faq): RedirectResponse
    {
        abort_unless(DB::table('m22_support_faqs')->where('id',$faq)->delete(),404);
        AuditLogger::record('admin.support.faq_deleted',$request->user(),null,
            metadata:['faq_id'=>$faq],request:$request);
        return redirect()->route('admin.support.faqs')->with('success','FAQ removed.');
    }
}
