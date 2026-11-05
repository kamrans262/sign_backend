<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class M20NotificationTemplates
{
    public function validateCopy(object $template, string $title, string $body): void
    {
        $allowed = json_decode((string) $template->allowed_variables, true) ?: [];
        foreach ([$title, $body] as $value) {
            preg_match_all('/\{([^{}]+)\}/u', $value, $matches);
            foreach ($matches[1] as $name) {
                if (!in_array($name, $allowed, true)) {
                    throw ValidationException::withMessages(['body' => 'Unknown template variable: '.$name]);
                }
            }
            if (str_contains($value, '{') && preg_match('/\{[^{}]*$/', $value)) {
                throw ValidationException::withMessages(['body' => 'A template variable is incomplete.']);
            }
        }
    }

    public function render(string $key, string $channel, string $locale, array $values): ?array
    {
        $template = DB::table('m20_notification_templates')
            ->where('template_key', $key)->where('channel', $channel)
            ->where('locale', in_array($locale, ['fr', 'en'], true) ? $locale : 'en')
            ->where('enabled', true)->first();
        if (!$template && $locale !== 'en') {
            $template = DB::table('m20_notification_templates')
                ->where('template_key', $key)->where('channel', $channel)
                ->where('locale', 'en')->where('enabled', true)->first();
        }
        if (!$template || !$template->published_revision_id) return null;
        $revision = DB::table('m20_notification_template_revisions')
            ->where('template_id', $template->id)
            ->where('id', $template->published_revision_id)->first();
        if (!$revision) return null;
        $allowed = json_decode((string) $template->allowed_variables, true) ?: [];
        $replacements = [];
        foreach ($allowed as $variable) {
            // Only server-provided, explicit, bounded values. Notes and raw personal
            // messages cannot be introduced as arbitrary notification variables.
            $replacements['{'.$variable.'}'] = mb_substr((string) ($values[$variable] ?? ''), 0, 160);
        }
        return [
            'title' => strtr($revision->title, $replacements),
            'body' => strtr($revision->body, $replacements),
            'revision_id' => $revision->id,
        ];
    }
}