<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EmailOtpCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $code,
        public readonly string $purpose,
    ) {}

    public function build(): static
    {
        $label = match ($this->purpose) {
            'register' => 'Verify your email',
            'login' => 'Your sign-in code',
            'reset' => 'Reset your password',
            default => 'Your verification code',
        };
        return $this->subject('Sign2Digital — '.$label)
            ->view('mail.email-otp')
            ->with(['otpPurposeLabel' => $label, 'otpCode' => $this->code]);
    }
}
