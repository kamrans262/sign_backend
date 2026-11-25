<?php
namespace Tests\Feature;

use App\Services\M25AppleIdentity;
use App\Services\M25AppleJwt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class M25AppleRevocationTest extends TestCase
{
    use RefreshDatabase;
    private string $keyPath;
    protected function setUp(): void
    {
        parent::setUp();
        $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
        $this->assertNotFalse($key);
        $this->assertTrue(openssl_pkey_export($key,$pem));
        $this->keyPath=tempnam(sys_get_temp_dir(),'s2d-apple-test-');
        file_put_contents($this->keyPath,$pem);
        chmod($this->keyPath,0600);
        config(['m25_apple.signin_key_id'=>'TESTKEY','m25_apple.signin_key_path'=>$this->keyPath]);
        Http::preventStrayRequests();
    }
    protected function tearDown(): void
    {
        if(isset($this->keyPath)) @unlink($this->keyPath);
        parent::tearDown();
    }
    private function responseToken(string $subject): string
    {
        // Fake HTTPS response fixture, not an accepted client identity token.
        return M25AppleJwt::b64('{"alg":"RS256"}').'.'.M25AppleJwt::b64(json_encode([
            'sub'=>$subject,'aud'=>config('m25_apple.bundle'),'iss'=>'https://appleid.apple.com',
        ])).'.fixture';
    }
    public function test_exchange_encrypts_refresh_and_binds_apple_subject():void
    {
        Http::fake(['https://appleid.apple.com/auth/token'=>Http::response([
            'id_token'=>$this->responseToken('subject-one'),'refresh_token'=>'fake-refresh-token',
        ])]);
        $cipher=app(M25AppleIdentity::class)->exchange('fake-code','subject-one');
        $this->assertNotSame('fake-refresh-token',$cipher);
        $this->assertSame('fake-refresh-token',Crypt::decryptString($cipher));
        Http::assertSent(function(Request $r):bool{
            $jwt=$r['client_secret'];
            $claims=M25AppleJwt::claims($jwt);
            $parts=explode('.',$jwt);
            $signature=base64_decode(strtr($parts[2],'-_','+/').'==',true);
            $this->assertSame(64,strlen($signature));
            $der='';
            foreach([substr($signature,0,32),substr($signature,32)] as $n){
                $n=ltrim($n,"\0");
                if($n===''||(ord($n[0])&128))$n="\0".$n;
                $der.=chr(2).chr(strlen($n)).$n;
            }
            $private=openssl_pkey_get_private(file_get_contents($this->keyPath));
            $public=openssl_pkey_get_details($private)['key'];
            $this->assertSame(1,openssl_verify($parts[0].'.'.$parts[1],chr(48).chr(strlen($der)).$der,$public,OPENSSL_ALGO_SHA256));
            return $r['grant_type']==='authorization_code'&&$r['code']==='fake-code'
                &&$claims['sub']===config('m25_apple.bundle')&&$claims['aud']==='https://appleid.apple.com';
        });
        $this->expectException(ValidationException::class);
        app(M25AppleIdentity::class)->exchange('fake-code','another-subject');
    }
    public function test_revocation_retry_retains_failure_and_erases_success():void
    {
        DB::table('m25_apple_revocations')->insert([
            ['refresh_token'=>Crypt::encryptString('old-failing-token'),'created_at'=>now()->subDays(2),'updated_at'=>now()->subDays(2)],
            ['refresh_token'=>Crypt::encryptString('new-token'),'created_at'=>now()->subDay(),'updated_at'=>now()->subDay()],
        ]);
        Http::fake(['https://appleid.apple.com/auth/revoke'=>Http::sequence()->push([],503)->push([],200)->push([],200)]);
        $service=app(M25AppleIdentity::class);
        $this->assertSame(0,$service->retry(1));
        $this->assertDatabaseCount('m25_apple_revocations',2);
        $this->assertSame(1,$service->retry(1));
        $this->assertSame('old-failing-token',Crypt::decryptString(DB::table('m25_apple_revocations')->first()->refresh_token));
        $this->assertSame(1,$service->retry(1));
        $this->assertDatabaseCount('m25_apple_revocations',0);
        Http::assertSent(fn(Request $r)=>$r['token']==='new-token'&&$r['token_type_hint']==='refresh_token');
    }
}
