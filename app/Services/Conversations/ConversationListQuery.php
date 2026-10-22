<?php

namespace App\Services\Conversations;

use App\Models\Escalation;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Liste des conversations (gérant et admin) : recherche, filtres, nombre de non lus,
 * commandes et dernier message, en un nombre de requêtes fixe quelle que soit la page.
 */
final class ConversationListQuery
{
    public const FILTERS = ['all', 'unread', 'pending', 'orders'];

    /** Colonnes du dernier message (préfixées : sa sous-requête relit la table messages). */
    private const LATEST_MESSAGE_COLUMNS = 'latestMessage:messages.id,messages.conversation_id,messages.direction,messages.sender_type,messages.content,messages.metadata,messages.created_at';

    /**
     * @param  Builder|HasMany  $query  conversations d'un business, ou toutes (admin)
     */
    public static function apply(Builder|HasMany $query, ?string $search, ?string $filter): Builder|HasMany
    {
        $query
            ->with(self::LATEST_MESSAGE_COLUMNS)
            ->with(['escalations' => fn ($q) => $q->where('status', Escalation::PENDING)->latest()])
            ->withCount(['messages as unread_count' => fn ($q) => self::unread($q)])
            ->withExists('orders as has_orders');

        match ($filter) {
            'unread' => $query->whereHas('messages', fn ($q) => self::unread($q)),
            'pending' => $query->whereHas('escalations', fn ($q) => $q->where('status', Escalation::PENDING)),
            'orders' => $query->whereHas('orders'),
            default => null,
        };

        $search = trim((string) $search);
        if ($search !== '') {
            self::search($query, $search);
        }

        return $query->orderByDesc('last_message_at');
    }

    /**
     * Messages du client arrivés après la dernière lecture du gérant.
     */
    private static function unread(BuilderContract $query): void
    {
        $query->where('messages.direction', 'inbound')->where(fn ($q) => $q
            ->whereNull('conversations.last_read_at')
            ->orWhereColumn('messages.created_at', '>', 'conversations.last_read_at'));
    }

    /**
     * Nom du client, numéro (espaces et « + » ignorés) ou texte d'un message, sans
     * tenir compte des majuscules.
     */
    private static function search(Builder|HasMany $query, string $search): void
    {
        $pattern = '%'.self::escapeLike(mb_strtolower($search)).'%';
        $digits = preg_replace('/\D/', '', $search);
        $like = DB::getDriverName() === 'pgsql' ? 'LIKE ?' : "LIKE ? ESCAPE '\\'";

        $query->where(function ($query) use ($pattern, $digits, $like) {
            $query->whereRaw("LOWER(conversations.customer_name) {$like}", [$pattern])
                ->orWhereRaw("LOWER(conversations.customer_phone) {$like}", [$pattern])
                ->orWhereHas('messages', fn ($q) => $q->whereRaw("LOWER(messages.content) {$like}", [$pattern]));

            if (strlen($digits) >= 3) {
                $query->orWhere('conversations.customer_phone', 'like', '%'.$digits.'%');
            }
        });
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
