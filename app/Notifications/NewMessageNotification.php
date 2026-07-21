<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;

class NewMessageNotification extends BaseNotification
{
    public function __construct(public $senderProfile, public $receiverProfile, public $message, public $publication) {}

    protected function getType(): string { return 'new_message'; }
    protected function getTitle(): string { 
        switch ($this->getLangue()) {
            case 'fr':
                return $this->getAuthorName() . ', Tu as reçu un message !';
            case 'ar':
                return $this->getAuthorName() . ', جاك ميساج !';
            default:
                return $this->getAuthorName() . ", You’ve got a message!";
        }
     }
    
    protected function getMessage(): string
    {
        switch ($this->getLangue()) {
            case 'fr':
                return 'Va le lire maintenant.';
            case 'ar':
                return 'شوفو دابا';
            default:
                return "Read it now.";
        }
    }

    protected function getImage(): string {
        return "https://media.flink.ma/" . ($this->senderProfile->nom ? $this->senderProfile->logo : $this->senderProfile->avatar); // Optional: child classes don't have to implement this
    }

    public function getGreeting(): ?string {
        return null; // Optional: child classes don't have to implement this
    }

    public function getSalutation(): ?string {
        return null; // Optional: child classes don't have to implement this
    }

    protected function getLink(): string { return "https://flink.ma/d/messages"; }

    public function via($notifiable): array {
         return  ['database', FcmChannel::class];
    }

    protected function getAuthorName(): string
    {
        if($this->receiverProfile->nom) return $this->receiverProfile->nom;
        return $this->receiverProfile->last_name;
    }

    protected function getLangue(): string
    {
        if($this->receiverProfile->nom) return $this->receiverProfile->langue;
        return $this->receiverProfile->langue;
    }

    protected function getData(): array {
        return [
            'type' => $this->getType(),
            'profile' => [
                'id' => $this->senderProfile->id,
                'name' => $this->getAuthorName(),
                'slug' => $this->senderProfile->slug,
                'avatar' => $this->senderProfile->nom ? $this->senderProfile->logo : $this->senderProfile->avatar,
                'type' => $this->senderProfile->nom ? "etablissement":  "user",
            ],
            'image' => $this->getImage(),
            'link' => $this->getLink(),
        ];
    }
}