<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class AuditLogger
{
    public static function record(string $action, ?User $actor = null, ?Model $subject = null, ?Company $company = null, array $metadata = [], ?Request $request = null): void
    {
        $log = new AuditLog();
        $log->actor_user_id = $actor?->id;
        $log->company_id = $company?->id;
        $log->action = $action;
        $log->subject_type = $subject ? class_basename($subject) : null;
        $log->subject_id = $subject ? (string) $subject->getKey() : null;
        // Avoid passwords, tokens, content bodies or other secrets in metadata.
        $log->metadata = $metadata;
        $log->ip_address = $request?->ip();
        $log->user_agent = mb_substr((string) ($request?->userAgent() ?? ''), 0, 500);
        $log->created_at = now();
        $log->save();
    }
}
