<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Horaires d'ouverture saisis librement (« lundi-samedi » => « 9h - 20h », « dimanche » =>
 * « fermé », ou « Lundi au vendredi 8h-12h / 14h-18h »), lus pour proposer au client des
 * créneaux de rendez-vous concrets. Ce qui ne se lit pas est ignoré : sans aucun jour lisible,
 * aucun créneau n'est proposé et le modèle s'appuie sur les horaires du contexte.
 */
final class OpeningHours
{
    /** Jours ISO (1 = lundi) : nom complet puis abréviations. */
    private const DAYS = [
        1 => ['lundi', 'lun'],
        2 => ['mardi', 'mar'],
        3 => ['mercredi', 'mer'],
        4 => ['jeudi', 'jeu'],
        5 => ['vendredi', 'ven'],
        6 => ['samedi', 'sam'],
        7 => ['dimanche', 'dim'],
    ];

    /** Heure de l'après-midi proposée quand elle tombe dans les horaires (« samedi à 15h »). */
    private const AFTERNOON_HOUR = 15;

    /** Un créneau commence au moins une heure après l'ouverture et finit avant la fermeture. */
    private const MARGIN_MINUTES = 60;

    /**
     * Plages d'ouverture par jour ISO, en minutes depuis minuit ; un jour fermé n'a aucune plage,
     * un jour absent des horaires n'est pas dans le tableau.
     *
     * @param  mixed  $hours  businesses.opening_hours
     * @return array<int, list<array{0: int, 1: int}>>
     */
    public static function parse($hours): array
    {
        $segments = match (true) {
            is_string($hours) => preg_split('/[;\n]+/', $hours) ?: [],
            is_array($hours) => array_map(
                fn ($value, $key) => (is_string($key) ? $key.' ' : '').(is_array($value) ? implode(' - ', array_filter($value, 'is_scalar')) : (string) $value),
                $hours,
                array_keys($hours),
            ),
            default => [],
        };

        $week = [];
        foreach ($segments as $segment) {
            $segment = mb_strtolower(trim((string) $segment));
            $days = self::days($segment);

            if ($days === []) {
                continue;
            }

            $ranges = preg_match('/ferm[ée]|closed|repos/u', $segment) ? [] : self::ranges($segment);
            foreach ($days as $day) {
                $week[$day] = $ranges;
            }
        }
        ksort($week);

        return $week;
    }

    /**
     * Deux créneaux à proposer, à partir de demain : le premier jour ouvert le matin (une heure
     * après l'ouverture), puis un autre jour ouvert l'après-midi, le samedi s'il est ouvert.
     *
     * @param  mixed  $hours
     * @return list<CarbonImmutable>
     */
    public static function suggestSlots($hours, CarbonImmutable $now): array
    {
        $week = self::parse($hours);
        $openDays = [];

        for ($offset = 1; $offset <= 7; $offset++) {
            $day = $now->startOfDay()->addDays($offset);
            if (($week[$day->dayOfWeekIso] ?? []) !== []) {
                $openDays[] = $day;
            }
        }

        if ($openDays === []) {
            return [];
        }

        $first = array_shift($openDays);
        $slots = [$first->addMinutes(self::morningSlot($week[$first->dayOfWeekIso]))];

        $second = collect($openDays)->first(fn (CarbonImmutable $day) => $day->dayOfWeekIso === 6) ?? ($openDays[0] ?? null);
        if ($second !== null) {
            $slots[] = $second->addMinutes(self::afternoonSlot($week[$second->dayOfWeekIso]));
        }

        return $slots;
    }

    /** « demain jeudi 31 octobre à 10h », « samedi 2 novembre à 15h30 ». */
    public static function describe(CarbonImmutable $slot, CarbonImmutable $now): string
    {
        $date = $slot->locale('fr')->isoFormat('dddd D MMMM');
        $time = $slot->minute === 0 ? $slot->format('G').'h' : $slot->format('G').'h'.$slot->format('i');

        return ($slot->isSameDay($now->addDay()) ? 'demain ' : '').$date.' à '.$time;
    }

    /**
     * @return list<int>
     */
    private static function days(string $segment): array
    {
        if (preg_match('/tous les jours|chaque jour|7\s*j\s*\/\s*7|7\s*\/\s*7/u', $segment)) {
            return array_keys(self::DAYS);
        }

        $names = implode('|', array_merge(...array_values(self::DAYS)));
        $range = "/\\b({$names})\\b\\.?\\s*(?:-|–|au|à|a)\\s*\\b({$names})\\b/u";

        if (preg_match($range, $segment, $match)) {
            [$from, $to] = [self::dayNumber($match[1]), self::dayNumber($match[2])];
            $days = [];
            for ($day = $from; ; $day = $day % 7 + 1) {
                $days[] = $day;
                if ($day === $to) {
                    break;
                }
            }

            return $days;
        }

        preg_match_all("/\\b({$names})\\b/u", $segment, $matches);

        return array_values(array_unique(array_map(self::dayNumber(...), $matches[1])));
    }

    private static function dayNumber(string $name): int
    {
        foreach (self::DAYS as $number => $names) {
            if (in_array($name, $names, true)) {
                return $number;
            }
        }

        return 1;
    }

    /**
     * « 8h-12h / 14h-18h » → [[480, 720], [840, 1080]].
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function ranges(string $segment): array
    {
        preg_match_all('/\b([01]?\d|2[0-3])\s*(?:h|:)\s*([0-5]\d)?/u', $segment, $matches, PREG_SET_ORDER);
        $times = array_map(fn (array $match) => (int) $match[1] * 60 + (int) ($match[2] ?? 0), $matches);

        $ranges = [];
        for ($i = 0; $i + 1 < count($times); $i += 2) {
            if ($times[$i + 1] - $times[$i] >= 2 * self::MARGIN_MINUTES) {
                $ranges[] = [$times[$i], $times[$i + 1]];
            }
        }

        return $ranges;
    }

    /** Une heure après l'ouverture, arrondie à l'heure suivante. */
    private static function morningSlot(array $ranges): int
    {
        return (int) ceil(($ranges[0][0] + self::MARGIN_MINUTES) / 60) * 60;
    }

    /** 15h si la plage le permet, sinon deux heures avant la fermeture. */
    private static function afternoonSlot(array $ranges): int
    {
        foreach ($ranges as [$open, $close]) {
            $afternoon = self::AFTERNOON_HOUR * 60;
            if ($afternoon >= $open && $afternoon <= $close - self::MARGIN_MINUTES) {
                return $afternoon;
            }
        }

        $last = $ranges[count($ranges) - 1];

        return max(self::morningSlot([$last]), (int) floor(($last[1] - 2 * self::MARGIN_MINUTES) / 60) * 60);
    }
}
