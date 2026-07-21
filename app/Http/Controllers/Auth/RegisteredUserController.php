<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Support\Facades\Request as RequestUser;
use Jenssegers\Agent\Agent;
use Illuminate\Support\Str;
use App\Models\Etablissement;
use App\Services\NotificationService;

use Illuminate\Support\Facades\Validator; // <-- For custom message if needed
use App\Rules\ValidEmailWithDns; // Import your custom rule


class RegisteredUserController extends Controller
{
    /**
     * Handle an incoming registration request.
     */
    public function store(Request $request) {
        
        // Validate input

    //     $validator = Validator::make($request->all(), [
    //     'first_name' => ['required', 'string', 'max:15'],
    //     'last_name' => ['required', 'string', 'max:15'],
    //     'email' => [
    //         'required',
    //         'string',
    //         'email', // Basic format check
    //         'max:255',
    //         new ValidEmailWithDns(), // Use custom DNS rule
    //         'unique:'.User::class,
    //         // You can remove the 'regex' rule as Rule::email() is more robust
    //     ],
    //     'password' => ['required', 'confirmed', Rules\Password::defaults()],
    // ], [
    //     'first_name.required' => 'Prénom requis.',
    //     'last_name.required' => 'Nom requis.',
    //     'email.required' => 'Email requis.',
    //     'email.unique' => 'Email déjà utilisé.',
    //     'password.required' => 'Mot de passe requis.',
    //     'password.confirmed' => 'Les mots de passe ne correspondent pas.',
    // ]);

    // // Check if validation fails
    // if ($validator->fails()) {
    //     return response()->json([
    //         'message' => 'Données invalides.',
    //         'errors' => $validator->errors()
    //     ], 422); // HTTP 422 Unprocessable Entity
    // }


        $request->validate([
            'first_name' => ['required', 'string', 'max:15'],
            'last_name' => ['required', 'string', 'max:15'],
            // 'type' => ['required', 'string', 'max:50', 'in:pro,personal'],
            // 'tele' => [
            //     'required',
            //     'string',
            //     'max:255',
            //     'regex:/^(06|07|08|05)[0-9]{8}$/',
            //     'unique:'.User::class,
            // ],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                'regex:/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/',
                'unique:'.User::class,
            ],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ], [
            'first_name.required' => 'Prénom requis.',
            'last_name.required' => 'Nom requis.',
            // 'type.required' => 'Type requis.',
            // 'tele.required' => 'Téléphone requis.',
            // 'tele.regex' => 'Format téléphone invalide.',
            // 'tele.unique' => 'Téléphone déjà utilisé.',
            'email.required' => 'Email requis.',
            'email.regex' => 'Format email invalide.',
            'email.unique' => 'Email déjà utilisé.',
            'password.required' => 'Mot de passe requis.',
            'password.confirmed' => 'Les mots de passe ne correspondent pas.',
        ]);
        
        // $userName = $this->generateSlug($request->first_name, $request->last_name);
        // Create user
        $user = User::create([
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'slug' => $this->generateSlug($request->first_name, $request->last_name),
            // 'type' => $request->type,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        // Trigger registration event
        event(new Registered($user));

        // Log the user in
        Auth::login($user);

        // add connexion champ safely
        try {
            $info = $this->getUserInfo();
            $user->connexions()->create([
                'origine' => 'email',
                'ip' => $info['ip'],
                'adresse_mac' => $info['mac'],
                'navigateur' => $info['navigateur'],
                'os' => $info['os'],
                'device' => $info['device'],
                'latitude' => $info['geoData']['lat'],
                'longitude' => $info['geoData']['lon'],
                'pays' => $info['geoData']['country'],
                'ville' => $info['geoData']['city'],
                'isp' => $info['geoData']['isp'],
            ]);
        } catch (\Exception $dbEx) {
            // Fail silently so the user can still successfully register
        }

        // Create token
        $token = $user->createToken('token')->plainTextToken;

        // notifications service
        app(NotificationService::class)->sendAccountCreatedNotification($user);

        // Success response
        return response([
            'message' => 'Compte créé avec succès.',
            'token' => $token,
            'user' => $user,
        ], 200);
    }

    private static function generateSlug($firstname, $lastname) {
        $name = $firstname . '_' . $lastname;
        $slug = Str::slug($name, '_');  // Generate slug from names

        // Ensure the slug is unique by checking the database
        $slugBase = $slug;
        $count = 1;

        while (User::where('slug', $slug)->exists() || Etablissement::where('slug', $slug)->exists()) {
            $slug = $slugBase . '_' . $count;  // Append a number to make it unique
            $count++;
        }

        return $slug;
    }



    private function getUserInfo() {
        $ip = RequestUser::ip(); // Get the IP address of the user

        // Get MAC address (Windows only; alternative required for Linux/MacOS)
        $mac = false; // Default value
        // Check if the operating system is Windows
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows: Use getmac command
            $mac = exec('getmac');
            $mac = strtok($mac, ' '); // Extract the MAC address part
        } else {
            // Linux/macOS: Use 'ifconfig' or 'ip link show' (fallback for non-Windows systems)
            $mac = exec('ifconfig -a | grep -Po \'([a-f0-9]{2}(:[a-f0-9]{2}){5})\''); // For Linux/macOS
            if (empty($mac)) {
                // If ifconfig fails, try ip link show (for modern Linux systems)
                $mac = exec('ip link show | awk \'/ether/ {print $2}\'');
            }
        }
        if (empty($mac)) {
            // In case no MAC address is found, set a default value
            $mac = "Not Available";
        }
    
        // Initialize the Agent for browser, OS, and device detection
        $agent = new Agent();
        $navigateur = $agent->browser(); // Get browser
        $os = $agent->platform(); // Get operating system
        $device = $agent->isMobile() ? 'Mobile' : ($agent->isTablet() ? 'Tablet' : 'Desktop'); // Detect device type
    
        // Fetch geolocation data from ip-api.com using cURL
        $geoData = $this->fetchGeoData($ip);
    
        return [
            "ip" => $ip,
            "mac" => $mac,
            "navigateur" => $navigateur,
            "os" => $os,
            "device" => $device,
            "geoData" => $geoData,
        ];
    }

    // Function to fetch geolocation data using cURL
    private function fetchGeoData($ip) {
        $url = "http://ip-api.com/json/{$ip}?fields=country,city,lat,lon,isp";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Set a timeout to prevent hanging
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Disable SSL verification if necessary

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            error_log('cURL error: ' . curl_error($ch)); // Log error if request fails
            curl_close($ch);
            return null; // Return null if request fails
        }

        curl_close($ch);

        return json_decode($response, true);
    }
}
