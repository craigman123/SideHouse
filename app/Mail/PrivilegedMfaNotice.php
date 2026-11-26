<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PrivilegedMfaNotice extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        private readonly string $noticeSubject,
        private readonly string $noticeBody,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->noticeSubject);
    }

    public function build(): self
    {
        return $this->subject($this->noticeSubject)
            ->view('emails.privileged-mfa-notice')
            ->with(['noticeBody' => $this->noticeBody]);
    }
}
