<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Services\AccountErasureService;
use App\Services\M24TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
final class M25DeletionController extends Controller {
    private function hash(int $id,string $code): string {
        return hash_hmac('sha256',$id.'|'.$code,(string)config('app.key'));
    }
    public function challenge(Request $request) {
        $user=$request->user();
        abort_unless($user->email_verified_at,422,'A verified email is required.');
        $prior=DB::table('m25_deletion_challenges')->where('user_id',$user->id)->first();
        if($prior && now()->addMinutes(9)->lessThan($prior->expires_at)) abort(429,'Please wait one minute.');
        $code=(string)random_int(100000,999999);
        DB::table('m25_deletion_challenges')->updateOrInsert(['user_id'=>$user->id],[
            'proof_hash'=>$this->hash($user->id,$code),'expires_at'=>now()->addMinutes(10),'attempts'=>0,
        ]);
        try {
            Mail::raw('Your Sign2Digital account deletion verification code is '.$code.'. It expires in 10 minutes.',fn($m)=>$m->to($user->email)->subject('Confirm Sign2Digital account deletion'));
        } catch(\Throwable) {
            DB::table('m25_deletion_challenges')->where('user_id',$user->id)->delete();
            abort(503,'Verification email could not be sent.');
        }
        return response()->json(['data'=>['sent'=>true,'two_factor_required'=>app(M24TwoFactorService::class)->enabled($user->id)]],200,['Cache-Control'=>'private, no-store']);
    }
    public function complete(Request $request,AccountErasureService $eraser,M24TwoFactorService $twoFactor) {
        $data=$request->validate(['code'=>['required','regex:/^[0-9]{6}$/D'],'confirmation'=>['required','in:DELETE'],'two_factor_code'=>['nullable','string','max:32']]);
        $user=$request->user();
        $ok=DB::transaction(function()use($user,$data):bool{
            $row=DB::table('m25_deletion_challenges')->where('user_id',$user->id)->lockForUpdate()->first();
            if(!$row||$row->attempts>=5||now()->greaterThanOrEqualTo($row->expires_at))return false;
            DB::table('m25_deletion_challenges')->where('user_id',$user->id)->increment('attempts');
            if(!hash_equals($row->proof_hash,$this->hash($user->id,$data['code'])))return false;
            DB::table('m25_deletion_challenges')->where('user_id',$user->id)->delete();
            return true;
        },3);
        if(!$ok)throw ValidationException::withMessages(['code'=>'Invalid or expired verification code.']);
        $twoFactor->confirmDestructiveAction($user,$data['two_factor_code']??null);
        $eraser->erase($user,$request);
        return response()->json(['data'=>['deleted'=>true,'requires_login'=>true,'business_records_retained'=>true]],200,['Cache-Control'=>'private, no-store']);
    }
}
