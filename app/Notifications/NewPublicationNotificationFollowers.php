<?php


namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

use App\Models\Publication;  // Add this import
use NotificationChannels\Fcm\FcmMessage;  // Also make sure these are imported
use NotificationChannels\Twilio\TwilioWhatsAppMessage;  // If you're using WhatsApp
use NotificationChannels\Fcm\FcmChannel;

class NewPublicationNotificationFollowers extends BaseNotification
{

    public function __construct(public Publication $publication, public $follower) {}

    public function getType(): string
    {
        return 'new_publication';
    }

    public function getTitle(): string
    {
        switch ($this->follower->langue) {
            case 'fr':
                return $this->getAuthorName() . " vient de publier ". $this->typePublication() ." !";
            case 'ar':
                return $this->getAuthorName() . " نشر  ". $this->typePublication() ." !";
            default:
                return $this->getAuthorName() . " just posted ". $this->typePublication() ." !";
        }
        // return 'New Publication from ' . $this->getAuthorName();
    }

    public function getMessage(): string
    {
        switch ($this->follower->langue) {
            case 'fr':
                return 'Allez voir ce qui a été partagé maintenant.';
            case 'ar':
                return 'اكتشفه الآن وتفاعل معه';
            default:
                return 'Go check it out now.';
        }
        // return 'A profile you follow has published new content: ' . $this->publication->title;
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

    protected function getAuthorName(): string
    {
        if($this->publication->etab_id) return $this->publication->etablissement->nom;
        return $this->publication->user->last_name;
    }

    protected function typePublication(): string
    {
        switch ($this->publication->user->langue) {
            case 'fr':
                return $this->publication->is_marketplace 
                    ? "un produit"
                    : ($this->publication->images ? "une image" : "une vidéo");
            case 'ar':
                return $this->publication->is_marketplace 
                    ? "منتج"
                    : ($this->publication->images ? "صورة" : "فيديو");
            default:
                return $this->publication->is_marketplace 
                    ? "a product"
                    : ($this->publication->images ? "an image" : "a video");
        }
    }


    public function via($notifiable): array {
         return  ['database', FcmChannel::class];
    }

    // protected function getLangue(): string
    // {
    //     if($this->follower->langue) return $this->publication->etablissement->langue;
    //     return $this->publication->user->langue;
    // }

    protected function getData(): array {
        return [
            'type' => $this->getType(),
            'profile' => [
                'id' => $this->publication->etab_id ? $this->publication->etablissement->id:  $this->publication->user->id,
                'name' => $this->getAuthorName(),
                'slug' => $this->publication->etab_id ? $this->publication->etablissement->slug:  $this->publication->user->slug,
                'avatar' => $this->publication->etab_id ? $this->publication->etablissement->logo:  $this->publication->user->avatar,
                'type' => $this->publication->etab_id ? "etablissement":  "user",
            ],
            'image' => $this->getImage(),
            'link' => $this->getLink(),
            'type_publication' => $this->publication->is_marketplace ? "product" : ($this->publication->images ? "image" : "video"),
        ];
    }
}
