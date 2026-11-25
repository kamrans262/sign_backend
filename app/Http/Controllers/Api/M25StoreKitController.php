<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\M25StoreKit;
use Illuminate\Http\Request;
final class M25StoreKitController extends Controller{
    public function __construct(private M25StoreKit $store){}
    private function company(Request $request):Company{
        $company=$request->attributes->get('company');abort_unless($company instanceof Company,403);return $company;
    }
    private function response(array $data){return response()->json(['data'=>$data],200,['Cache-Control'=>'private, no-store']);}
    public function catalog(Request $r){return $this->response($this->store->catalog($this->company($r),$r->user()->id));}
    public function begin(Request $r){return $this->response($this->store->begin($this->company($r),$r->user()->id));}
    public function cancelPending(Request $r){return $this->response($this->store->cancelPending($this->company($r),$r->user()->id));}
    public function verify(Request $r){
        $d=$r->validate(['transaction_id'=>['required','string','regex:/^[0-9]{1,64}$/D']]);
        return $this->response($this->store->sync($this->company($r),$d['transaction_id'],$r->user()->id));
    }
    public function notify(Request $r){
        abort_unless($this->store->ready(),503);
        $d=$r->validate(['signedPayload'=>['required','string','max:65536']]);
        try{$this->store->notification($d['signedPayload']);}catch(\RuntimeException){abort(400,'Invalid Apple notification.');}
        return response()->json(['received'=>true]);
    }
}
