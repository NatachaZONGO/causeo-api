<?php

namespace App\Services\Contacts;

use App\Models\Business;
use App\Models\Conversation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumber;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Contacts clients d'un business, exportés en vCard 3.0 pour que le gérant les enregistre dans
 * son téléphone (et voie ses statuts WhatsApp). Un client = un numéro de conversation : s'il en a
 * plusieurs, la plus ancienne compte. Aucun nom ni numéro de client n'est écrit dans les logs.
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

    /** Pays d'un business sans pays valide, pour l'indicatif des numéros locaux. */
    private const DEFAULT_COUNTRY = 'BF';

    /**
     * @return array{total: int, new_since_last_export: int, last_exported_at: ?string}
     */
    public function summary(Business $business): array
    {
        $firsts = DB::query()->fromSub(
            Conversation::query()
                ->where('business_id', $business->id)
                ->groupBy('customer_phone')
                ->selectRaw('customer_phone, min(created_at) as first_at'),
            'clients',
        );
        $since = $business->contacts_exported_at;

        return [
            'total' => (clone $firsts)->count(),
            'new_since_last_export' => $since === null ? (clone $firsts)->count() : (clone $firsts)->where('first_at', '>', $since)->count(),
            'last_exported_at' => $since?->toIso8601String(),
        ];
    }

    /**
     * Clients à exporter, le plus ancien d'abord. scope=new : ceux arrivés après le dernier
     * export (tous s'il n'y en a jamais eu).
     *
     * @return Collection<int, Conversation>
     */
    public function clients(Business $business, string $scope): Collection
    {
        $clients = Conversation::query()
            ->where('business_id', $business->id)
            ->select(['id', 'customer_phone', 'customer_name', 'created_at'])
            ->withMin('messages', 'created_at')
            ->orderBy('created_at')
            ->get()
            ->unique('customer_phone')
            ->values();

        $since = $business->contacts_exported_at;

        return $scope === self::SCOPE_NEW && $since !== null
            ? $clients->filter(fn (Conversation $client) => $client->created_at->gt($since))->values()
            : $clients;
    }

    /**
     * @param  Collection<int, Conversation>  $clients
     */
    public function vcards(Business $business, Collection $clients): string
    {
        return $clients->map(fn (Conversation $client) => $this->vcard($business, $client))->implode('');
    }

    /** clients-{slug}-{date}.vcf, la date dans le fuseau du business. */
    public function filename(Business $business): string
    {
        $slug = Str::slug((string) $business->name) ?: 'business';

        return "clients-{$slug}-".now($business->localTimezone())->format('Y-m-d').'.vcf';
    }

    private function vcard(Business $business, Conversation $client): string
    {
        $digits = $this->digits($business, $client->customer_phone);
        $label = $this->escape($this->displayName($business, $client, $digits));
        $firstAt = Carbon::parse($client->messages_min_created_at ?? $client->created_at)->setTimezone($business->localTimezone());

        $lines = [
            'BEGIN:VCARD',
            'VERSION:3.0',
            "FN:{$label}",
            "N:{$label};;;;",
            "TEL;TYPE=CELL:+{$digits}",
            'NOTE:'.$this->escape('Client WhatsApp depuis le '.$firstAt->format('d/m/Y')),
            'END:VCARD',
        ];

        return collect($lines)->map(fn (string $line) => $this->fold($line))->implode("\r\n")."\r\n";
    }

    /** « {nom} · {business} », ou « Client {4 derniers chiffres} · {business} » sans nom. */
    private function displayName(Business $business, Conversation $client, string $digits): string
    {
        $name = trim(preg_replace('/[^\P{C}\n]+/u', '', (string) $client->customer_name) ?? '');
        $name = $name === '' ? 'Client '.substr($digits, -4) : Str::limit($name, self::NAME_MAX_LENGTH, '…');

        return $name.' · '.trim((string) $business->name);
    }

    /**
     * Numéro en chiffres, indicatif compris. Un wa_id contient déjà son indicatif et reste tel
     * quel ; seul un numéro local sans indicatif reçoit celui du pays de l'entreprise (Burkina par
     * défaut). Un numéro que libphonenumber ne reconnaît pas reste tel quel.
     */
    private function digits(Business $business, string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        $util = PhoneNumberUtil::getInstance();
        $international = $this->parse("+{$digits}", null);

        if ($international !== null && $util->isValidNumber($international)) {
            return $digits;
        }

        $local = $this->parse($digits, strtoupper(trim((string) $business->country)) ?: self::DEFAULT_COUNTRY)
            ?? $this->parse($digits, self::DEFAULT_COUNTRY);

        if ($local !== null && $util->isValidNumber($local)) {
            return ltrim($util->format($local, PhoneNumberFormat::E164), '+');
        }

        return $digits;
    }

    private function parse(string $number, ?string $region): ?PhoneNumber
    {
        try {
            return PhoneNumberUtil::getInstance()->parse($number, $region);
        } catch (NumberParseException) {
            return null;
        }
    }

    /** Échappement vCard des valeurs texte : \ ; , et retour à la ligne. */
    private function escape(string $value): string
    {
        return str_replace(["\\", ';', ',', "\r\n", "\n", "\r"], ["\\\\", '\;', '\,', '\n', '\n', '\n'], $value);
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
