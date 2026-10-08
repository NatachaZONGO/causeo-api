<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Business;
use App\Services\WhatsApp\WhatsAppNotConnectedException;
use App\Services\WhatsApp\WhatsAppServiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AppointmentController extends Controller
{
    /** Statuts dont le changement est annoncé au client sur WhatsApp. */
    private const NOTIFIED_STATUSES = ['confirmed', 'declined'];

    public function __construct(
        private readonly WhatsAppServiceFactory $whatsApp,
    ) {
    }

    /**
     * Lister les demandes de rendez-vous d'une entreprise : les plus récentes d'abord,
     * ou par date souhaitée croissante avec sort=requested_date.
     */
    public function index(Request $request, Business $business): JsonResponse
    {
        $this->checkOwnership($business);
        $this->checkAppointmentsModule($business);

        $data = $request->validate([
            'status' => ['nullable', Rule::in(Appointment::STATUSES)],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(['requested_date'])],
        ], [
            'status.in' => 'Le statut doit être l\'un des suivants : '.implode(', ', Appointment::STATUSES).'.',
            'date_from.date_format' => 'La date de début doit être au format AAAA-MM-JJ.',
            'date_to.date_format' => 'La date de fin doit être au format AAAA-MM-JJ.',
            'date_to.after_or_equal' => 'La date de fin doit être postérieure ou égale à la date de début.',
            'q.max' => 'La recherche ne doit pas dépasser 100 caractères.',
            'sort.in' => 'Le tri doit être « requested_date ».',
        ]);

        $appointments = $business->appointments()
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['date_from'] ?? null, fn ($query, $date) => $query->whereDate('requested_date', '>=', $date))
            ->when($data['date_to'] ?? null, fn ($query, $date) => $query->whereDate('requested_date', '<=', $date))
            ->when(trim($data['q'] ?? ''), fn ($query, $search) => $this->search($query, $search))
            // Tri stable entre les pages : date souhaitée croissante, puis ordre de création.
            ->when(
                ($data['sort'] ?? null) === 'requested_date',
                fn ($query) => $query->orderBy('requested_date')->orderBy('created_at')->orderBy('id'),
                fn ($query) => $query->latest(),
            )
            ->paginate(20);

        return response()->json($appointments);
    }

    /**
     * Afficher une demande de rendez-vous.
     */
    public function show(Appointment $appointment): JsonResponse
    {
        $appointment->loadMissing('business');

        $this->checkOwnership($appointment->business);
        $this->checkAppointmentsModule($appointment->business);

        return response()->json([
            'appointment' => $appointment,
        ]);
    }

    /**
     * Changer le statut d'une demande de rendez-vous. Une confirmation ou un
     * refus est annoncé au client sur WhatsApp s'il a écrit dans les dernières 24 h
     * (au-delà, WhatsApp n'autorise que des modèles de message pré-approuvés).
     */
    public function updateStatus(Request $request, Appointment $appointment): JsonResponse
    {
        $appointment->loadMissing('business', 'conversation');

        $this->checkOwnership($appointment->business);
        $this->checkAppointmentsModule($appointment->business);

        $data = $request->validate([
            'status' => ['required', Rule::in(Appointment::STATUSES)],
        ], [
            'status.required' => 'Le statut est obligatoire.',
            'status.in' => 'Le statut doit être l\'un des suivants : '.implode(', ', Appointment::STATUSES).'.',
        ]);

        $changed = $appointment->status !== $data['status'];
        $appointment->update(['status' => $data['status']]);

        $skippedReason = match (true) {
            ! $changed || ! in_array($data['status'], self::NOTIFIED_STATUSES, true) => 'not_applicable',
            default => $this->notifyCustomer($appointment),
        };

        $notified = $skippedReason === null;

        return response()->json([
            'message' => $this->statusMessage($data['status'], $notified, $skippedReason),
            'appointment' => $appointment,
            'customer_notified' => $notified,
            'notification_skipped_reason' => $skippedReason,
        ]);
    }

    /**
     * Envoyer au client le message de confirmation ou de refus.
     * Renvoie null si le message est parti, sinon la raison de l'absence d'envoi.
     */
    private function notifyCustomer(Appointment $appointment): ?string
    {
        $conversation = $appointment->conversation;

        if ($conversation === null) {
            return 'no_conversation';
        }

        $lastInbound = $conversation->messages()->where('direction', 'inbound')->max('created_at');

        if ($lastInbound === null || now()->subDay()->greaterThan($lastInbound)) {
            return 'outside_24h_window';
        }

        try {
            $whatsApp = $this->whatsApp->forBusiness($appointment->business);
        } catch (WhatsAppNotConnectedException) {
            return 'whatsapp_not_connected';
        }

        $text = $this->customerMessage($appointment);

        $sent = $whatsApp->sendMessage($conversation->customer_phone, $text);
        $wamid = $sent['messages'][0]['id'] ?? null;

        $conversation->messages()->create([
            'direction' => 'outbound',
            'sender_type' => 'human',
            'content' => $text,
            'status' => $wamid ? 'sent' : 'failed',
            'whatsapp_message_id' => $wamid,
            'metadata' => [
                'appointment_id' => $appointment->id,
                'appointment_status' => $appointment->status,
            ],
        ]);

        return $wamid ? null : 'send_failed';
    }

    private function customerMessage(Appointment $appointment): string
    {
        $when = $appointment->requested_date->locale('fr')->isoFormat('dddd D MMMM')." à {$appointment->requested_time}";

        return $appointment->status === 'confirmed'
            ? "Bonne nouvelle 😊 Votre rendez-vous « {$appointment->service} » du {$when} est confirmé. À bientôt chez {$appointment->business->name} !"
            : "Nous sommes désolés, nous ne pouvons pas vous recevoir pour « {$appointment->service} » le {$when}. N'hésitez pas à nous proposer un autre créneau.";
    }

    private function statusMessage(string $status, bool $notified, ?string $skippedReason): string
    {
        if (! in_array($status, self::NOTIFIED_STATUSES, true) || $skippedReason === 'not_applicable') {
            return 'Le statut du rendez-vous a été mis à jour.';
        }

        return match ($skippedReason) {
            null => 'Le statut du rendez-vous a été mis à jour et le client a été prévenu sur WhatsApp.',
            'outside_24h_window' => 'Le statut du rendez-vous a été mis à jour. Le client n\'a pas écrit depuis plus de 24 h : aucun message WhatsApp n\'a été envoyé, prévenez-le autrement.',
            'no_conversation' => 'Le statut du rendez-vous a été mis à jour. Aucune conversation WhatsApp n\'est liée : le client n\'a pas été prévenu.',
            'whatsapp_not_connected' => 'Le statut du rendez-vous a été mis à jour. WhatsApp n\'est pas connecté pour cette entreprise : le client n\'a pas été prévenu.',
            default => 'Le statut du rendez-vous a été mis à jour, mais l\'envoi du message WhatsApp a échoué : prévenez le client autrement.',
        };
    }

    /**
     * Rechercher, sans tenir compte de la casse, dans la référence (fin de l'id),
     * le nom du client, le téléphone, la prestation et le lieu.
     *
     * @param  Builder<Appointment>|HasMany<Appointment>  $query
     */
    private function search(Builder|HasMany $query, string $search): void
    {
        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)).'%';
        $like = DB::getDriverName() === 'pgsql' ? 'LIKE ?' : "LIKE ? ESCAPE '\\'";

        $query->where(function ($query) use ($pattern, $like) {
            foreach (['CAST(appointments.id AS TEXT)', 'appointments.customer_name', 'appointments.customer_phone', 'appointments.service', 'appointments.location'] as $column) {
                $query->orWhereRaw("LOWER({$column}) {$like}", [$pattern]);
            }
        });
    }

    /**
     * Refuser l'accès aux rendez-vous si le module n'est pas activé pour l'entreprise.
     */
    private function checkAppointmentsModule(Business $business): void
    {
        abort_unless($business->hasModule('appointments'), 403, "Le module Rendez-vous n'est pas activé pour cette entreprise.");
    }

    /**
     * Vérifier que l'entreprise appartient à l'utilisateur connecté.
     */
    private function checkOwnership(Business $business): void
    {
        abort_if($business->user_id !== auth()->id(), 403, 'Cette entreprise ne vous appartient pas.');
    }
}
