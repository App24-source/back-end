<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\Etablissement;
class BounceEmailController extends Controller
{
    public function handleIncomingEmail(Request $request)
    {
        try {
            // Get the raw content
            $rawEmail = $request->getContent();
            
            if (empty($rawEmail)) {
                return response()->json([
                    'status' => 'error', 
                    'message' => 'Empty content'
                ], 400);
            }

            // Simple email extraction
            $failedEmail = $this->extractFailedEmail($rawEmail);
            
            if ($failedEmail) {
                // Update database
                $profile = User::where('email', $failedEmail)->first();
                if(!$profile) {
                   $profile = Etablissement::where('email', $failedEmail)->first();
                }
                if ($profile) {
                    $profile->update([
                        'email_status' => 'failed',
                    ]);
                }
                
                return response()->json([
                    'status' => 'success',
                    'message' => 'Email marked as failed',
                    'email' => $failedEmail,
                    "profile" => $profile
                ]);
            }

            return response()->json([
                'status' => 'no_action',
                'message' => 'No valid email found in bounce message'
            ]);

        } catch (\Exception $e) {
            Log::error('Bounce processing error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Internal server error'
            ], 500);
        }
    }
    
    /**
     * Extract failed email address from bounce content
     */
    private function extractFailedEmail($bounceContent)
    {
        // Convert to lowercase for case-insensitive matching
        $content = strtolower($bounceContent);
        
        // Patterns that indicate the FAILED email (not the sender)
        $failurePatterns = [
            '/failed to (.+@.+\.\w+)/i',
            '/undeliverable to (.+@.+\.\w+)/i',
            '/delivery failed for (.+@.+\.\w+)/i',
            '/could not deliver to (.+@.+\.\w+)/i',
            '/recipient: (.+@.+\.\w+)/i',
            '/to: (.+@.+\.\w+)/i',
            '/address: (.+@.+\.\w+)/i',
        ];
        
        // First, try to find the FAILED recipient using patterns
        foreach ($failurePatterns as $pattern) {
            if (preg_match($pattern, $bounceContent, $matches)) {
                $email = trim($matches[1]);
                if ($this->isValidEmail($email) && !$this->isBounceEmail($email)) {
                    return $email;
                }
            }
        }
        
        // Fallback: find all emails and exclude common bounce addresses
        preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $bounceContent, $matches);
        
        $commonBounceSenders = [
            'postmaster@', 'mailer-daemon@', 'mailer@', 
            'automated@', 'noreply@', 'no-reply@',
            'bounces@', 'bounce@'
        ];
        
        foreach ($matches[0] as $email) {
            $emailLower = strtolower($email);
            
            // Skip if it's a common bounce sender or your bounce address
            $isBounceSender = false;
            foreach ($commonBounceSenders as $bounceSender) {
                if (strpos($emailLower, $bounceSender) === 0) {
                    $isBounceSender = true;
                    break;
                }
            }
            
            if (!$isBounceSender && !$this->isBounceEmail($email)) {
                return $email;
            }
        }
        
        return null;
    }

    /**
     * Check if email is a bounce email address
     */
    private function isBounceEmail($email)
    {
        $bounceEmail = strtolower(env('BOUNCE_EMAIL', 'bounces@flink.ma'));
        $fromEmail = strtolower(env('MAIL_FROM_ADDRESS', 'noreply@flink.ma'));
        
        return strtolower($email) === $bounceEmail || strtolower($email) === $fromEmail;
    }

    /**
     * Check if email is valid
     */
    private function isValidEmail($email)
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}