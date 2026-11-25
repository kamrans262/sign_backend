<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Http\Middleware\M25AiConsent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
final class M25PrivacyController extends Controller {
    public function show(Request $request) {
        return response()->json(['data'=>['version'=>M25AiConsent::VERSION,'allowed'=>M25AiConsent::allowed($request->user()->id)]],200,['Cache-Control'=>'private, no-store']);
    }
    public function update(Request $request) {
        $data=$request->validate(['allowed'=>['required','boolean'],'version'=>['required','in:'.M25AiConsent::VERSION]]);
        DB::table('m25_ai_consents')->updateOrInsert(['user_id'=>$request->user()->id],[
            'version'=>M25AiConsent::VERSION,'allowed'=>$data['allowed'],'created_at'=>now(),'updated_at'=>now(),
        ]);
        return $this->show($request);
    }
}
