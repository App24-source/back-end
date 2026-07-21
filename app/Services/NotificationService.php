<?php

namespace App\Services;

use Illuminate\Notifications\Notification;
use App\Models\User;
use App\Models\Publication;
use App\Models\Message;
use App\Notifications\AccountCreatedNotification;
use App\Notifications\FirstPublicationEmailReminder;
use App\Notifications\NewPublicationNotificationUser;
use App\Notifications\NewPublicationNotificationEmailUser;
use App\Notifications\PublicationRejectedNotification;
use App\Notifications\PublicationRejectedNotificationEmail;
use App\Notifications\PublicationDeletedNotification;
use App\Notifications\PublicationDeletedNotificationEmail;
use App\Notifications\PublicationSauvgardedNotification;
use App\Notifications\PublicationPartageNotification;
use App\Notifications\NewPublicationNotificationFollowers;
use App\Notifications\PublicationViewMilestoneNotification;
use App\Notifications\NewFollowerNotification;
use App\Notifications\NewFollowers24GroupedNotification;
use App\Notifications\NewMessageNotification;
use App\Notifications\SystemNotification;
use App\Notifications\SystemEmailNotification;
use App\Notifications\PublicationClickWhatsAppNotification;
use App\Notifications\PublicationClickAppelNotification;
use App\Notifications\PublicationPartageGroupedNotification;
use App\Notifications\PublicationClickWhatsAppGroupedNotification;
use App\Notifications\PublicationClickAppelGroupedNotification;

class NotificationService
{
    // User Events
    public function sendAccountCreatedNotification($profile): void
    {
        try {
            // 1. Send immediate push notification
            $profile->notify(
                (new AccountCreatedNotification($profile))
                    ->delay(now()->addMinutes(1))
            );
            
            // // 2. Schedule reminder for 3 minutes later
            // $profile->notify(
            //     (new FirstPublicationEmailReminder($profile))
            //         ->delay(now()->addMinutes(3)) // Test with 10 seconds first
            // );

            
            $notification = new FirstPublicationEmailReminder($profile);
            // Delay the notification by 24 hours but still check publications at send time
            $profile->notify($notification->delay(now()->addSeconds(10)));
            
        } catch (\Exception $e) {
            logger()->error('Notification failed: '.$e->getMessage());
        }
    }

    // Publication Events
    public function sendNewPublicationNotification(Publication $publication): void
    {
        if($publication->etab_id) $profile = $publication->etablissement;
        else $profile = $publication->user;

        $followers = collect();

        if ($publication->etab_id && $publication->etablissement) {
            // Get users following this establishment (excluding author)
            $followers = $publication->etablissement->followersUsers()
                ->where('users.id', '!=', $publication->user_id)
                ->get();
            
            // If you also want establishments following this establishment
            $establishmentFollowers = $publication->etablissement->followersEtablissements()
            ->where('etablissements.id', '!=', $publication->etab_id)
            ->get();
                // ->with('users') // Assuming establishments have users
                // ->get()
                // ->pluck('users')
                // ->flatten()
                // ->unique('id')
                // ->where('id', '!=', $publication->user_id);
            
            $followers = $followers->merge($establishmentFollowers);
        } elseif ($publication->user) {
            // Get users following this user (excluding author)
            $followers = $publication->user->followersUsers()
                ->where('users.id', '!=', $publication->user_id)
                ->get();
            
            // If you want establishments following this user
            $establishmentFollowers = $publication->user->followersEtablissements()
            ->where('etablissements.id', '!=', $publication->etab_id)
            ->get();
                // ->with('users')
                // ->get()
                // ->pluck('users')
                // ->flatten()
                // ->unique('id')
                // ->where('id', '!=', $publication->user_id);
            
            $followers = $followers->merge($establishmentFollowers);
        }

        // Notify all unique followers
        $followers->unique('id')->each(function ($follower) use ($publication) {
            try {
                $follower->notifyNow(new NewPublicationNotificationFollowers($publication, $follower));
            } catch (\NotificationChannels\Fcm\Exceptions\CouldNotSendNotification $e) {
                // Log the error and continue with other notifications
                \Log::error("FCM notification failed for user {$follower->id}: " . $e->getMessage());
            }
        });


        $profile->notifyNow(new NewPublicationNotificationUser($publication));
        $profile->notifyNow(new NewPublicationNotificationEmailUser($publication));
    }

    public function sendPublicationRejectedNotification(Publication $publication): void {
        if($publication->etab_id) $profile = $publication->etablissement;
        else $profile = $publication->user;

        $profile->notify(
            new PublicationRejectedNotification($publication)
        );
        $profile->notify(
            new PublicationRejectedNotificationEmail($publication)
        );
    }

    public function sendPublicationDeletedNotification(Publication $publication): void {
        if($publication->etab_id) $profile = $publication->etablissement;
        else $profile = $publication->user;

        $profile->notify(
            new PublicationDeletedNotification($publication)
        );
        $profile->notify(
            new PublicationDeletedNotificationEmail($publication)
        );
    }

