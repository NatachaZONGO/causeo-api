<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use Closure;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AuthController extends Controller
{
    /** Demandes de lien autorisées par e-mail et par adresse IP, sur RESET_LINK_DECAY_SECONDS. */
    private const RESET_LINK_ATTEMPTS = 3;

    private const RESET_LINK_DECAY_SECONDS = 600;

    /**
     * Inscrire un nouvel utilisateur.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'phone' => $data['phone'] ?? null,
            'whatsapp_phone' => $data['whatsapp_phone'] ?? null,
            'city' => $data['city'] ?? 'Ouagadougou',
            'country' => $data['country'] ?? 'BF',
        ]);

        $token = $user->createToken('causeo-app')->plainTextToken;

        return response()->json([
            'message' => 'Votre compte a été créé avec succès.',
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    /**
     * Connecter un utilisateur.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Les identifiants fournis sont incorrects.'],
            ]);
        }

        $token = $user->createToken('causeo-app')->plainTextToken;

        return response()->json([
            'message' => 'Connexion réussie.',
            'user' => $user->load('businesses'),
            'token' => $token,
        ]);
    }

    /**
     * Récupérer l'utilisateur connecté.
     */
    public function me(): JsonResponse
    {
        return response()->json([
            'user' => auth()->user()->load('businesses'),
        ]);
    }

    /**
     * Déconnecter l'utilisateur (révoquer le token courant).
     */
    public function logout(): JsonResponse
    {
        auth()->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Vous avez été déconnecté avec succès.',
        ]);
    }

    /**
     * Envoyer un lien de réinitialisation du mot de passe. La réponse est la même
     * que le compte existe ou non, pour ne pas révéler quelles adresses sont inscrites.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $email = trim($request->validated('email'));
        // Clés sans l'adresse en clair.
        $limits = [
            'forgot-password:email:'.hash('sha256', Str::lower($email)),
            'forgot-password:ip:'.$request->ip(),
        ];

        foreach ($limits as $key) {
            if (RateLimiter::tooManyAttempts($key, self::RESET_LINK_ATTEMPTS)) {
                $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));

                return response()->json([
                    'message' => "Trop de demandes de réinitialisation. Réessayez dans {$minutes} minute".($minutes > 1 ? 's' : '').'.',
                ], 429);
            }
        }

        foreach ($limits as $key) {
            RateLimiter::hit($key, self::RESET_LINK_DECAY_SECONDS);
        }

        // Compte inconnu, ou lien déjà envoyé il y a moins d'une minute : même réponse.
        // L'e-mail part après la réponse, pour que sa durée d'envoi ne trahisse pas le compte.
        Password::sendResetLink(['email' => $email], function (User $user, string $token) {
            $this->afterResponse(function () use ($user, $token) {
                try {
                    $user->sendPasswordResetNotification($token);
                } catch (Throwable $e) {
                    // Ni jeton ni adresse dans les logs.
                    Log::error('AuthController: e-mail de réinitialisation impossible', [
                        'user_id' => $user->id,
                        'exception' => $e::class,
                    ]);
                }
            });
        });

        return response()->json([
            'message' => 'Si un compte existe avec cet e-mail, vous allez recevoir un lien pour réinitialiser votre mot de passe.',
        ]);
    }

    /**
     * Réinitialiser le mot de passe (aussi pour définir un mot de passe sur un
     * compte créé avec Google), puis déconnecter tous les appareils.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->validated(),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                // Déconnexion de tous les appareils.
                $user->tokens()->delete();

                event(new PasswordReset($user));

                $this->afterResponse(function () use ($user) {
                    try {
                        $user->notify(new PasswordChangedNotification());
                    } catch (Throwable $e) {
                        Log::error('AuthController: e-mail de confirmation du mot de passe impossible', [
                            'user_id' => $user->id,
                            'exception' => $e::class,
                        ]);
                    }
                });
            }
        );

        // Jeton faux, expiré ou déjà utilisé, ou adresse inconnue : même message.
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'token' => ['Ce lien de réinitialisation est invalide ou a expiré. Demandez un nouveau lien depuis la page « Mot de passe oublié ».'],
            ]);
        }

        return response()->json([
            'message' => 'Votre mot de passe a été réinitialisé. Vous pouvez maintenant vous connecter avec votre nouveau mot de passe.',
        ]);
    }

    /**
     * Exécuter un envoi après la réponse, une seule fois (une application qui sert
     * plusieurs requêtes, comme en test, relancerait les callbacks déjà enregistrés).
     */
    private function afterResponse(Closure $callback): void
    {
        $done = false;

        app()->terminating(function () use (&$done, $callback) {
            if ($done) {
                return;
            }

            $done = true;
            $callback();
        });
    }

    /**
     * Mettre à jour le profil de l'utilisateur connecté.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'whatsapp_phone' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();
        $user->update($data);

        return response()->json([
            'message' => 'Profil mis à jour avec succès.',
            'user' => $user,
        ]);
    }

    /**
     * Changer le mot de passe de l'utilisateur connecté.
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Le mot de passe actuel est incorrect.'],
            ]);
        }

        $user->update([
            'password' => Hash::make($data['password']),
        ]);

        return response()->json([
            'message' => 'Mot de passe modifié avec succès.',
        ]);
    }
}
