<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use App\Mail\ResetCodeMail;

class PasswordResetLinkController extends Controller
{
    /**
     * Handle an incoming password reset link request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): JsonResponse {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        // We will send the password reset link to this user. Once we have attempted
        // to send the link, we will examine the response then see the message we
        // need to show to the user. Finally, we'll send out a proper response.
        $status = Password::sendResetLink(
            $request->only('email')
        );

        if ($status != Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json(['status' => __($status)]);
    }

    // public function sendResetCode(Request $request)
    // {
    //     $request->validate([
    //         'email' => 'required|email|exists:users,email',
    //     ]);

    //     $user = User::where('email', $request->email)->first();

    //     // Generate a random 6-digit code
    //     $resetCode = Str::random(6);

    //     // Store the code in the password_resets table
    //     DB::table('password_reset_tokens')->updateOrInsert(
    //         ['email' => $request->email],
    //         [
    //             'email' => $request->email,
    //             'token' => bcrypt($resetCode),
    //             'created_at' => now(),
    //         ]
    //     );

    //     try {
    //         // Send the code via email
    //         Mail::send('emails.reset_code', ['code' => $resetCode , 'email' => $user->email], function ($message) use ($request) {
    //             $message->to($request->email);
    //             $message->subject('Demande de réinitialisation du mot de passe');
    //         });
        
    //         return response()->json(['message' => 'Code de réinitialisation envoyé à votre e-mail.','code' => $resetCode ,], 200);
    //     } catch (\Exception $e) {
    //         // Handle the error
    //         return response()->json(['error' => 'Erreur lors de l\'envoi de l\'e-mail. Veuillez réessayer plus tard.'], 500);
    //     }
    // }
    public function sendResetCode(Request $request) {
        $request->validate([
            'email' => 'required|email|exists:users,email',
        ], [
            'email.required' => 'L\'adresse e-mail est requise.',
            'email.email' => 'L\'adresse e-mail doit être une adresse e-mail valide.',
            'email.exists' => 'Cette adresse e-mail n\'existe pas.',
        ]);
    
        $user = User::where('email', $request->email)->first();
    
        // Générer un code aléatoire à 6 caractères alphanumériques
        $resetCode = $this->generateAlphanumericCode(6);
    
        // Stocker le code dans la table password_resets
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $request->email],
            [
                'email' => $request->email,
                'token' => bcrypt($resetCode),
                'created_at' => now(),
            ]
        );
        
        try {
            Mail::to($user->email)->send(new ResetCodeMail($resetCode, $user->email));
            return response()->json(['message' => 'Code de réinitialisation envoyé à votre e-mail.'], 200);
        } catch (\Exception $e) {
            \Log::error('Erreur envoi email de réinitialisation : ' . $e->getMessage(), [
                'email' => $user->email,
                'code' => $resetCode,
                'stack' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Une erreur est survenue lors de l\'envoi de l\'e-mail. Veuillez réessayer plus tard.'
            ], 500);
        }
        // try {
        //     // Envoyer le code par e-mail
        //     Mail::send('emails.reset_code', ['code' => $resetCode, 'email' => $user->email], function ($message) use ($request) {
        //         $message->to($request->email);
        //         $message->subject('Demande de réinitialisation du mot de passe');
        //     });
        //     return response()->json(['message' => 'Code de réinitialisation envoyé à votre e-mail.','code' => $resetCode], 200);
        // } catch (\Exception $e) {
        //     return response()->json(['error' => 'Une erreur est survenue lors de l\'envoi de l\'e-mail. Veuillez réessayer plus tard.'], 500);
        // }
    }
    
    private function generateAlphanumericCode($length = 6) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        return substr(str_shuffle(str_repeat($characters, ceil($length / strlen($characters)))), 0, $length);
    }
    
    public function checkCode(Request $request) {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'code' => 'required|string',
        ], [
            'email.required' => 'L\'adresse e-mail est requise.',
            'email.email' => 'L\'adresse e-mail doit être une adresse e-mail valide.',
            'email.exists' => 'Cette adresse e-mail n\'existe pas.',
            'code.required' => 'Le code de réinitialisation est requis.',
        ]);
    
        $resetRecord = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();
    
        if (!$resetRecord || !\Hash::check($request->code, $resetRecord->token)) {
            return response()->json(['message' => 'Code invalide'], 400);
        }
    
      
        // return response()->json(['token' => $token, 'message' => 'Code de réinitialisation valide.'], 200);
    }


    public function resetPassword(Request $request) {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'code' => 'required|string',
            'password' => 'required|string|min:8',
        ], [
            'email.required' => 'L\'adresse e-mail est requise.',
            'email.email' => 'L\'adresse e-mail doit être une adresse e-mail valide.',
            'email.exists' => 'Cette adresse e-mail n\'existe pas.',
            'code.required' => 'Le code de réinitialisation est requis.',
            'password.required' => 'Le mot de passe est requis.',
            // 'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
        ]);
    
        $resetRecord = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();
    
        if (!$resetRecord || !\Hash::check($request->code, $resetRecord->token)) {
            return response()->json(['message' => 'Code ou e-mail invalide.'], 400);
        }
    
        // Mettre à jour le mot de passe de l'utilisateur
        $user = User::where('email', $request->email)->first();
        $user->password = Hash::make($request->password);
        $user->save();
    
        // Connecter l'utilisateur
        Auth::login($user);
        
        // Créer un token pour l'utilisateur nouvellement enregistré
        $token = $user->createToken('token')->plainTextToken;
    
        // Supprimer l'enregistrement de réinitialisation pour que le code ne puisse pas être réutilisé
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();
    
        return response()->json(['token' => $token, 'message' => 'Le mot de passe a été réinitialisé avec succès.'], 200);
    }
    
}