    public function sendPublicationSauvgardedNotification(Publication $publication, $sauvgarededProfile): void {
        if($publication->etab_id) $profile = $publication->etablissement;
        else $profile = $publication->user;
        $profile->notify(
            new PublicationSauvgardedNotification($publication, $sauvgarededProfile)
        );
    }

    public function sendPublicationPartageNotification(Publication $publication): void {
        if($publication->etab_id) $profile = $publication->etablissement;
        else $profile = $publication->user;
        $profile->notifyNow(
            new PublicationPartageNotification($publication)
        );
    }

    public function sendPublicationPartageGroupedNotification($profile): void {
        $profile->notify(
            (new PublicationPartageGroupedNotification($profile))
            ->delay(now()->addHours(24))
        );
    }

    public function sendPublicationClickWhatsAppNotification(Publication $publication): void {
        if($publication->etab_id) $profile = $publication->etablissement;
        else $profile = $publication->user;
        $profile->notifyNow(
            new PublicationClickWhatsAppNotification($publication)
        );
    }

    public function sendPublicationClickWhatsAppGroupedNotification($profile): void {
        $profile->notify(
            (new PublicationClickWhatsAppGroupedNotification($profile))
            ->delay(now()->addHours(24))
        );
    }

    public function sendPublicationClickAppelNotification(Publication $publication): void {
        if($publication->etab_id) $profile = $publication->etablissement;
        else $profile = $publication->user;
        $profile->notifyNow(
            new PublicationClickAppelNotification($publication)
        );
    }

    public function sendPublicationClickAppelGroupedNotification($profile): void {
        $profile->notify(
            (new PublicationClickAppelGroupedNotification($profile))
            ->delay(now()->addHours(24))
        );
    }

    public function sendPublicationViewMilestoneNotification(Publication $publication, $viewCount): void {
        if($publication->etab_id) $profile = $publication->etablissement;
        else $profile = $publication->user;

        $profile->notify(
            new PublicationViewMilestoneNotification($publication, $viewCount)
        );
    }

    // Social Events
    public function sendNewFollowerNotification($followingProfile, $followerProfile): void {

        $followerProfile->notifyNow(new NewFollowerNotification($followingProfile, $followerProfile));
        // $followerProfile->notify(
        //     (new NewFollowers24GroupedNotification($followerProfile))
        //     // ->delay(now()->addHours(24))
        //     ->delay(now()->addSeconds(30))
        // );
    }

    public function sendNewMessageNotification($senderProfile, $receiverProfile, $message, $publication): void {
        $receiverProfile->notify(
            new NewMessageNotification($senderProfile, $receiverProfile, $message, $publication)
        );
    }

    // system Notification
    public function sendSystemNotification($send_type, $profile, $type, $title, $message, $image, $link, $btn1, $btn2): void {
        if($send_type == 'email') {
            $profile->notify(new SystemEmailNotification($profile, $type, $title, $message, $image, $link));
        } elseif($send_type == 'push') {
            $profile->notify(new SystemNotification($profile, $type, $title, $message, $image, $link, $btn1, $btn2));
        } else {
            $profile->notify(new SystemNotification($profile, $type, $title, $message, $image, $link, $btn1, $btn2));
            $profile->notify(new SystemEmailNotification($profile, $type, $title, $message, $image, $link));
        }
    }

    // // system Notification
    // public function sendMarketingNotification($profile, $title, $message): void {
    //     $profile->notify(
    //         new MarketingNotification($profile, $title, $message)
    //     );  
    // }

    // Security Events
    public function sendSuspiciousLoginNotification(
        User $user,
        string $ip,
        string $location,
        string $device
    ): void {
        $user->notify(new SuspiciousLoginNotification($ip, $location, $device));
    }

    public function sendPasswordChangedNotification(User $user): void
    {
        $user->notify(new PasswordChangedNotification());
    }

    // View Milestones (Example of batch notification)
    public function checkViewMilestones(Publication $publication): void
    {
        $milestones = [100, 500, 1000, 5000];
        $views = $publication->views()->count();
        
        foreach ($milestones as $milestone) {
            if ($views >= $milestone && $views < $milestone + 100) {
                if (!$this->alreadyNotified($publication, 'view_milestone', $milestone)) {
                    $publication->user->notify(
                        new PublicationMilestoneNotification($publication, $milestone)
                    );
                    $this->markAsNotified($publication, 'view_milestone', $milestone);
                }
            }
        }
    }

    protected function alreadyNotified(
        Publication $publication,
        string $type,
        mixed $value
    ): bool {
        return $publication->notifications()
            ->where('data->type', $type)
            ->where('data->value', $value)
            ->exists();
    }

    protected function markAsNotified(
        Publication $publication,
        string $type,
        mixed $value
    ): void {
        // Implement your notification tracking logic here
    }
}