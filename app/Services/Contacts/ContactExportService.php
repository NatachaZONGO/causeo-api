<?php

namespace App\Services\Contacts;

use App\Models\Business;
use App\Models\Customer;
use App\Support\PhoneNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Contacts clients d'un business, exportés en vCard 3.0 pour que le gérant les enregistre dans
 * son téléphone (et voie ses statuts WhatsApp). Un client = un numéro (table customers) : ceux qui
 * ont écrit sur WhatsApp et ceux enregistrés lors d'une vente au comptoir. Le nom est celui choisi
 * par le commerçant s'il existe, sinon le nom WhatsApp. Aucun nom ni numéro de client n'est écrit
 * dans les logs.
 */
class ContactExportService
{
    public const SCOPE_NEW = 'new';

    public const SCOPE_ALL = 'all';

    public const SCOPES = [self::SCOPE_NEW, self::SCOPE_ALL];

    /** Longueur du nom du client avant « · {business} ». */
    public const NAME_MAX_LENGTH = 50;

    /** vCard : 75 octets par ligne au plus, la suite repliée sur la ligne suivante. */
    private const LINE_MAX_BYTES = 75;

    /**
     * @return array{total: int, new_since_last_export: int, last_exported_at: ?string}
     */
    public function summary(Business $business): array
    {
        $since = $business->contacts_exported_at;
        $total = $business->customers()->count();

        return [
            'total' => $total,
            'new_since_last_export' => $since === null ? $total : $business->customers()->where('first_seen_at', '>', $since)->count(),
            'last_exported_at' => $since?->toIso8601String(),
        ];
    }

    /**
     * Clients à exporter, le plus ancien d'abord. scope=new : ceux arrivés après le dernier
     * export (tous s'il n'y en a jamais eu).
     *
     * @return Collection<int, Customer>
     */
    public function clients(Business $business, string $scope): Collection
    {
        $since = $business->contacts_exported_at;

        return $business->customers()
            ->withExists('conversations')
            ->when($scope === self::SCOPE_NEW && $since !== null, fn ($query) => $query->where('first_seen_at', '>', $since))
            ->orderBy('first_seen_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, Customer>  $clients
     */
    public function vcards(Business $business, Collection $clients): string
    {
        return $clients->map(fn (Customer $client) => $this->vcard($business, $client))->implode('');
    }

    /** clients-{slug}-{date}.vcf, la date dans le fuseau du business. */
    public function filename(Business $business): string
    {
        $slug = Str::slug((string) $business->name) ?: 'business';

        return "clients-{$slug}-".now($business->localTimezone())->format('Y-m-d').'.vcf';
    }

    private function vcard(Business $business, Customer $client): string
    {
        $digits = PhoneNumber::canonical($client->phone, $business->country);
        $label = $this->escape($this->displayName($business, $client, $digits));
        $firstAt = ($client->first_seen_at ?? $client->created_at)->copy()->setTimezone($business->localTimezone());
        // Client venu seulement du comptoir : pas « WhatsApp ».
        $since = $client->conversations_exists ? 'Client WhatsApp depuis le ' : 'Client depuis le ';

        $lines = [
            'BEGIN:VCARD',
            'VERSION:3.0',
            "FN:{$label}",
            "N:{$label};;;;",
            "TEL;TYPE=CELL:+{$digits}",
            'NOTE:'.$this->escape($since.$firstAt->format('d/m/Y')),
            'END:VCARD',
        ];

        return collect($lines)->map(fn (string $line) => $this->fold($line))->implode("\r\n")."\r\n";
    }

    /** « {nom} · {business} », ou « Client {4 derniers chiffres} · {business} » sans nom. */
    private function displayName(Business $business, Customer $client, string $digits): string
    {
        $name = '';
        foreach ([$client->name, $client->whatsapp_name] as $candidate) {
            $name = trim(preg_replace('/[^\P{C}\n]+/u', '', (string) $candidate) ?? '');
            if ($name !== '') {
                break;
            }
        }
        $name = $name === '' ? 'Client '.substr($digits, -4) : Str::limit($name, self::NAME_MAX_LENGTH, '…');

        return $name.' · '.trim((string) $business->name);
    }

    /** Échappement vCard des valeurs texte : \ ; , et retour à la ligne. */
    private function escape(string $value): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\;', '\,', '\n', '\n', '\n'], $value);
    }

    /** Ligne repliée tous les 75 octets sans couper un caractère UTF-8. */
    private function fold(string $line): string
    {
        $parts = [];

        // Les lignes repliées commencent par une espace : 74 octets de contenu.
        while (strlen($line) > ($max = $parts === [] ? self::LINE_MAX_BYTES : self::LINE_MAX_BYTES - 1)) {
            $part = mb_strcut($line, 0, $max, 'UTF-8');
            $parts[] = $part;
            $line = substr($line, strlen($part));
        }
        $parts[] = $line;

        return implode("\r\n ", $parts);
    }
}
