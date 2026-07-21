<?php

namespace App\Notifications;

use App\Models\Publication;
use App\Models\User;
use NotificationChannels\Fcm\FcmChannel;
use Illuminate\Contracts\Queue\ShouldQueue;


class PublicationPartageNotification extends BaseNotification
{
    public function __construct(public Publication $publication) {}

    protected function getType(): string { return 'partage_publication'; }
    protected function getTitle(): string { 
        switch ($this->getLangue()) {
            case 'fr':
                return $this->getAuthorName() . ', Nouveau partage';
            case 'ar':
                return $this->getAuthorName() . ', مشاركة جديدة';
            default:
                return $this->getAuthorName() . ", New share";
        }
     }
    
    protected function getMessage(): string
    {
        switch ($this->getLangue()) {
            case 'fr':
                return "Votre publication a été partagée par un utilisateur.";
            case 'ar':
                return "تم مشاركة منشورك بواسطة مستخدم";
            default:
                return "Your post has been shared by a user.";
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
        else return $this->publication->user->last_name;
    }

    protected function getLangue(): string
    {

        if($this->publication->etab_id) return $this->publication->etablissement->langue;
        else return $this->publication->user->langue;
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