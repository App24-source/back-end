<?php

namespace App\Notifications;

use App\Models\Publication;
use NotificationChannels\Fcm\FcmChannel;
use Illuminate\Contracts\Queue\ShouldQueue;


class NewPublicationNotificationEmailUser extends BaseNotification
{
    public function __construct(public Publication $publication) {}

    protected function getType(): string { return 'new_publication_user'; }
    protected function getTitle(): string {
        switch ($this->getLangue()) {
            case 'fr':
                return 'Votre publication est maintenant visible';
            case 'ar':
                return 'تم قبول إعلانك وهو مرئي الآن';
            default:
                return "Your post is now live";
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
                return "
                    إعلانك " . $this->publication->slug . " تمت الموافقة عليه من قبل فريقنا.
                    وهو مرئي الآن لجميع مستخدمي Flink.ma.

                    شاركه الآن لزيادة عدد المشاهدات والتفاعل.

                    ➜ عرض الإعلان → https://flink.ma/publication/" . $this->publication->slug . "

                    شكراً لثقتك،
                    ";

            default:
                return "Your post " . $this->publication->slug . " has been approved by our team.
                    It is now visible to all Flink.ma users.

                    Share it now to increase your reach.

                    ➜ View your post → https://flink.ma/publication/" . $this->publication->slug . "

                    Thanks for using Flink.ma,";
        }
    }

    protected function getImage(): string {
        return "https://media.flink.ma/design-icons/flink-icon-fit.png"; // Optional: child classes don't have to implement this
    }

    public function getGreeting(): ?string {  
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

    protected function getLink(): string { return 'https://flink.ma/publication/' . $this->publication->slug . '/'; }

    public function via($notifiable): array {
    
        if($notifiable->email_status == 'pending') {
            \Log::info('toMail method called for: ' . $notifiable->email);
            // Perform the DNS check HERE, before building the message
            $email = $notifiable->email; // Get the email from the notifiable
            $domain = substr(strrchr($email, "@"), 1);
            $hasMxRecord = checkdnsrr($domain, 'MX');

            if (!$hasMxRecord) {
                \Log::warning('DNS check failed for: ' . $email);
                // Update the user status immediately
                if ($notifiable) {
                    $notifiable->update([
                        'email_status' => 'invalid',
                    ]);
                }
            } else {
                \Log::info('DNS check passed for: ' . $email);
                $notifiable->update(['email_status' => 'valid']);
            }
        }
        if($notifiable->email_status == 'valid') return  ['mail'];
        else return [];
    }

    protected function getAuthorName(): string {
        if($this->publication->etab_id) return $this->publication->etablissement->nom;
        return $this->publication->user->first_name . ' ' . $this->publication->user->last_name;
    }

    protected function getLangue(): string {
        if($this->publication->etab_id) return $this->publication->etablissement->langue;
        return $this->publication->user->langue;
    }

    protected function getData(): array {
        return [];
    }
}