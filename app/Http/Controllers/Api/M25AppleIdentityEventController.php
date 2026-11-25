<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AppleIdTokenVerifier;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
final class M25AppleIdentityEventController extends Controller {
 public function __invoke(Request $request,AppleIdTokenVerifier $verifier) {
  $data=$request->validate(['payload'=>['required','string','max:16384']]);
  $verified=$verifier->verifyEvent($data['payload']); $event=$verified['event'];
  DB::transaction(function()use($verified,$event,$request):void{
   if(!DB::table('m25_apple_identity_events')->insertOrIgnore(['event_id'=>$verified['id'],'type'=>$event['type'],'created_at'=>now()]))return;
   $user=User::where('apple_subject',$event['sub'])->lockForUpdate()->first();
   if(!$user)return;
   $credential=DB::table('m25_apple_credentials')->where('user_id',$user->id)->lockForUpdate()->first();
   if(in_array($event['type'],['consent-revoked','account-deleted'],true)
      && (!$credential || strtotime($credential->updated_at)<=$verified['issued_at'])){
    DB::table('m25_apple_credentials')->where('user_id',$user->id)->delete();
    $user->tokens()->delete();
    $user->forceFill(['remember_token'=>null])->save();
    if(config('session.driver')==='database') DB::connection(config('session.connection'))->table(config('session.table','sessions'))->where('user_id',$user->id)->delete();
    DB::table('m20_push_devices')->where('user_id',$user->id)->update(['enabled'=>false,'revoked_at'=>now(),'updated_at'=>now()]);
   }
   AuditLogger::record('apple.identity.'.$event['type'],$user,$user,metadata:['provider_event'=>$verified['id']],request:$request);
  },3);
  return response()->json(['received'=>true]);
 }
}
