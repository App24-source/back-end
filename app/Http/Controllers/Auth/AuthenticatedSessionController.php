<?php

namespace App\Http\Controllers\Auth;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Models\Etablissement;
use Illuminate\Support\Facades\Request as RequestUser;
use Jenssegers\Agent\Agent;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthenticatedSessionController extends Controller
{
    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): Response {
        try {
            // Validate the request for the email field
            $validated = $request->validate([
                'email' => 'required|email',
                'password' => 'required|string',
            ]);

            $request->authenticate();

            // add connexion champ with success
            try {
                $info = $this->getUserInfo();
                $request->user()->connexions()->create([
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
                // Fail silently so the user can still successfully log in
            }
    
            $token = $request->user()->createToken('token')->plainTextToken;
            return response(['token' => $token], 200);
        } catch (ValidationException $e) {

            $errors = $e->errors();
            
            $errorMessage = 'Erreur de validation: ';
            foreach ($errors as $field => $error) {
                switch ($field) {
                    case 'email':
                        $errorMessage .= 'Email invalide. ';
                        break;
                    case 'password':
                        if (in_array('password', $error)) {
                            $errorMessage .= 'Le mot de passe est invalide. ';
                        }
                        if (in_array('min', $error)) {
                            $errorMessage .= 'Le mot de passe doit contenir au moins 8 caractères. ';
                        }
                        break;
                    default:
                        $errorMessage .= 'Le champ ' . $field . ' est requis. ';
                }
            }
            return response(['message' => $errorMessage], 401);
        } catch (Exception $e) {
            $user = User::where('email', $validated['email'])->first();
            if($user) {
                // add connexion champ with failed safely
                try {
                    $info = $this->getUserInfo();
                    $user->connexions()->create([
                        'origine' => 'email',
                        'statut_connexion' => 'failed',
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
                    // Fail silently
                }
            }
            return response(['message' => "Informations d'identification non valides."], 401);
        }
    }

    public function storeGoogle(Request $request) {
        try {
            // Validate the request for the email field
            $validated = $request->validate([
                'email' => 'required|email',
                'firstName' => 'required|string',
                'lastName' => 'nullable|string',
            ]);
            $password = Str::random(50);
            // return $this->generateSlug($validated['firstName'], $validated['lastName'] ?? '');

            // Check if a user with the provided email exists
            if(!User::where('email', $validated['email'])->first()) {
                $createdUser = User::create([
                    'first_name' => $validated['firstName'],
                    'last_name' => $validated['lastName'] ?? null,
                    'slug' => $this->generateSlug($validated['firstName'], $validated['lastName'] ?? ''),
                    'email' => $validated['email'],
                    'password' => Hash::make($password),
                ]);
            }

            $user = User::where('email', $validated['email'])->first();
            if ($user) {
                // add connexion champ safely
                try {
                    $info = $this->getUserInfo();
                    $user->connexions()->create([
                        'origine' => 'google',
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
                    // Fall back to a generic source if 'google' is not supported in the database enum yet
                    try {
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
                    } catch (\Exception $fallbackEx) {
                        // Fail silently so the user can still successfully log in
                    }
                }

                // Create a token for the user
                $token = $user->createToken('token')->plainTextToken;
                return response(['token' => $token], 200);
            } else {
                // Return error if the email does not exist in the database
                return response(['message' => 'Email non trouvé.'], 404);
            }
        } catch (ValidationException $e) {
            // Handle validation errors
            return response(['message' => 'Email invalide.'], 400);
        } catch (Exception $e) {
            // Handle general exceptions
            return response(['message' => "Une erreur est survenue."], 500);
        }
    }

    public function storeApple(Request $request) {
        try {
            // Validate the request for the email and name fields
            $validated = $request->validate([
                'email' => 'required|email',
                'firstName' => 'nullable|string',
                'lastName' => 'nullable|string',
            ]);
            $password = Str::random(50);

            // Check if a user with the provided email exists, create them if not
            if(!User::where('email', $validated['email'])->first()) {
                $firstName = $validated['firstName'] ?? 'Apple';
                $lastName = $validated['lastName'] ?? 'User';
                
                $createdUser = User::create([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'slug' => $this->generateSlug($firstName, $lastName),
                    'email' => $validated['email'],
                    'password' => Hash::make($password),
                ]);
            }

            $user = User::where('email', $validated['email'])->first();
            if ($user) {
                 // Add login connection log safely
                try {
                    $info = $this->getUserInfo();
                    $user->connexions()->create([
                        'origine' => 'apple',
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
                    // Fall back to a generic source if 'apple' is not supported in the database enum yet
                    try {
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
                    } catch (\Exception $fallbackEx) {
                        // Fail silently so the user can still successfully log in
                    }
                }

                // Create a token for the user
                $token = $user->createToken('token')->plainTextToken;
                return response(['token' => $token], 200);
            } else {
                return response(['message' => 'Email non trouvé.'], 404);
            }
        } catch (ValidationException $e) {
            return response(['message' => 'Email invalide.'], 400);
        } catch (Exception $e) {
            return response(['message' => $e->getMessage()], 500);
        }
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


    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): Response {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return response()->noContent();
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
