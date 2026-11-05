<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

final class EquipmentLoanReminderMail extends Mailable
{
    use Queueable, SerializesModels;
    public function __construct(
        public readonly string $companyName,
        public readonly string $borrowerName,
        public readonly string $equipmentName,
        public readonly string $equipmentCode,
        public readonly string $expectedReturnDate,
    ) {}

    public function build(): static
    {
        return $this->subject('Equipment return reminder — '.$this->companyName)
            ->view('mail.equipment-loan-reminder')
            ->with([
                'companyName'=>$this->companyName,
                'borrowerName'=>$this->borrowerName,
                'equipmentName'=>$this->equipmentName,
                'equipmentCode'=>$this->equipmentCode,
                'expectedReturnDate'=>$this->expectedReturnDate,
            ]);
    }
}
