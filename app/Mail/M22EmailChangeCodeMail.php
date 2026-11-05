<?php
namespace App\Mail;

use Illuminate\Mail\Mailable;

class M22EmailChangeCodeMail extends Mailable
{
    public function __construct(public readonly string $code) {}
    public function build(): static
    {
        return $this->subject('Sign2Digital — verify your new email')
            ->text('mail.m22-email-change', ['code' => $this->code]);
    }
}
