<?php

namespace App\Notifications;

use App\Models\Publication;
use NotificationChannels\Fcm\FcmChannel;
use Illuminate\Contracts\Queue\ShouldQueue;


class PublicationDeletedNotificationEmail extends BaseNotification
{
    public function __construct(public Publication $publication) {}

    protected function getType(): string { return 'deleted_publication'; }
    protected function getTitle(): string { 
        switch ($this->getLangue()) {
            case 'fr':
                return 'Votre publication a été supprimée';
            case 'ar':
                return 'تم حذف إعلانك';
            default:
                return "Your post was deleted";
        }
     }
    
    protected function getMessage(): string
    {
        switch ($this->getLangue()) {
            case 'fr':
                return "Vous avez supprimé la publication " . $this->publication->slug . ".

                    Pour rester visible, vous pouvez en créer une nouvelle à tout moment.

                    Créer une nouvelle publication → https://flink.ma/publication/@" . $this->getProfileSlug() . "

                    À bientôt sur Flink.ma !
                    ";

            case 'ar':
                return "
                    لقد قمت بحذف إعلانك " . $this->publication->slug . ".

                    يمكنك دائماً إنشاء إعلان جديد لتبقى مرئياً.

                    إنشاء إعلان جديد → https://flink.ma/publication/@" . $this->getProfileSlug() . "

                    نراك قريباً على Flink.ma
";

            default:
                return "You have deleted your post " . $this->publication->slug . ".

                    Want to stay visible? Create a new one anytime.

                    Create a new post → https://flink.ma/publication/@" . $this->getProfileSlug() . "

                    See you soon on Flink.ma!";
        }

    }

    protected function getImage(): string {
        return "https://media.flink.ma/design-icons/flink-icon-fit.png"; // Optional: child classes don't have to implement this
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