<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Support\AuditLogger;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

final class LabourAiController extends Controller
{
    public function voice(Request $request): JsonResponse
    {
        $company=$request->attributes->get('company');
        abort_unless($company instanceof Company && $company->status==='active',403);
        $data=$request->validate([
            'file'=>['required','file','mimes:m4a,mp4,aac,wav','max:25600'],
        ]);
        $bytes=file_get_contents($data['file']->getRealPath());
        abort_unless(is_string($bytes)&&strlen($bytes)>=16&&
            strlen($bytes)<=25*1024*1024,422,'Invalid audio size.');
        $wav=substr($bytes,0,4)==='RIFF'&&substr($bytes,8,4)==='WAVE';
        $mp4=substr($bytes,4,4)==='ftyp';
        $aac=ord($bytes[0])===255&&(ord($bytes[1])&240)===240;
        abort_unless($wav||$mp4||$aac,422,'Invalid audio content.');
        $key=trim((string)config('services.openai.key'));
        abort_if($key==='',503,'Labour voice AI is not configured.');
        $name=$wav?'work.wav':($mp4?'work.m4a':'work.aac');
        $transcript=Http::withToken($key)->timeout(95)
            ->attach('file',$bytes,$name)
            ->post('https://api.openai.com/v1/audio/transcriptions',[
                'model'=>'gpt-4o-mini-transcribe',
            ]);
        abort_unless($transcript->successful(),502,'Voice transcription unavailable.');
        $text=trim((string)$transcript->json('text'));
        if($text===''||mb_strlen($text)>14000)
            throw ValidationException::withMessages(['file'=>'No usable speech detected.']);
        $prompt='Extract ONLY spoken labour/time information as JSON object with '
            .'keys jobReference, worker, date (YYYY-MM-DD), startTime and endTime '
            .'(24-hour HH:mm), labourType (worker role), hourlyRate (CHF), description. '
            .'Unknown fields are null. Do not guess worker identity, job ID, amounts or '
            .'billing. Never approve, create, stop timers, or save.';
        $response=Http::withToken($key)->timeout(60)->post(
            'https://api.openai.com/v1/chat/completions',[
                'model'=>config('services.openai.quote_model','gpt-4o-mini'),
                'temperature'=>0,'response_format'=>['type'=>'json_object'],
                'messages'=>[
                    ['role'=>'system','content'=>$prompt],
                    ['role'=>'user','content'=>$text],
                ],
            ]);
        abort_unless($response->successful(),502,'Labour AI extraction unavailable.');
        $raw=$response->json('choices.0.message.content');
        $value=is_string($raw)?json_decode($raw,true):null;
        abort_unless(is_array($value),502,'Invalid AI response.');
        $string=static fn(mixed $v,int $max)=>is_string($v)&&trim($v)!==''
            ?mb_substr(trim($v),0,$max):null;
        $time=static fn(mixed $v)=>is_string($v)&&
            preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$v)?$v:null;
        $day=$string($value['date']??null,10);
        $date=$day?DateTimeImmutable::createFromFormat('!Y-m-d',$day):false;
        if(!$date||$date->format('Y-m-d')!==$day)$day=null;
        $rate=$value['hourlyRate']??null;
        if(!is_numeric($rate)||!is_finite((float)$rate)||
            $rate<0||$rate>999999)$rate=null;
        $result=[
            'jobReference'=>$string($value['jobReference']??null,255),
            'worker'=>$string($value['worker']??null,255),
            'date'=>$day,'startTime'=>$time($value['startTime']??null),
            'endTime'=>$time($value['endTime']??null),
            'labourType'=>$string($value['labourType']??null,120),
            'hourlyRate'=>$rate===null?null:(float)$rate,
            'description'=>$string($value['description']??null,2000),
            'saved'=>false,'approved'=>false,
        ];
        AuditLogger::record('mobile.labour.ai_suggested',$request->user(),
            null,$company,['audio_bytes'=>strlen($bytes)],$request);
        return response()->json(['data'=>['suggestion'=>$result]],200,[
            'Cache-Control'=>'private, no-store',
        ]);
    }
}
