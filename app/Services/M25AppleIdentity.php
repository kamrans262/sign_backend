<?php
namespace App\Services;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
final class M25AppleIdentity {
    public function configured(): bool {
        return config('m25_apple.signin_key_id')!=='' && is_readable((string)config('m25_apple.signin_key_path'));
    }
    private function secret(): string {
        return M25AppleJwt::sign(config('m25_apple.signin_key_id'),config('m25_apple.signin_key_path'),[
            'iss'=>config('m25_apple.team'),'sub'=>config('m25_apple.bundle'),'aud'=>'https://appleid.apple.com','iat'=>time(),'exp'=>time()+300,
        ]);
    }
    public function exchange(string $code,string $subject): string {
        try {
            $response=Http::asForm()->timeout(12)->withOptions(['allow_redirects'=>false])->post('https://appleid.apple.com/auth/token',[
                'client_id'=>config('m25_apple.bundle'),'client_secret'=>$this->secret(),'grant_type'=>'authorization_code','code'=>$code,
            ]);
            if(!$response->successful()) throw new \RuntimeException('Apple rejected authorization.');
            // This response is obtained directly from Apple's authenticated HTTPS endpoint,
            // never from the mobile client. Bind the exchanged identity to the verified subject.
            $claims=M25AppleJwt::claims((string)$response->json('id_token'));
            $refresh=$response->json('refresh_token');
            if(($claims['sub']??null)!==$subject || ($claims['aud']??null)!==config('m25_apple.bundle') ||
                ($claims['iss']??null)!=='https://appleid.apple.com' || !is_string($refresh)||$refresh==='') throw new \RuntimeException('Identity mismatch.');
            return Crypt::encryptString($refresh);
        } catch(\Throwable) {
            throw ValidationException::withMessages(['apple'=>'Apple authorization could not be completed. Please try again.']);
        }
    }
    public function store(int $user,string $cipher): void {
        DB::table('m25_apple_credentials')->updateOrInsert(['user_id'=>$user],['refresh_token'=>$cipher,'created_at'=>now(),'updated_at'=>now()]);
    }
    public function queueErasure(int $user): void {
        $row=DB::table('m25_apple_credentials')->where('user_id',$user)->lockForUpdate()->first();
        if($row) {
            DB::table('m25_apple_revocations')->insert(['refresh_token'=>$row->refresh_token,'created_at'=>now(),'updated_at'=>now()]);
            DB::table('m25_apple_credentials')->where('user_id',$user)->delete();
        }
    }
    public function retry(int $max=20): int {
        if(!$this->configured()) return 0;
        $done=0;
        foreach(DB::table('m25_apple_revocations')->orderBy('updated_at')->orderBy('id')->limit($max)->get() as $row) {
            // Rotate failed records to the back; an unavailable token must not starve later requests.
            DB::table('m25_apple_revocations')->where('id',$row->id)->update(['updated_at'=>now()]);
            try {
                $response=Http::asForm()->timeout(10)->withOptions(['allow_redirects'=>false])->post('https://appleid.apple.com/auth/revoke',[
                    'client_id'=>config('m25_apple.bundle'),'client_secret'=>$this->secret(),'token'=>Crypt::decryptString($row->refresh_token),'token_type_hint'=>'refresh_token',
                ]);
                if($response->successful()){DB::table('m25_apple_revocations')->where('id',$row->id)->delete();$done++;}
            } catch(\Throwable) { /* retain encrypted retry record; never log tokens */ }
        }
        return $done;
    }
}
