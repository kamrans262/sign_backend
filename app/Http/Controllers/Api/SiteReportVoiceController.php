<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ServiceJob;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

final class SiteReportVoiceController extends Controller
{
    /** Explicit, temporary M12 dictation. Nothing is saved, sent or approved. */
    public function transcribe(Request $request): JsonResponse
    {
        $company=$request->attributes->get('company');
        abort_unless($company instanceof Company && $company->status==='active',403);
        $data=$request->validate([
            'job_id'=>['required','integer','min:1'],
            'file'=>['required','file','mimes:m4a,mp4,aac,wav','max:25600'],
        ]);
        $job=ServiceJob::query()->where('company_id',$company->id)
            ->findOrFail($data['job_id']);
        abort_if($job->category==='archived',409,'Cannot dictate for an archived job.');
        $file=$request->file('file');
        $bytes=file_get_contents($file->getRealPath());
        abort_unless(is_string($bytes) && strlen($bytes)>=16 &&
            strlen($bytes)<=25*1024*1024,422,'Recording size is invalid.');
        $head=substr($bytes,0,16);
        $isMp4=substr($head,4,4)==='ftyp';
        $isWav=substr($head,0,4)==='RIFF'&&substr($head,8,4)==='WAVE';
        $isAac=ord($bytes[0])===255&&(ord($bytes[1])&240)===240;
        abort_unless($isMp4||$isWav||$isAac,422,'Only AAC, M4A or WAV recordings are supported.');
        $key=trim((string)config('services.openai.key'));
        abort_if($key==='',503,'Voice transcription is not configured.');
        $filename=$isMp4?'report.m4a':($isWav?'report.wav':'report.aac');
        $result=Http::withToken($key)->timeout(95)
            ->attach('file',$bytes,$filename)
            ->post('https://api.openai.com/v1/audio/transcriptions',[
                'model'=>'gpt-4o-mini-transcribe',
            ]);
        abort_unless($result->successful(),502,'Transcription service unavailable.');
        $text=trim((string)$result->json('text'));
        if($text===''||mb_strlen($text)>20000) {
            throw ValidationException::withMessages(['file'=>'No usable dictated text was recognized.']);
        }
        AuditLogger::record('mobile.report.voice_transcribed',
            $request->user(),null,$company,
            ['job_id'=>$job->id,'byte_size'=>strlen($bytes)],$request);
        return response()->json([
            'data'=>['job_id'=>$job->id,'transcript'=>$text,
                'applied'=>false,'approved'=>false],
        ],200,['Cache-Control'=>'private, no-store']);
    }
}
