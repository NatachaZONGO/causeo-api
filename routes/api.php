<?php

use App\Http\Controllers\API\V1\Admin\AdminAiCostController;
use App\Http\Controllers\API\V1\Admin\AdminDashboardController;
use App\Http\Controllers\API\V1\Admin\AdminFeedbackController;
use App\Http\Controllers\API\V1\Admin\AdminNotificationController;
use App\Http\Controllers\API\V1\Admin\AdminPaymentController;
use App\Http\Controllers\API\V1\Admin\AdminPlanController;
use App\Http\Controllers\API\V1\AppointmentController;
use App\Http\Controllers\API\V1\AuthController;
use App\Http\Controllers\API\V1\BillingController;
use App\Http\Controllers\API\V1\ConversationController;
use App\Http\Controllers\API\V1\FeedbackController;
use App\Http\Controllers\API\V1\NotificationController;
use App\Http\Controllers\API\V1\OnboardingController;
use App\Http\Controllers\API\V1\OrderController;
use App\Http\Controllers\API\V1\PaymentController;
use App\Http\Controllers\API\V1\PlanController;
use App\Http\Controllers\API\V1\WhatsAppAlertController;
use App\Http\Controllers\API\V1\WhatsAppSetupController;
use App\Models\BusinessTemplate;
use Illuminate\Support\Facades\Route;

Route::prefix('webhook')->group(function () {
    Route::get('whatsapp', [\App\Http\Controllers\API\V1\WebhookController::class, 'verify']);
    Route::post('whatsapp', [\App\Http\Controllers\API\V1\WebhookController::class, 'handle']);
    Route::post('payment', [\App\Http\Controllers\API\V1\WebhookController::class, 'payment']);
    Route::middleware('whatsapp.express')->group(function () {
        Route::post('whatsapp-express', [\App\Http\Controllers\API\V1\WebhookController::class, 'handleExpress']);
        Route::post('whatsapp-express/status', [\App\Http\Controllers\API\V1\WebhookController::class, 'handleExpressStatus']);
    });
});

Route::get('v1/templates', fn () => response()->json(
    BusinessTemplate::where('is_active', true)->orderBy('sort_order')->get()
));

Route::get('v1/plans', [PlanController::class, 'index']);

// Profils d'activité (Catalogue, Menu, Prestations…) et leur vocabulaire, pour l'inscription et les réglages.
Route::get('v1/activity-profiles', fn () => response()->json(['data' => \App\Support\ActivityProfile::all()]));

