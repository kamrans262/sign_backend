<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

final class ExpenseAiController extends Controller
{
    private function company(Request $request): Company
    {
        $company=$request->attributes->get('company');
        abort_unless($company instanceof Company && $company->status==='active',403);
        return $company;
    }

    /** AI content is untrusted: never allow invalid calendar dates into drafts. */
    private static function calendarDate(mixed $input): ?string
    {
        if(!is_string($input)||
            !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$input))return null;
        $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$input);
        return $parsed!==false&&$parsed->format('Y-m-d')===$input
            ?$input:null;
    }

    private static function suggestion(string $text,string $key): array
    {
        $prompt='Return JSON ONLY with optional jobReference, expenseType, date, amount, '
            .'supplier, description and tags. Use ONLY details explicitly present in the '
            .'input. Valid categories: Fuel, Tools, Parking, Meals, Other, Material, Labour. '
            .'Date YYYY-MM-DD and amount positive CHF number. Unknown fields null, tags []. '
            .'No estimate, new purchase, job association, tax, approval or save.';
        $response=Http::withToken($key)->timeout(60)->post(
            'https://api.openai.com/v1/chat/completions',[
                'model'=>config('services.openai.quote_model','gpt-4o-mini'),
                'temperature'=>0,'response_format'=>['type'=>'json_object'],
                'messages'=>[
                    ['role'=>'system','content'=>$prompt],
                    ['role'=>'user','content'=>mb_substr($text,0,14000)],
                ],
            ]);
        abort_unless($response->successful(),502,'Expense extraction temporarily unavailable.');
        $raw=$response->json('choices.0.message.content');
        $value=is_string($raw)?json_decode($raw,true):null;
        abort_unless(is_array($value),502,'Invalid expense extraction response.');
        $types=['Fuel','Tools','Parking','Meals','Other','Material','Labour'];
        $expenseType=$value['expenseType']??null;
        $day=self::calendarDate($value['date']??null);
        $amount=$value['amount']??null;
        if(!is_numeric($amount)||!is_finite((float)$amount)||$amount<=0||
            $amount>9999999999) $amount=null;
        $safe=static fn($v,$max)=>is_string($v)&&trim($v)!==''
            ?mb_substr(trim($v),0,$max):null;
        return [
            'jobReference'=>$safe($value['jobReference']??null,255),
            'expenseType'=>in_array($expenseType,$types,true)?$expenseType:null,
            'date'=>$day,'amount'=>$amount===null?null:(float)$amount,
            'supplier'=>$safe($value['supplier']??null,255),
            'description'=>$safe($value['description']??null,2000),
            'tags'=>array_slice(array_values(array_unique(array_filter(
                array_map(fn($v)=>$safe($v,60),
                    is_array($value['tags']??null)?$value['tags']:[])
            ))),0,12),
            'applied'=>false,'approved'=>false,'saved'=>false,
        ];
    }

    public function voice(Request $request): JsonResponse
    {
        $company=$this->company($request);
        $data=$request->validate([
            'file'=>['required','file','mimes:m4a,mp4,aac,wav','max:25600'],
        ]);
        $bytes=file_get_contents($data['file']->getRealPath());
        abort_unless(is_string($bytes)&&strlen($bytes)>=16&&strlen($bytes)<=25*1024*1024,
            422,'Invalid audio recording.');
        $isWav=substr($bytes,0,4)==='RIFF'&&substr($bytes,8,4)==='WAVE';
        $isMp4=substr($bytes,4,4)==='ftyp';
        $isAac=ord($bytes[0])===255&&(ord($bytes[1])&240)===240;
        abort_unless($isWav||$isMp4||$isAac,422,'Invalid audio encoding.');
        $key=trim((string)config('services.openai.key'));
        abort_if($key==='',503,'Expense AI is not configured.');
        $filename=$isWav?'expense.wav':($isMp4?'expense.m4a':'expense.aac');
        $transcription=Http::withToken($key)->timeout(95)
            ->attach('file',$bytes,$filename)
            ->post('https://api.openai.com/v1/audio/transcriptions',[
                'model'=>'gpt-4o-mini-transcribe',
            ]);
        abort_unless($transcription->successful(),502,'Voice service unavailable.');
        $text=trim((string)$transcription->json('text'));
        if($text===''||mb_strlen($text)>14000)
            throw ValidationException::withMessages([
                'file'=>'Unable to recognize usable expense details.',
            ]);
        $draft=self::suggestion($text,$key);
        AuditLogger::record('mobile.expense.ai_voice_suggested',$request->user(),
            null,$company,['audio_bytes'=>strlen($bytes)],$request);
        return response()->json(['data'=>['suggestion'=>$draft]],200,[
            'Cache-Control'=>'private, no-store']);
    }

    public function receipt(Request $request): JsonResponse
    {
        $company=$this->company($request);
        $data=$request->validate([
            'file'=>['required','file','mimes:jpg,jpeg,png','max:5120'],
        ]);
        $bytes=file_get_contents($data['file']->getRealPath());
        $mime=is_string($bytes)
            ?(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes):'';
        abort_unless(is_string($bytes)&&in_array($mime,
            ['image/jpeg','image/png'],true)&&is_array(@getimagesizefromstring($bytes)),
            422,'Only real receipt images are supported for AI extraction.');
        $key=trim((string)config('services.openai.key'));
        abort_if($key==='',503,'Expense AI is not configured.');
        $response=Http::withToken($key)->timeout(75)->post(
            'https://api.openai.com/v1/chat/completions',[
                'model'=>config('services.openai.quote_model','gpt-4o-mini'),
                'temperature'=>0,'response_format'=>['type'=>'json_object'],
                'messages'=>[
                    ['role'=>'system','content'=>'Extract visible purchase receipt fields as JSON '
                        .'using ONLY clearly printed information. Keys: date, amount, supplier, '
                        .'description, expenseType, tags, jobReference. Unknown is null; '
                        .'never guess; never create a purchase.'],
                    ['role'=>'user','content'=>[
                        ['type'=>'text','text'=>'Read this receipt as a draft suggestion.'],
                        ['type'=>'image_url','image_url'=>[
                            'url'=>'data:'.$mime.';base64,'.base64_encode($bytes),
                        ]],
                    ]],
                ],
            ]);
        abort_unless($response->successful(),502,'Receipt extraction unavailable.');
        $json=$response->json('choices.0.message.content');
        abort_unless(is_string($json),502);
        // Feed only the returned JSON through the same normalizer.
        $raw=json_decode($json,true);
        abort_unless(is_array($raw),502);
        $suggestion=[
            'jobReference'=>is_string($raw['jobReference']??null)?mb_substr($raw['jobReference'],0,255):null,
            'expenseType'=>in_array($raw['expenseType']??null,
                ['Fuel','Tools','Parking','Meals','Other','Material','Labour'],true)
                    ?$raw['expenseType']:null,
            'date'=>self::calendarDate($raw['date']??null),
            'amount'=>is_numeric($raw['amount']??null)&&
                (float)$raw['amount']>0&&(float)$raw['amount']<9999999999
                    ?(float)$raw['amount']:null,
            'supplier'=>is_string($raw['supplier']??null)?mb_substr(trim($raw['supplier']),0,255):null,
            'description'=>is_string($raw['description']??null)?mb_substr(trim($raw['description']),0,2000):null,
            'tags'=>array_slice(array_filter(array_map(fn($v)=>is_string($v)?mb_substr(trim($v),0,60):null,
                is_array($raw['tags']??null)?$raw['tags']:[])),0,12),
            'applied'=>false,'approved'=>false,'saved'=>false,
        ];
        AuditLogger::record('mobile.expense.ai_receipt_suggested',$request->user(),
            null,$company,['receipt_bytes'=>strlen($bytes)],$request);
        return response()->json(['data'=>['suggestion'=>$suggestion]],200,[
            'Cache-Control'=>'private, no-store']);
    }
}
