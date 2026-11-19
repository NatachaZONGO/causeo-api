<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Services\Catalog\CatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Stock (module « catalog », profil boutique) : mouvements saisis par le gérant (réception,
 * inventaire, perte, retour) et journal du stock de toute l'entreprise.
 */
class StockController extends Controller
{
    public function __construct(
        private readonly CatalogService $catalog,
    ) {}

    /**
     * Réception, retour (+ quantité), perte (− quantité) ou inventaire (quantité = stock compté).
     */
    public function store(Request $request, ProductVariant $variant): JsonResponse
    {
        $variant->loadMissing('product.business');
        $this->checkAccess($variant->product->business, write: true);

        $data = $request->validate([
            'reason' => ['required', Rule::in(StockMovement::OWNER_REASONS)],
            'quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:250'],
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
        ], [
            'reason.required' => 'Choisissez le motif : réception, inventaire, perte ou retour.',
            'reason.in' => 'Motif : réception (restock), inventaire (adjustment), perte (loss) ou retour (return).',
            'quantity.required' => 'Indiquez la quantité (pour un inventaire, le stock compté).',
            'quantity.integer' => 'La quantité doit être un nombre entier de pièces.',
            'quantity.min' => 'La quantité ne peut pas être négative.',
            'note.max' => 'La note ne doit pas dépasser 250 caractères.',
            'unit_cost.numeric' => 'Le coût unitaire doit être un montant en FCFA.',
            'unit_cost.min' => 'Le coût unitaire ne peut pas être négatif.',
        ]);

        try {
            $variant = $this->catalog->recordMovement($variant, $data['reason'], (int) $data['quantity'], $data['note'] ?? null, isset($data['unit_cost']) ? (float) $data['unit_cost'] : null, auth()->id());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['quantity' => [$e->getMessage()]]], 422);
        }

        return response()->json([
            'message' => StockMovement::LABELS[$data['reason']].' enregistrée : stock de '.$variant->stock_quantity.'.',
            'variant' => $variant->fresh(),
            'movement' => StockMovement::query()->where('variant_id', $variant->id)->latest('id')->first(),
        ], 201);
    }

    /**
     * Journal du stock de l'entreprise, le plus récent d'abord : filtres article, motif et dates.
     */
    public function index(Request $request, Business $business): JsonResponse
    {
        $this->checkAccess($business);

        $data = $request->validate([
            'product_id' => ['nullable', 'uuid'],
            'reason' => ['nullable', Rule::in(StockMovement::REASONS)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], [
            'reason.in' => 'Motif inconnu.',
            'from.date' => 'La date de début n\'est pas valide.',
            'to.date' => 'La date de fin n\'est pas valide.',
            'to.after_or_equal' => 'La date de fin doit suivre la date de début.',
        ]);

        $timezone = $business->localTimezone();

        $movements = $business->stockMovements()
            ->with(['variant.product' => fn ($query) => $query->withTrashed()->select(['id', 'name', 'deleted_at']), 'order:id,source,customer_name'])
            ->when($data['product_id'] ?? null, fn ($query, $productId) => $query->whereHas('variant', fn ($variants) => $variants->withTrashed()->where('product_id', $productId)))
            ->when($data['reason'] ?? null, fn ($query, $reason) => $query->where('reason', $reason))
            ->when($data['from'] ?? null, fn ($query, $from) => $query->where('created_at', '>=', \Illuminate\Support\Carbon::parse($from, $timezone)->startOfDay()->utc()))
            ->when($data['to'] ?? null, fn ($query, $to) => $query->where('created_at', '<=', \Illuminate\Support\Carbon::parse($to, $timezone)->endOfDay()->utc()))
            ->latest('created_at')
            ->latest('id')
            ->paginate($data['per_page'] ?? 30)
            ->withQueryString();

        $movements->getCollection()->transform(fn (StockMovement $movement) => [
            'id' => $movement->id,
            'created_at' => $movement->created_at,
            'reason' => $movement->reason,
            'reason_label' => StockMovement::LABELS[$movement->reason] ?? $movement->reason,
            'quantity' => $movement->quantity,
            'balance_after' => $movement->balance_after,
            'note' => $movement->note,
            'unit_cost' => $movement->unit_cost,
            'order_id' => $movement->order_id,
            'order_reference' => $movement->order?->reference(),
            'product_id' => $movement->variant?->product_id,
            'product_name' => $movement->variant?->product?->name,
            'product_archived' => $movement->variant?->product?->trashed() ?? false,
            'variant_id' => $movement->variant_id,
            'variant_label' => $movement->variant?->optionsLabel() ?: null,
            'variant_archived' => $movement->variant?->trashed() ?? false,
        ]);

        return response()->json($movements);
    }

    /**
     * Gérant propriétaire, module activé ; pour une modification, module compris dans la formule.
     */
    private function checkAccess(Business $business, bool $write = false): void
    {
        abort_if($business->user_id !== auth()->id(), 403, 'Cette entreprise ne vous appartient pas.');
        abort_unless($business->moduleEnabled('catalog'), 403, "Le module Catalogue n'est pas activé pour cette entreprise.");

        if ($write) {
            abort_unless($business->hasModule('catalog'), 403, "Votre formule {$business->billingState()->plan->name} ne permet pas de gérer le stock : passez en Pro pour le modifier.");
        }
    }
}
