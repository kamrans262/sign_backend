<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CompanyInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $companyName,
        public readonly string $inviteCode,
        public readonly string $inviterName,
    ) {}

    public function build(): static
    {
        return $this->subject('Sign2Digital — Company invitation')
            ->view('mail.company-invitation')
            ->with([
                'companyName' => $this->companyName,
                'inviteCode' => $this->inviteCode,
                'inviterName' => $this->inviterName,
            ]);
    }
}
