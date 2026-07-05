<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

final class UserPasswordResetMail extends Mailable
{
    public function __construct(
        private readonly string $subjectLine,
        private readonly string $body
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject($this->subjectLine)
            ->html(nl2br(e($this->body)));
    }
}
