<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Conversation;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AppointmentService
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {
    }

    /**
     * Enregistrer une demande de rendez-vous à partir des données de l'outil
     * create_appointment. Le créneau reste à confirmer par l'entreprise.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws InvalidArgumentException si les données sont incomplètes (message destiné à l'IA)
     */
    public function createFromAi(Business $business, Conversation $conversation, array $input, ?CarbonImmutable $today = null): Appointment
    {
        $service = $this->string($input['service'] ?? null);
        $requestedTime = $this->string($input['requested_time'] ?? null);
        $customerName = $this->string($input['customer_name'] ?? null) ?? $conversation->customer_name;
        $requestedDate = $this->string($input['requested_date'] ?? null);

        $missing = array_keys(array_filter([
            'service' => $service === null,
            'requested_date' => $requestedDate === null,
            'requested_time' => $requestedTime === null,
            'customer_name' => $customerName === null,
        ]));

        if ($missing !== []) {
            throw new InvalidArgumentException('Informations manquantes : '.implode(', ', $missing).'. Demande-les au client avant de réessayer.');
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $requestedDate);
        } catch (InvalidFormatException) {
            $date = false;
        }

        if ($date === false || $date->format('Y-m-d') !== $requestedDate) {
            throw new InvalidArgumentException("Date invalide : « {$requestedDate} ». Utilise le format AAAA-MM-JJ.");
        }

        $today ??= CarbonImmutable::now(config('app.timezone'))->startOfDay();
        if ($date->lessThan($today)) {
            throw new InvalidArgumentException("La date demandée ({$requestedDate}) est déjà passée. Demande au client une autre date.");
        }

        $participants = $input['participants'] ?? null;
        if ($participants !== null && $participants !== '' && (! is_numeric($participants) || (int) $participants != $participants || (int) $participants < 1)) {
            throw new InvalidArgumentException('Le nombre de participants doit être un entier supérieur ou égal à 1.');
        }

        $price = $input['price'] ?? null;
        if ($price !== null && $price !== '' && (! is_numeric($price) || (float) $price < 0)) {
            throw new InvalidArgumentException("Le prix est invalide. N'invente jamais un prix absent du contexte.");
        }

        // Le client a pu confirmer deux fois : on ne duplique pas une demande identique récente.
        $duplicate = Appointment::query()
            ->where('conversation_id', $conversation->id)
            ->where('status', 'requested')
            ->whereDate('requested_date', $date->format('Y-m-d'))
            ->where('requested_time', $requestedTime)
            ->where('created_at', '>=', now()->subMinutes(10))
            ->latest()
            ->get()
            ->first(fn (Appointment $appointment) => Str::lower($appointment->service) === Str::lower($service));

        if ($duplicate !== null) {
            return $duplicate;
        }

        $appointment = Appointment::create([
            'business_id' => $business->id,
            'conversation_id' => $conversation->id,
            'customer_phone' => $conversation->customer_phone,
            'customer_name' => $customerName,
            'service' => $service,
            'requested_date' => $date->format('Y-m-d'),
            'requested_time' => $requestedTime,
            'location' => $this->string($input['location'] ?? null),
            'participants' => $participants === null || $participants === '' ? null : (int) $participants,
            'price' => $price === null || $price === '' ? null : (float) $price,
            'notes' => $this->string($input['notes'] ?? null),
            'status' => 'requested',
        ]);

        $this->notificationService->notifyAppointment($appointment);

        return $appointment;
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
