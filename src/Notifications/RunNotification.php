<?php

namespace NineteenNinetyFour\Ingest\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An email about a feed: a failed or stopped run, a recovery, or a feed
 * that hasn't run successfully for a while.
 */
class RunNotification extends Notification
{
    use Queueable;

    /**
     * @param  string[]  $lines
     */
    public function __construct(
        public string $subject,
        public array $lines,
        public ?string $url = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject);

        foreach ($this->lines as $line) {
            $mail->line($line);
        }

        if ($this->url) {
            $mail->action(__('See the run'), $this->url);
        }

        return $mail;
    }
}
