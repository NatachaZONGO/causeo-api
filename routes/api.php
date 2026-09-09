<?php

use App\Http\Controllers\API\V1\AuthController;
use App\Http\Controllers\API\V1\ConversationController;
use Illuminate\Support\Facades\Route;

Route::prefix('webhook')->group(function () {
    Route::get('whatsapp', [\App\Http\Controllers\API\V1\WebhookController::class, 'verify']);
    Route::post('whatsapp', [\App\Http\Controllers\API\V1\WebhookController::class, 'handle']);
    Route::post('payment', [\App\Http\Controllers\API\V1\WebhookController::class, 'payment']);
});

Route::prefix('v1/auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::put('me', [AuthController::class, 'updateProfile']);
        Route::put('password', [AuthController::class, 'updatePassword']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

/*
|--------------------------------------------------------------------------
| Routes protégées
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::apiResource('businesses', \App\Http\Controllers\API\V1\BusinessController::class);
    Route::apiResource('businesses.documents', \App\Http\Controllers\API\V1\DocumentController::class)->shallow()->except('update');
    Route::apiResource('businesses.media', \App\Http\Controllers\API\V1\BusinessMediaController::class)
        ->shallow()->except('update')->parameters(['media' => 'media']);

    Route::get('businesses/{business}/conversations', [ConversationController::class, 'index']);
    Route::get('conversations/{conversation}', [ConversationController::class, 'show']);
    Route::post('conversations/{conversation}/reply', [ConversationController::class, 'manualReply']);

    // TODO: route temporaire de test du RAG — à retirer.
    Route::post('businesses/{business}/ask', function (\App\Models\Business $business, \Illuminate\Http\Request $request) {
        $service = app(\App\Services\AI\AIResponseService::class);
        $result = $service->answer($business, $request->input('question'));

        return response()->json($result);
    });
});
