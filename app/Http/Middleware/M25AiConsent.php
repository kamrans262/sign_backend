<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
final class M25AiConsent {
    public const VERSION = 'openai-2026-11-25-v1';
    public static function required(string $path): bool {
        return (bool) preg_match('~^api/v1/(?:support/assistant(?:/transcribe)?|ai/(?:companies|jobs)/voice|jobs/[^/]+/notes/[^/]+/(?:transcribe|documents/prepare)|quotes/prepare/[^/]+|(?:labour|invoices|deposits|expenses|reminders)/ai/[^/]+|general-documents/(?:ai/prepare|voice/transcribe)|reports/(?:voice/transcribe|[^/]+/prepare-ai)|addenda/prepare/voice|equipment(?:-loans)?/voice/prepare)$~D', ltrim($path,'/'));
    }
    public static function allowed(?int $id): bool {
        return $id !== null && DB::table('m25_ai_consents')->where('user_id',$id)
            ->where('version',self::VERSION)->where('allowed',true)->exists();
    }
    public function handle(Request $request, Closure $next): Response {
        if ($request->isMethod('POST') && self::required($request->path()) && !self::allowed($request->user()?->id)) {
            return response()->json(['message'=>'Your permission is required before sharing information with OpenAI.','code'=>'ai_consent_required'],403);
        }
        return $next($request);
    }
}
