<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Services\AccountErasureService;
use App\Services\AppleIdTokenVerifier;
use App\Services\M25AppleIdentity;
use App\Services\M24TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
final class M25DeletionController extends Controller {
    private function hash(int $id,string $email,string $code): string {
        return hash_hmac('sha256',$id.'|'.strtolower(trim($email)).'|'.$code,(string)config('app.key'));
    }
    public function options(Request $request) {
        $user=$request->user();
        $companies=$user->ownedCompanies()->get(['id','name'])->map(function($c)use($user){
            $members=DB::table('company_user as m')->join('users as u','u.id','=','m.user_id')
                ->where('m.company_id',$c->id)->where('m.user_id','!=',$user->id)->where('m.status','active');
            return ['id'=>$c->id,'name'=>$c->name,'can_close'=>!(clone $members)->exists(),
                'successors'=>$members->where('m.role','admin')->where('u.is_active',true)->whereNotNull('u.email_verified_at')->get(['u.id','u.name'])];
        });
        return response()->json(['data'=>['companies'=>$companies,'apple_linked'=>!empty($user->apple_subject),
            'platform_admin'=>(bool)$user->is_platform_admin,'two_factor_required'=>app(M24TwoFactorService::class)->enabled($user->id)]],200,['Cache-Control'=>'private, no-store']);
    }
    public function challenge(Request $request) {
        $user=$request->user();
        abort_unless($user->email_verified_at,422,'A verified email is required.');
        $prior=DB::table('m25_deletion_challenges')->where('user_id',$user->id)->first();
        if($prior && now()->addMinutes(9)->lessThan($prior->expires_at)) abort(429,'Please wait one minute.');
        $code=(string)random_int(100000,999999);
        DB::table('m25_deletion_challenges')->updateOrInsert(['user_id'=>$user->id],[
            'proof_hash'=>$this->hash($user->id,$user->email,$code),'expires_at'=>now()->addMinutes(10),'attempts'=>0,
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
        $data=$request->validate([
            'code'=>['required_without:apple_id_token','nullable','regex:/^[0-9]{6}$/D'],
            'apple_id_token'=>['nullable','string','max:8192'],
            'apple_nonce'=>['required_with:apple_id_token','nullable','regex:/^[a-f0-9]{64}$/D'],
            'apple_authorization_code'=>['nullable','string','max:4096'],
            'confirmation'=>['required','in:DELETE'],'two_factor_code'=>['nullable','string','max:32'],
            'close_sole_owned'=>['sometimes','boolean'],'ownership_transfers'=>['sometimes','array','max:100'],
            'ownership_transfers.*'=>['integer','min:1'],
        ]);
        $user=$request->user();
        if(!empty($data['apple_id_token'])) {
            $identity=app(AppleIdTokenVerifier::class)->verify($data['apple_id_token'],$data['apple_nonce']);
            if(!$user->apple_subject||!hash_equals($user->apple_subject,$identity['sub'])||
                DB::table('apple_sign_in_challenges')->where('nonce_hash',hash('sha256',$data['apple_nonce']))->where('expires_at','>',now())->delete()!==1)
                throw ValidationException::withMessages(['apple'=>'Apple identity could not be confirmed.']);
            $provider=app(M25AppleIdentity::class);
            if($provider->configured()&&!empty($data['apple_authorization_code'])) {
                try {
                    $provider->store($user->id,$provider->exchange($data['apple_authorization_code'],$identity['sub']));
                } catch (ValidationException) {
                    // Verified identity is sufficient to erase the local account. A failed exchange must not prevent deletion.
                    // An existing encrypted refresh token can still be revoked; otherwise return manual revocation guidance.
                }
            }
            $ok=true;
        } else {
        $ok=DB::transaction(function()use($user,$data):bool{
            $row=DB::table('m25_deletion_challenges')->where('user_id',$user->id)->lockForUpdate()->first();
            if(!$row||$row->attempts>=5||now()->greaterThanOrEqualTo($row->expires_at))return false;
            DB::table('m25_deletion_challenges')->where('user_id',$user->id)->increment('attempts');
            if(!hash_equals($row->proof_hash,$this->hash($user->id,$user->email,$data['code'])))return false;
            DB::table('m25_deletion_challenges')->where('user_id',$user->id)->delete();
            return true;
        },3);
        }
        if(!$ok)throw ValidationException::withMessages(['code'=>'Invalid or expired verification code.']);
        $twoFactor->confirmDestructiveAction($user,$data['two_factor_code']??null);
        $manualAppleRevocation = !empty($user->apple_subject) && !DB::table('m25_apple_credentials')->where('user_id',$user->id)->exists();
        $eraser->erase($user,$request,closeSoleOwned:(bool)($data['close_sole_owned']??false),transfers:$data['ownership_transfers']??[]);
        return response()->json(['data'=>['deleted'=>true,'requires_login'=>true,'business_records_retained'=>true,'manual_apple_revocation'=>$manualAppleRevocation]],200,['Cache-Control'=>'private, no-store']);
    }
}
