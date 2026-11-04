<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceJob;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class JobMediaController extends Controller
{
    private function company(Request $request): \App\Models\Company
    {
        $company = $request->attributes->get('company');
        abort_unless($company instanceof \App\Models\Company && $company->status === 'active', 403);
        return $company;
    }

    private function job(Request $request, int $job): ServiceJob
    {
        return ServiceJob::query()->where('company_id', $this->company($request)->id)->findOrFail($job);
    }

    private function writable(ServiceJob $job): void
    {
        abort_if($job->category === 'archived', 409, 'Restore the job before adding or changing media.');
    }

    private function photo(Request $request, ServiceJob $job, int $photo): object
    {
        return DB::table('job_photos')->where('company_id', $job->company_id)
            ->where('service_job_id', $job->id)->whereNull('archived_at')->where('id', $photo)->firstOrFail();
    }

    private function note(Request $request, ServiceJob $job, int $note): object
    {
        return DB::table('job_notes')->where('company_id', $job->company_id)
            ->where('service_job_id', $job->id)->whereNull('archived_at')->where('id', $note)->firstOrFail();
    }

    private function safePhoto(object $item): array
    {
        return [
            'id' => $item->id, 'job_id' => $item->service_job_id,
            'company_id' => $item->company_id, 'category' => $item->category,
            'caption' => $item->caption, 'title' => $item->caption ?: 'Front View',
            'original_name' => $item->original_name, 'mime_type' => $item->mime_type,
            'byte_size' => $item->byte_size, 'sha256' => $item->sha256,
            'created_at' => $item->created_at,
        ];
    }

    private function safeNote(object $item): array
    {
        return [
            'id' => $item->id, 'job_id' => $item->service_job_id,
            'company_id' => $item->company_id, 'title' => $item->title,
            'body' => $item->body, 'type' => $item->note_type,
            'visibility' => $item->visibility, 'has_audio' => $item->audio_private_path !== null,
            'audio_mime_type' => $item->audio_mime_type,
            'audio_byte_size' => $item->audio_byte_size,
            'audio_sha256' => $item->audio_sha256, 'created_at' => $item->created_at,
            'updated_at' => $item->updated_at,
        ];
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status, ['Cache-Control' => 'private, no-store']);
    }

    public function photos(Request $request, int $job): JsonResponse
    {
        $target = $this->job($request, $job);
        $items = DB::table('job_photos')->where('company_id', $target->company_id)
            ->where('service_job_id', $target->id)->whereNull('archived_at')
            ->orderByDesc('id')->limit(100)->get()->map(fn ($p) => $this->safePhoto($p));
        return $this->json(['photos' => $items]);
    }

    public function uploadPhoto(Request $request, int $job): JsonResponse
    {
        $target = $this->job($request, $job);
        $this->writable($target);
        $values = $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:20480'],
            'category' => ['required', Rule::in(['before', 'during', 'after'])],
            'caption' => ['nullable', 'string', 'max:187'],
        ]);
        $file = $request->file('file');
        $bytes = file_get_contents($file->getRealPath());
        if ($bytes === false || strlen($bytes) < 8) abort(422, 'Invalid image file.');
        $image = @getimagesizefromstring($bytes);
        $mime = $image['mime'] ?? null;
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) abort(422, 'Invalid image encoding.');
        // Reject duplicate content even across app restarts or repeated uploads.
        if (DB::table('job_photos')->where('company_id',$target->company_id)
            ->where('service_job_id',$target->id)->where('sha256',hash('sha256',$bytes))
            ->whereNull('archived_at')->exists()) {
            throw ValidationException::withMessages(['file'=>'This photo already exists for this job.']);
        }
        $suffix = $mime === 'image/png' ? 'png' : 'jpg';
        $path = 'job-media/'.$target->company_id.'/'.$target->id.'/photos/'.Str::uuid().'.'.$suffix;
        if (!Storage::disk('local')->put($path, $bytes)) abort(503, 'Private storage unavailable.');
        try {
            $id = DB::table('job_photos')->insertGetId([
                'company_id' => $target->company_id, 'service_job_id' => $target->id,
                'created_by' => $request->user()->id, 'category' => $values['category'],
                'caption' => trim($values['caption'] ?? ''),
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'private_path' => $path, 'mime_type' => $mime,
                'byte_size' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
        AuditLogger::record('mobile.job_photo.created', $request->user(), null, $this->company($request),
            ['job_id' => $target->id, 'photo_id' => $id], $request);
        return $this->json(['photo' => $this->safePhoto($this->photo($request, $target, $id))], 201);
    }

    public function updatePhoto(Request $request, int $job, int $photo): JsonResponse
    {
        $target = $this->job($request, $job);
        $this->writable($target);
        $item = $this->photo($request, $target, $photo);
        $values = $request->validate([
            'category' => ['sometimes', Rule::in(['before', 'during', 'after'])],
            'caption' => ['sometimes', 'nullable', 'string', 'max:187'],
        ]);
        if (!$values) throw ValidationException::withMessages(['photo' => 'No changes supplied.']);
        DB::table('job_photos')->where('id', $item->id)->update(array_merge($values, ['updated_at' => now()]));
        AuditLogger::record('mobile.job_photo.updated', $request->user(), null, $this->company($request),
            ['job_id' => $target->id, 'photo_id' => $photo], $request);
        return $this->json(['photo' => $this->safePhoto($this->photo($request, $target, $photo))]);
    }

    public function deletePhoto(Request $request, int $job, int $photo): JsonResponse
    {
        $target = $this->job($request, $job);
        $this->writable($target);
        $item = $this->photo($request, $target, $photo);
        DB::table('job_photos')->where('id', $item->id)->update(['archived_at' => now(), 'updated_at' => now()]);
        AuditLogger::record('mobile.job_photo.archived', $request->user(), null, $this->company($request),
            ['job_id' => $target->id, 'photo_id' => $photo], $request);
        return $this->json(['archived' => true]);
    }

    private function privateFile(string $path, string $digest, string $mime): \Illuminate\Http\Response
    {
        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 404);
        $bytes = $disk->get($path);
        abort_unless(is_string($bytes) && hash_equals($digest, hash('sha256', $bytes)), 409, 'Media integrity check failed.');
        return response($bytes, 200, [
            'Content-Type' => $mime, 'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function photoFile(Request $request, int $job, int $photo): \Illuminate\Http\Response
    {
        $target = $this->job($request, $job);
        $item = $this->photo($request, $target, $photo);
        return $this->privateFile($item->private_path, $item->sha256, $item->mime_type);
    }

    public function notes(Request $request, int $job): JsonResponse
    {
        $target = $this->job($request, $job);
        $notes = DB::table('job_notes')->where('company_id', $target->company_id)
            ->where('service_job_id', $target->id)->whereNull('archived_at')
            ->orderByDesc('id')->limit(100)->get()->map(fn ($n) => $this->safeNote($n));
        return $this->json(['notes' => $notes]);
    }

    public function showNote(Request $request, int $job, int $note): JsonResponse
    {
        return $this->json(['note' => $this->safeNote($this->note($request, $this->job($request, $job), $note))]);
    }

    public function createNote(Request $request, int $job): JsonResponse
    {
        $target = $this->job($request, $job);
        $this->writable($target);
        $values = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:20000'],
            'type' => ['required', Rule::in(['text', 'voice', 'textWithAudio'])],
            'visibility' => ['required', Rule::in(['private', 'customer_facing'])],
            'file' => ['nullable', 'file', 'max:25600'],
        ]);
        $body = trim($values['body'] ?? '');
        $audio = $request->file('file');
        if ($values['type'] === 'text' && $body === '') {
            throw ValidationException::withMessages(['body' => 'Text notes require content.']);
        }
        if ($values['type'] === 'voice' && !$audio) {
            throw ValidationException::withMessages(['file' => 'Voice notes require a recording.']);
        }
        if ($values['type'] === 'textWithAudio' && (!$audio || $body === '')) {
            throw ValidationException::withMessages(['file' => 'A combined note needs text and audio.']);
        }
        if ($values['type'] === 'text' && $audio) {
            throw ValidationException::withMessages(['file' => 'Text notes cannot include audio.']);
        }
        $path = null;
        $mime = null;
        $bytes = null;
        if ($audio) {
            $bytes = file_get_contents($audio->getRealPath());
            if (!is_string($bytes) || strlen($bytes) < 12) abort(422, 'Invalid recording.');
            $header = substr($bytes, 0, 16);
            $isMp4 = substr($header, 4, 4) === 'ftyp';
            $isWave = substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'WAVE';
            $isAac = ord($bytes[0]) === 0xff && (ord($bytes[1]) & 0xf0) === 0xf0;
            if (!$isMp4 && !$isWave && !$isAac) abort(422, 'Only AAC, M4A and WAV recordings are supported.');
            $mime = $isMp4 ? 'audio/mp4' : ($isWave ? 'audio/wav' : 'audio/aac');
            $extension = $isMp4 ? 'm4a' : ($isWave ? 'wav' : 'aac');
            $path = 'job-media/'.$target->company_id.'/'.$target->id.'/audio/'.Str::uuid().'.'.$extension;
            if (!Storage::disk('local')->put($path, $bytes)) abort(503, 'Private storage unavailable.');
        }
        try {
            $id = DB::table('job_notes')->insertGetId([
                'company_id' => $target->company_id, 'service_job_id' => $target->id,
                'created_by' => $request->user()->id, 'title' => trim($values['title']),
                'body' => $body, 'note_type' => $values['type'], 'visibility' => $values['visibility'],
                'audio_private_path' => $path, 'audio_mime_type' => $mime,
                'audio_byte_size' => $bytes === null ? null : strlen($bytes),
                'audio_sha256' => $bytes === null ? null : hash('sha256', $bytes),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            if ($path) Storage::disk('local')->delete($path);
            throw $e;
        }
        AuditLogger::record('mobile.job_note.created', $request->user(), null, $this->company($request),
            ['job_id' => $target->id, 'note_id' => $id, 'visibility' => $values['visibility']], $request);
        return $this->json(['note' => $this->safeNote($this->note($request, $target, $id))], 201);
    }

    public function updateNote(Request $request, int $job, int $note): JsonResponse
    {
        $target = $this->job($request, $job);
        $this->writable($target);
        $existing = $this->note($request, $target, $note);
        $values = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'body' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'visibility' => ['sometimes', Rule::in(['private', 'customer_facing'])],
        ]);
        if (!$values) throw ValidationException::withMessages(['note' => 'No changes supplied.']);
        if (array_key_exists('body', $values)) $values['body'] = trim($values['body'] ?? '');
        if ($existing->note_type === 'text' && array_key_exists('body', $values) && $values['body'] === '') {
            throw ValidationException::withMessages(['body' => 'Text note content cannot be empty.']);
        }
        DB::table('job_notes')->where('id', $note)->update(array_merge($values, ['updated_at' => now()]));
        AuditLogger::record('mobile.job_note.updated', $request->user(), null, $this->company($request),
            ['job_id' => $target->id, 'note_id' => $note], $request);
        return $this->json(['note' => $this->safeNote($this->note($request, $target, $note))]);
    }

    public function deleteNote(Request $request, int $job, int $note): JsonResponse
    {
        $target = $this->job($request, $job);
        $this->writable($target);
        $this->note($request, $target, $note);
        DB::table('job_notes')->where('id', $note)->update(['archived_at' => now(), 'updated_at' => now()]);
        AuditLogger::record('mobile.job_note.archived', $request->user(), null, $this->company($request),
            ['job_id' => $target->id, 'note_id' => $note], $request);
        return $this->json(['archived' => true]);
    }

    public function noteAudio(Request $request, int $job, int $note): \Illuminate\Http\Response
    {
        $item = $this->note($request, $this->job($request, $job), $note);
        abort_unless($item->audio_private_path && $item->audio_sha256 && $item->audio_mime_type, 404);
        return $this->privateFile($item->audio_private_path, $item->audio_sha256, $item->audio_mime_type);
    }
}
