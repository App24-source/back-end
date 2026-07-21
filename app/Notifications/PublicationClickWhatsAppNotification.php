<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;
use App\Models\Publication;


class PublicationClickWhatsAppNotification extends BaseNotification
{
    public function __construct(public Publication $publication) {}

    protected function getType(): string { return 'click_whatsapp_publication'; }
    protected function getTitle(): string { 
        switch ($this->getLangue()) {
            case 'fr':
                return $this->getAuthorName() . ', Activité sur votre publication';
            case 'ar':
                return $this->getAuthorName() . ', نشاط على منشورك';
            default:
                return $this->getAuthorName() . ", Activity on your publication";
        }
    }
    
    protected function getMessage(): string
    {
        switch ($this->getLangue()) {
            case 'fr':
                return "Votre publication a reçu un clics WhatsApp !";
            case 'ar':
                return "تلقى منشورك  نقرات واتساب !";
            default:
                return "Your publication got a WhatsApp clicks!";
        }
    }

    protected function getImage(): string {
        return "https://media.flink.ma/" . $this->publication->image_default; // Optional: child classes don't have to implement this
    }

    public function getGreeting(): ?string {
        return null; // Optional: child classes don't have to implement this
    }

    public function getSalutation(): ?string {
        return null; // Optional: child classes don't have to implement this
    }

    protected function getLink(): string { return 'https://flink.ma/publication/' . $this->publication->slug . '/'; }

    public function via($notifiable): array {
         return  ['database', FcmChannel::class];
    }

    protected function getAuthorName(): string
    {
        if($this->publication->etab_id) return $this->publication->etablissement->nom;
        return $this->publication->user->last_name;
    }

    protected function getLangue(): string
    {
        if($this->publication->etab_id) return $this->publication->etablissement->langue;
        return $this->publication->user->langue;
    }

    protected function getData(): array {
        return [
            'type' => $this->getType(),
            'profile' => [
                // 'id' => $this->publication->etab_id ? $this->publication->etablissement->id:  $this->publication->user->id,
                // 'name' => $this->getAuthorName(),
                // 'slug' => $this->publication->etab_id ? $this->publication->etablissement->slug:  $this->publication->user->slug,
                'avatar' => "https://media.flink.ma/design-icons/flink-icon-fit.png",
                // 'type' => $this->publication->etab_id ? "etablissement":  "user",
            ],
            'image' => $this->getImage(),
            'link' => $this->getLink(),
        ];
    }
}