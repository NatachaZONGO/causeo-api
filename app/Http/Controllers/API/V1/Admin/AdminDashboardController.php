<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\DocumentChunk;
use App\Models\Escalation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    /**
     * Statistiques globales de la plateforme.
     */
    public function stats(): JsonResponse
    {
        $now = now();

        $revenueEstimate = Business::where('plan', 'pro')->count() * 15000
            + Business::where('plan', 'enterprise')->count() * 45000;

        return response()->json([
            'total_users' => User::count(),
            'total_businesses' => Business::count(),
            'total_conversations' => Conversation::count(),
            'total_messages' => Message::count(),
            'total_documents' => DocumentChunk::distinct('business_id')->count('business_id'),
            'total_escalations_pending' => Escalation::where('status', 'pending')->count(),
            'messages_today' => Message::whereDate('created_at', $now->toDateString())->count(),
            'messages_this_week' => Message::whereBetween('created_at', [
                $now->clone()->startOfWeek(), $now->clone()->endOfWeek(),
            ])->count(),
            'messages_this_month' => Message::whereYear('created_at', $now->year)
                ->whereMonth('created_at', $now->month)
                ->count(),
            'new_users_this_month' => User::whereYear('created_at', $now->year)
                ->whereMonth('created_at', $now->month)
                ->count(),
            'revenue_estimate' => $revenueEstimate,
        ]);
    }

    /**
     * Lister toutes les entreprises de la plateforme.
     */
    public function businesses(Request $request): JsonResponse
    {
        $query = Business::query()
            ->with('user:id,name,email')
            ->withCount([
                'conversations',
                'documents',
            ])
            ->addSelect(['monthly_message_count']);

        if ($request->filled('plan')) {
            $query->where('plan', $request->input('plan'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%");
            });
        }

        $businesses = $query->latest()->paginate(15);

        $businesses->getCollection()->transform(function (Business $business) {
            $business->monthly_message_count = (int) $business->monthly_message_count;

            return $business;
        });

        return response()->json($businesses);
    }

    /**
     * Mettre à jour une entreprise (plan, statut, IA).
     */
    public function updateBusiness(Request $request, Business $business): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['sometimes', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'ai_instructions' => ['sometimes', 'nullable', 'string'],
            'custom_greeting' => ['sometimes', 'nullable', 'string'],
        ]);

        $business->update($data);

        return response()->json([
            'message' => 'L\'entreprise a été mise à jour avec succès.',
            'business' => $business,
        ]);
    }

    /**
     * Lister tous les utilisateurs de la plateforme.
     */
    public function users(Request $request): JsonResponse
    {
        $query = User::query()->withCount('businesses');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_admin')) {
            $query->where('is_admin', $request->boolean('is_admin'));
        }

        $users = $query->latest()->paginate(15);

        return response()->json($users);
    }

    /**
     * Promouvoir ou rétrograder un utilisateur.
     */
    public function updateUser(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'is_admin' => ['required', 'boolean'],
        ]);

        $user->update($data);

        return response()->json([
            'message' => 'L\'utilisateur a été mis à jour avec succès.',
            'user' => $user,
        ]);
    }

    /**
     * Supprimer un utilisateur et ses données.
     */
    public function deleteUser(User $user): JsonResponse
    {
        DB::transaction(function () use ($user) {
            $user->businesses()->each(fn (Business $business) => $business->delete());
            $user->delete();
        });

        return response()->json([
            'message' => 'L\'utilisateur a été supprimé avec succès.',
        ]);
    }

    /**
     * Lister toutes les conversations de toutes les entreprises.
     */
    public function conversations(Request $request): JsonResponse
    {
        $query = Conversation::query()
            ->with('business:id,name')
            ->with('latestMessage:id,conversation_id,content,created_at')
            ->withCount([
                'escalations as pending_escalations_count' => fn ($q) => $q->where('status', 'pending'),
            ]);

        if ($request->filled('business_id')) {
            $query->where('business_id', $request->input('business_id'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%");
            });
        }

        $conversations = $query->latest('last_message_at')->paginate(15);

        return response()->json($conversations);
    }

    /**
     * Lister les escalations de toutes les entreprises.
     */
    public function escalations(Request $request): JsonResponse
    {
        $query = Escalation::query()
            ->with('conversation:id,customer_name,customer_phone')
            ->with('business:id,name');

        $status = $request->input('status', 'pending');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($request->filled('business_id')) {
            $query->where('business_id', $request->input('business_id'));
        }

        $escalations = $query->latest()->paginate(15);

        return response()->json($escalations);
    }
}
