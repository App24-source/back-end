<?php

namespace App\Http\Controllers\Auth;

use Log;
use Hash;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Foundation\Auth\EmailVerificationRequest;


class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(EmailVerificationRequest $request): RedirectResponse {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(
                config('app.frontend_url').RouteServiceProvider::HOME.'?verified=1'
            );
        }

        if ($request->user()->markEmailAsVerified()) {
            event(new Verified($request->user()));
        }

        return redirect()->intended(
            config('app.frontend_url').RouteServiceProvider::HOME.'?verified=1'
        );
    }

    //Cutom functions -- Yassine OUJAMA

    public function sendVerifyCode(Request $request) {
    
        $user = Auth::user();

        if(!$user){
            return response()->json(['message' => 'Utilisateur no connecter'], 400);
        }

        // Generate a random 6-digit code
        $verifyCode = Str::random(6);
    
        // Store the code in the password_resets table
        DB::table('email_verify_tokens')->updateOrInsert(
            ['email' => $user->email],
            [
                'email' => $user->email,
                'token' => bcrypt($verifyCode),
                'created_at' => now(),
            ]
        );
    
        // Send the code via email
        Mail::send('emails.verify-email', ['code' => $verifyCode , 'email' => $user->email , 'nom' => $user->first_name], function ($message) use ($user) {
            $message->to($user->email);
            $message->subject('Demande de verification du adresse mail');
        });
    
        return response()->json(['message' => 'Code de verification a envoyé à votre e-mail.','code' => $verifyCode], 200);
    }

    public function verifyEmail(Request $request) {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'code' => 'required|string',
        ]);
    
        $user = Auth::user();

        if ($user->email !== $request->email ) {
            return response()->json(['message' => "L'adresse mail est incorrect"], 400);
        }
    
        // Retrieve the record from the email_verify_tokens table
        $verificationRecord = DB::table('email_verify_tokens')
            ->where('email', $request->email)
            ->first();
    
        // Check if the record exists and if the code matches
        if (!$verificationRecord || !Hash::check($request->code, $verificationRecord->token)) {
            return response()->json(['message' => 'Code ou email invalide.'], 400);
        }
    
        // Mark the user's email as verified
        $user->email_verified_at = now();
        $user->save();
    
        // Delete the verification record so the code can't be reused
        DB::table('email_verify_tokens')->where('email', $request->email)->delete();
    
        return response()->json(['message' => "L'adresse mail a été vérifiée avec succès."], 200);
    }
}
