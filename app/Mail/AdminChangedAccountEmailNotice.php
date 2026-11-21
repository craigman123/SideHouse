<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AdminChangedAccountEmailNotice extends Mailable
{
    use Queueable, SerializesModels;

    public function build(): self
    {
        return $this->subject('Your Side House account email was changed')
            ->html('<p>An administrator changed the email address for your Side House account.</p><p>If you did not expect this change, please contact Side House.</p>');
    }
}
