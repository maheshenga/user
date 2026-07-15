<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

final class UserNotificationMail extends Mailable
{
    public function __construct(
        public readonly string $subjectLine,
        public readonly string $messageText
    ) {}

    public function build(): self
    {
        return $this
            ->subject($this->subjectLine)
            ->html(nl2br(e($this->messageText)));
    }
}