Route::post('v1/onboarding', [OnboardingController::class, 'store'])->middleware('auth:sanctum');

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
    // Catalogue et stock (module « catalog »).
    Route::get('businesses/{business}/products/export', [\App\Http\Controllers\API\V1\ProductController::class, 'export']);
    Route::get('businesses/{business}/products/import-template', [\App\Http\Controllers\API\V1\ProductController::class, 'template']);
    Route::post('businesses/{business}/products/import', [\App\Http\Controllers\API\V1\ProductController::class, 'import']);
    Route::get('businesses/{business}/products', [\App\Http\Controllers\API\V1\ProductController::class, 'index']);
    Route::post('businesses/{business}/products', [\App\Http\Controllers\API\V1\ProductController::class, 'store']);
    Route::get('products/{product}', [\App\Http\Controllers\API\V1\ProductController::class, 'show']);
    Route::put('products/{product}', [\App\Http\Controllers\API\V1\ProductController::class, 'update']);
    Route::delete('products/{product}', [\App\Http\Controllers\API\V1\ProductController::class, 'destroy']);
    Route::post('products/{product}/variants/generate', [\App\Http\Controllers\API\V1\ProductController::class, 'generateVariants']);
    Route::patch('variants/{variant}/stock', [\App\Http\Controllers\API\V1\ProductController::class, 'updateStock']);
    Route::patch('products/{product}/availability', [\App\Http\Controllers\API\V1\ProductController::class, 'updateAvailability']);
    Route::post('businesses/{business}/logo', [\App\Http\Controllers\API\V1\BusinessLogoController::class, 'store']);
    Route::delete('businesses/{business}/logo', [\App\Http\Controllers\API\V1\BusinessLogoController::class, 'destroy']);
    Route::apiResource('businesses.documents', \App\Http\Controllers\API\V1\DocumentController::class)->shallow()->except('update');
    Route::apiResource('businesses.media', \App\Http\Controllers\API\V1\BusinessMediaController::class)
        ->shallow()->except('update')->parameters(['media' => 'media']);

    Route::get('businesses/{business}/conversations', [ConversationController::class, 'index']);
    Route::get('conversations/{conversation}', [ConversationController::class, 'show']);
    Route::post('conversations/{conversation}/reply', [ConversationController::class, 'manualReply']);
    Route::post('conversations/{conversation}/read', [ConversationController::class, 'read']);
    // Assistant en pause dans une conversation (le gérant prend la main), ou réactivé.
    Route::post('conversations/{conversation}/ai/pause', [ConversationController::class, 'pauseAi']);
    Route::post('conversations/{conversation}/ai/resume', [ConversationController::class, 'resumeAi']);
    Route::get('messages/{message}/audio', [\App\Http\Controllers\API\V1\MessageAudioController::class, 'show']);
    Route::get('messages/{message}/media', [\App\Http\Controllers\API\V1\MessageMediaController::class, 'show']);
    // Statuts du jour (ce que le gérant publie en statut WhatsApp), actifs 24 h.
    Route::get('businesses/{business}/status-posts', [\App\Http\Controllers\API\V1\StatusPostController::class, 'index']);
    Route::post('businesses/{business}/status-posts', [\App\Http\Controllers\API\V1\StatusPostController::class, 'store']);
    Route::delete('status-posts/{statusPost}', [\App\Http\Controllers\API\V1\StatusPostController::class, 'destroy']);
    Route::post('escalations/{escalation}/resolve', [\App\Http\Controllers\API\V1\EscalationController::class, 'resolve']);

    Route::get('businesses/{business}/billing', [BillingController::class, 'show']);
    Route::get('businesses/{business}/payments', [PaymentController::class, 'index']);
    Route::post('businesses/{business}/payments', [PaymentController::class, 'store']);

    Route::get('businesses/{business}/notifications', [NotificationController::class, 'index']);
    Route::post('businesses/{business}/notifications/read-all', [NotificationController::class, 'readAll']);
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read']);

    // Alertes WhatsApp du gérant connecté (depuis le numéro Causeo).
    Route::get('me/whatsapp-alerts', [WhatsAppAlertController::class, 'show']);
    Route::put('me/whatsapp-alerts', [WhatsAppAlertController::class, 'update']);
    Route::post('me/whatsapp-alerts/test', [WhatsAppAlertController::class, 'test'])->middleware('throttle:5,1');
    // Activation en un clic (inscription) : numéro et consentement explicite, enregistré avec sa date.
    Route::post('me/whatsapp-alerts/activate', [WhatsAppAlertController::class, 'activate']);

    // Retours du gérant (bugs, suggestions, questions).
    Route::post('feedback', [FeedbackController::class, 'store']);
    Route::get('me/feedback', [FeedbackController::class, 'index']);

    Route::get('businesses/{business}/orders', [OrderController::class, 'index']);
    Route::get('orders/{order}', [OrderController::class, 'show']);
    Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus']);

    Route::get('businesses/{business}/appointments', [AppointmentController::class, 'index']);
    Route::get('appointments/{appointment}', [AppointmentController::class, 'show']);
    Route::patch('appointments/{appointment}/status', [AppointmentController::class, 'updateStatus']);

    Route::post('businesses/{business}/whatsapp/connect', [WhatsAppSetupController::class, 'exchangeToken']);
    Route::post('businesses/{business}/whatsapp/request', [WhatsAppSetupController::class, 'requestActivation']);
    Route::get('businesses/{business}/whatsapp/status', [WhatsAppSetupController::class, 'status']);
    Route::delete('businesses/{business}/whatsapp/disconnect', [WhatsAppSetupController::class, 'disconnect']);

    // TODO: route temporaire de test du RAG — à retirer.
    Route::post('businesses/{business}/ask', function (\App\Models\Business $business, \Illuminate\Http\Request $request) {
        $service = app(\App\Services\AI\AIResponseService::class);
        $result = $service->answer($business, $request->input('question'));

        return response()->json($result);
    });
});

Route::prefix('v1/admin')->middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('stats', [AdminDashboardController::class, 'stats']);
    Route::get('plans', [AdminPlanController::class, 'index']);
    Route::get('businesses', [AdminDashboardController::class, 'businesses']);
    Route::put('businesses/{business}', [AdminDashboardController::class, 'updateBusiness']);
    Route::put('businesses/{business}/whatsapp', [AdminDashboardController::class, 'configureBusinessWhatsapp']);
    Route::get('users', [AdminDashboardController::class, 'users']);
    Route::put('users/{user}', [AdminDashboardController::class, 'updateUser']);
    Route::delete('users/{user}', [AdminDashboardController::class, 'deleteUser']);
    Route::get('conversations', [AdminDashboardController::class, 'conversations']);
    Route::get('escalations', [AdminDashboardController::class, 'escalations']);

    Route::get('ai-costs', [AdminAiCostController::class, 'index']);

    Route::get('payments', [AdminPaymentController::class, 'index']);
    Route::get('payments/{payment}/proof', [AdminPaymentController::class, 'proof']);
    Route::post('payments/{payment}/approve', [AdminPaymentController::class, 'approve']);
    Route::post('payments/{payment}/reject', [AdminPaymentController::class, 'reject']);

    Route::get('notifications', [AdminNotificationController::class, 'index']);
    Route::post('notifications/read-all', [AdminNotificationController::class, 'readAll']);
    Route::post('notifications/{notification}/read', [AdminNotificationController::class, 'read']);

    Route::get('feedback', [AdminFeedbackController::class, 'index']);
    Route::get('feedback/{feedback}', [AdminFeedbackController::class, 'show']);
    Route::patch('feedback/{feedback}', [AdminFeedbackController::class, 'update']);
    Route::get('feedback/{feedback}/screenshot', [AdminFeedbackController::class, 'screenshot']);
});
