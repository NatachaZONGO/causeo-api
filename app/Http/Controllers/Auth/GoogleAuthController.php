<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    public function redirect()
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback()
    {
        $googleUser = Socialite::driver('google')->user();

        $user = User::where('email', $googleUser->getEmail())->first();

        if (!$user) {
            $user = User::create([
                'name' => $googleUser->getName(),
                'email' => $googleUser->getEmail(),
                'password' => Hash::make(Str::random(24)),
                'google_id' => $googleUser->getId(),
                'city' => 'Ouagadougou',
                'country' => 'BF',
            ]);
        } else {
            $user->update(['google_id' => $googleUser->getId()]);
        }

        $token = $user->createToken('causeo-app')->plainTextToken;
        $userData = urlencode(json_encode($user->load('businesses')));
        // config() et non env() : la configuration est mise en cache au démarrage.
        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');

        return redirect("{$frontendUrl}/auth/callback?token={$token}&user={$userData}");
    }
}
