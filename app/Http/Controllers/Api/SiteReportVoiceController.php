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
            'mode'=>['sometimes','in:meeting_minutes'],
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
        // Meeting minutes need interpretation, not verbatim dictation.
        // Keep the original transcript as a reviewable fallback if AI cannot
        // rewrite it. Ordinary Site Report dictation keeps its original API.
        $suggestion = null;
        if (($data['mode'] ?? '') === 'meeting_minutes') {
            try {
                $instructions = <<<'PROMPT'
Turn the supplied informal meeting dictation into an editable meeting-minutes
suggestion. A short sentence is enough; never require a full meeting template.
Return JSON with title and summary as strings or null, and arrays of strings
for attendees, absentees, decisions, nextSteps, outstandingPoints, actionItems.
Write a short meaningful title and a polished, concise summary in the speaker's
language, not a transcript or instructions like "create meeting minutes".
Move actually mentioned attendees, decisions, tasks and next steps into their
proper fields. Keep useful context in the summary without repeating lists.
Do not invent attendees, absentees, agreements, names, action owners, dates,
completion claims or approvals. Missing arrays must be empty. Suggestions
must be reviewed by the user and never saved or sent automatically.
PROMPT;
                $ai = Http::withToken($key)->timeout(70)
                    ->post('https://api.openai.com/v1/chat/completions', [
                        'model' => config('services.openai.quote_model', 'gpt-4o-mini'),
                        'temperature' => 0,
                        'response_format' => ['type' => 'json_object'],
                        'messages' => [
                            ['role' => 'system', 'content' => $instructions.\App\Support\AppLocale::aiInstruction()],
                            ['role' => 'user', 'content' => json_encode(
                                ['transcript' => $text],
                                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
                        ],
                    ]);
                $parsed = $ai->successful()
                    ? json_decode((string) $ai->json('choices.0.message.content'), true)
                    : null;
                if (is_array($parsed) && !array_is_list($parsed)) {
                    $clean = static fn (mixed $value, int $limit): ?string =>
                        is_string($value) && trim($value) !== ''
                            ? mb_substr(trim($value), 0, $limit) : null;
                    $list = static function (mixed $values) use ($clean): array {
                        if (!is_array($values)) return [];
                        return array_values(array_unique(array_filter(array_map(
                            fn ($value) => $clean($value, 2500),
                            array_slice($values, 0, 40)
                        ))));
                    };
                    $suggestion = [
                        'title' => $clean($parsed['title'] ?? null, 255),
                        'summary' => $clean($parsed['summary'] ?? null, 5000),
                        'attendees' => $list($parsed['attendees'] ?? null),
                        'absentees' => $list($parsed['absentees'] ?? null),
                        'decisions' => $list($parsed['decisions'] ?? null),
                        'nextSteps' => $list($parsed['nextSteps'] ?? null),
                        'outstandingPoints' => $list($parsed['outstandingPoints'] ?? null),
                        'actionItems' => $list($parsed['actionItems'] ?? null),
                    ];
                }
            } catch (\Throwable $exception) {
                // Preserve the dictated text for manual review. AI is optional.
                $suggestion = null;
            }
        }
        AuditLogger::record('mobile.report.voice_transcribed',
            $request->user(),null,$company,
            ['job_id'=>$job->id,'byte_size'=>strlen($bytes)],$request);
        return response()->json([
            'data'=>['job_id'=>$job->id,'transcript'=>$text,
                'suggestion'=>$suggestion,'requires_user_review'=>true,
                'applied'=>false,'approved'=>false],
        ],200,['Cache-Control'=>'private, no-store']);
    }
}
