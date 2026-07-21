<?php

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Support\Facades\Log;
use App\Models\User;

class HandleFailedEmailNotification implements ShouldQueue
{
    use InteractsWithQueue;

    // Patterns for different types of email failures
    private $errorPatterns = [
        'address_not_found' => [
            '/user unknown/i',
            '/mailbox not found/i',
            '/no such user/i',
            '/adresse introuvable/i',
            '/destinataire inconnu/i',
            '/compte inexistant/i',
            '/recipient not found/i',
            '/550.*5\.1\.1/i', // Gmail pattern
            '/550.*recipient/i',
            '/551.*user/i',
            '/553.*mailbox/i',
        ],
        'domain_invalid' => [
            '/domain not found/i',
            '/no mx record/i',
            '/invalid domain/i',
            '/domain does not exist/i',
            '/host or domain not found/i',
            '/550.*domain/i',
        ],
        'mailbox_full' => [
            '/mailbox full/i',
            '/quota exceeded/i',
            '/storage limit/i',
            '/552.*exceeded/i',
            '/552.*quota/i',
        ],
        'blocked' => [
            '/blocked/i',
            '/rejected/i',
            '/spam/i',
            '/blacklist/i',
            '/policy/i',
        ]
    ];

    /**
     * Handle the event.
     */
    public function handle(NotificationFailed $event)
    {
        Log::info('🔴 HandleFailedEmailNotification listener triggered');

        $notifiable = $event->notifiable;
        $exception = $event->data['exception'] ?? null;

        if (!$notifiable instanceof User || !$exception instanceof \Exception) {
            Log::warning('Listener conditions not met', [
                'is_user' => $notifiable instanceof User,
                'has_exception' => !is_null($exception)
            ]);
            return;
        }

        $errorMessage = $exception->getMessage();
        $email = $notifiable->email;

        Log::debug('Email failure analysis', [
            'email' => $email,
            'error' => $errorMessage,
            'exception_class' => get_class($exception)
        ]);

        // Check if this is a DNS failure from our toMail method
        if (str_contains($errorMessage, 'Invalid email domain')) {
            Log::info('DNS failure detected from toMail method');
            // Status was already set to 'invalid' in toMail method
            return;
        }

        // Analyze the error message to determine failure type
        $failureType = $this->analyzeFailureType($errorMessage);

        switch ($failureType) {
            case 'address_not_found':
                $this->handleAddressNotFound($notifiable, $errorMessage);
                break;
                
            case 'domain_invalid':
                $this->handleDomainInvalid($notifiable, $errorMessage);
                break;
                
            case 'mailbox_full':
                $this->handleMailboxFull($notifiable, $errorMessage);
                break;
                
            case 'blocked':
                $this->handleBlocked($notifiable, $errorMessage);
                break;
                
            default:
                $this->handleGenericFailure($notifiable, $errorMessage);
                break;
        }
    }

    /**
     * Analyze the error message to determine failure type
     */
    private function analyzeFailureType($errorMessage)
    {
        foreach ($this->errorPatterns as $type => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $errorMessage)) {
                    Log::debug("Pattern matched: {$type} - {$pattern}");
                    return $type;
                }
            }
        }
        
        return 'generic';
    }

    /**
     * Handle "Address Not Found" errors
     */
    private function handleAddressNotFound($user, $errorMessage)
    {
        $user->update([
            'email_status' => 'failed',
            'email_error' => 'Address not found: ' . substr($errorMessage, 0, 150)
        ]);
        
        Log::warning('📍 Address not found', [
            'email' => $user->email,
            'error' => $errorMessage
        ]);
    }

    /**
     * Handle invalid domain errors
     */
    private function handleDomainInvalid($user, $errorMessage)
    {
        $user->update([
            'email_status' => 'invalid',
            'email_error' => 'Invalid domain: ' . substr($errorMessage, 0, 150)
        ]);
        
        Log::warning('🌐 Invalid domain', [
            'email' => $user->email,
            'error' => $errorMessage
        ]);
    }

    /**
     * Handle mailbox full errors
     */
    private function handleMailboxFull($user, $errorMessage)
    {
        $user->update([
            'email_status' => 'failed',
            'email_error' => 'Mailbox full: ' . substr($errorMessage, 0, 150)
        ]);
        
        Log::warning('📦 Mailbox full', [
            'email' => $user->email,
            'error' => $errorMessage
        ]);
    }

    /**
     * Handle blocked email errors
     */
    private function handleBlocked($user, $errorMessage)
    {
        $user->update([
            'email_status' => 'failed',
            'email_error' => 'Blocked: ' . substr($errorMessage, 0, 150)
        ]);
        
        Log::warning('🚫 Email blocked', [
            'email' => $user->email,
            'error' => $errorMessage
        ]);
    }

    /**
     * Handle generic failures
     */
    private function handleGenericFailure($user, $errorMessage)
    {
        $user->update([
            'email_status' => 'failed',
            'email_error' => substr($errorMessage, 0, 200)
        ]);
        
        Log::warning('❌ Generic email failure', [
            'email' => $user->email,
            'error' => $errorMessage
        ]);
    }

    /**
     * Extract SMTP code from error message
     */
    private function extractSmtpCode($message)
    {
        preg_match('/\b(\d{3})\b/', $message, $matches);
        return $matches[1] ?? 'unknown';
    }
}