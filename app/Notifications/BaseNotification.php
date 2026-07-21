<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use Illuminate\Notifications\Messages\MailMessage;

abstract class BaseNotification extends Notification
{
    use Queueable;

    abstract protected function getType(): string;
    abstract protected function getMessage(): string;
    abstract protected function getLink(): string;
    abstract protected function getTitle(): string;
    abstract protected function getImage(): string;
    abstract protected function getGreeting():? string;
    abstract protected function getSalutation():? string;
    abstract protected function getData():? array;

    public function via($notifiable): array
    {
        // if($notifiable->email_status == 'pending') {
        //     // Perform the DNS check HERE, before building the message
        //     $email = $notifiable->email; // Get the email from the notifiable
        //     $domain = substr(strrchr($email, "@"), 1);
        //     $hasMxRecord = checkdnsrr($domain, 'MX');

        //     if (!$hasMxRecord) {
        //         // Update the user status immediately
        //         if ($notifiable) {
        //             $notifiable->update([
        //                 'email_status' => 'invalid',
        //             ]);
        //         }
        //     } else {
        //         $notifiable->update(['email_status' => 'valid']);
        //     }
        // }
        if (method_exists($this, 'toMail') 
            // && $notifiable->email_status == 'valid'
        ) {
            $channels[] = 'mail';
        }
        
        if (method_exists($this, 'toFcm')) {
            $channels[] = 'database';
            if ($notifiable->notification_tokens()->exists()) {
                $channels[] = FcmChannel::class;
            }
        }
        
        return $channels;
    }

    public function toDatabase($notifiable): array
    {
        // return [
        //     'type' => $this->getType(),
        //     'title' => $this->getTitle(),
        //     'message' => $this->getMessage(),
        //     'link' => $this->getLink(),
        //     'image' => $this->getImage(),
        // ];

        return $this->getData();
    }

    public function toMail($notifiable): MailMessage
    {
        
            return (new MailMessage)
            // ->from(env('MAIL_FROM_ADDRESS'), env('MAIL_FROM_NAME'))
            // ->replyTo(env('MAIL_FROM_ADDRESS'))
            // ->returnPath(env('BOUNCE_EMAIL'))
            ->subject($this->getTitle())
            ->greeting($this->getGreeting())  // Add greeting
            ->line($this->getMessage())
            ->action('View', $this->getLink())
            ->salutation($this->getSalutation());  // Custom signature;
        
    }

    public function toFcm($notifiable): FcmMessage
    {
        return FcmMessage::create()
            ->setNotification(
                \NotificationChannels\Fcm\Resources\Notification::create()
                    ->setTitle($this->getTitle())
                    ->setBody($this->getMessage())
                    ->setImage($this->getImage()) // Optional: set an image URL
            )
            ->setData([
                'link' => $this->getLink(),
                'deepLink' => $this->getLink(),
                // "click_action" => "OPEN_APP",
            ]);
    }

    public function shouldSend($notifiable): bool
    {
        return true;
    }
}