<?php

namespace App\Notifications;

use App\Models\Publication;
use NotificationChannels\Fcm\FcmChannel;
use Illuminate\Contracts\Queue\ShouldQueue;


class PublicationRejectedNotificationEmail extends BaseNotification
{
    public function __construct(public Publication $publication) {}

    protected function getType(): string { return 'rejected_publication'; }
    protected function getTitle(): string { 
        switch ($this->getLangue()) {
            case 'fr':
                return 'Votre publication a été rejetée.';
            case 'ar':
                return 'تم رفض إعلانك.';
            default:
                return "Your post was rejected.";
        }
     }
    
    protected function getMessage(): string
    {
        switch ($this->getLangue()) {
            case 'fr':
                return "Votre publication " . $this->publication->slug . " vient d’être validée par notre équipe.
                    Elle est maintenant visible pour tous les utilisateurs de Flink.ma.

                    Partagez-la dès maintenant pour gagner plus de visibilité.

                    ➜ Voir ma publication → https://flink.ma/publication/" . $this->publication->slug . "

                    Merci pour votre confiance,";

            case 'ar':
                return "لم تتم الموافقة على منشورك " . $this->publication->slug . ".

                السبب: " . $this->publication->reason_rejection . "

                يمكنك تعديله وإعادة نشره فورًا.

                تعديل منشوري → https://flink.ma/profile/@" . $this->publication->user->slug . "

                نرجو الالتزام بقواعد النشر لدينا";

            default:
                return "Your publication " . $this->publication->slug . " has not been approved.

            Reason: " . $this->publication->reason_rejection . "

            You can edit and republish it immediately.

            Edit my publication → https://flink.ma/profile/@" . $this->publication->user->slug . "

            Please respect our publishing guidelines.";
        }

    }

    protected function getImage(): string {
        return "https://media.flink.ma/" . $this->publication->image_default; // Optional: child classes don't have to implement this
    }
    
    public function getGreeting(): ?string {  
        // return "Bonjour ". $this->getAuthorName($this->publication->user); 
        switch ($this->getLangue()) {
            case 'fr':
                return "Bonjour ". $this->getAuthorName(); 
            case 'ar':
                return 'مرحبا ' . $this->getAuthorName();
            default:
                return 'Hi ' . $this->getAuthorName();
        }
    }

    public function getSalutation(): string {
        switch ($this->getLangue()) {
            case 'fr':
                return '— L’équipe Flink.ma';
            case 'ar':
                return '— فريق Flink.ma';
            default:
                return '— The Flink.ma Team';
        }

    }

    protected function getLink(): string { return 'https://flink.ma/profile/@' . $this->publication->user->slug . '/'; }

    public function via($notifiable): array {
         return  ['mail'];
    }
    protected function getAuthorName(): string
    {
        if($this->publication->etab_id) return $this->publication->etablissement->nom;
        return $this->publication->user->first_name . ' ' . $this->publication->user->last_name;
    }

    protected function getLangue(): string
    {
        if($this->publication->etab_id) return $this->publication->etablissement->langue;
        return $this->publication->user->langue;
    }

    protected function getProfileSlug(): string {
        if($this->publication->etab_id) return $this->publication->etablissement->slug;
        return $this->publication->user->slug;
    }

    protected function getData(): array {
        return [];
    }
}