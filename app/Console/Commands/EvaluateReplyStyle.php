<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\Message;
use App\Services\AI\AIResponseService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\Table;

/**
 * Rejouer de vraies questions d'un business avec l'ancien prompt de style puis avec
 * le nouveau (profil, exemples du gérant, concision), et comparer les réponses.
 * Rien n'est envoyé au client ni enregistré ; chaque question coûte deux appels à Claude.
 */
class EvaluateReplyStyle extends Command
{
    protected $signature = 'ai:style-eval
        {business : identifiant du business}
        {--limit=20 : nombre de questions rejouées}';

    protected $description = 'Compare les réponses du bot avant / après le style du gérant, sur de vraies questions';

    /** Une question trop courte (« ok », « merci ») ne dit rien du style. */
    private const MIN_WORDS = 3;

    public function handle(AIResponseService $ai): int
    {
        $business = Business::find($this->argument('business'));

        if ($business === null) {
            $this->error('Business introuvable.');

            return self::FAILURE;
        }

        $questions = $this->questions($business, max(1, (int) $this->option('limit')));

        if ($questions === []) {
            $this->warn('Aucune question de client à rejouer pour ce business.');

            return self::SUCCESS;
        }

        $legacy = $ai->withLegacyStyle();
        $rows = [];
        $before = [];
        $after = [];

        foreach ($questions as $i => $question) {
            $this->output->write("\rQuestion ".($i + 1).'/'.count($questions).'…');

            // Sans conversation : pas d'historique ni d'outil, les deux réponses partent du même point.
            $old = (string) ($legacy->answer($business, $question)['answer'] ?? '(escalade)');
            $new = (string) ($ai->answer($business, $question)['answer'] ?? '(escalade)');

            $before[] = mb_strlen($old);
            $after[] = mb_strlen($new);
            $rows[] = [$i + 1, $question, $old, $this->length($old), $new, $this->length($new)];
        }

        $this->output->write("\r");

        (new Table($this->output))
            ->setHeaders(['#', 'Question', 'Avant', 'Long.', 'Après', 'Long.'])
            ->setColumnMaxWidth(1, 30)
            ->setColumnMaxWidth(2, 45)
            ->setColumnMaxWidth(4, 45)
            ->setRows($rows)
            ->render();

        $this->line(sprintf(
            'Longueur médiane : %d caractères avant, %d après (%d question(s)).',
            $this->median($before),
            $this->median($after),
            count($questions),
        ));

        return self::SUCCESS;
    }

    /**
     * Dernières questions distinctes des clients du business.
     *
     * @return list<string>
     */
    private function questions(Business $business, int $limit): array
    {
        $questions = [];

        $messages = Message::query()
            ->whereIn('conversation_id', $business->conversations()->select('id'))
            ->where('direction', 'inbound')
            ->latest()
            ->limit($limit * 10)
            ->pluck('content');

        foreach ($messages as $content) {
            $question = trim((string) $content);
            $key = mb_strtolower($question);

            if (count(preg_split('/\s+/u', $question, -1, PREG_SPLIT_NO_EMPTY) ?: []) < self::MIN_WORDS) {
                continue;
            }

            $questions[$key] ??= $question;

            if (count($questions) >= $limit) {
                break;
            }
        }

        return array_values($questions);
    }

    private function length(string $text): string
    {
        return mb_strlen($text).' car. / '.count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []).' mots';
    }

    /**
     * @param  list<int>  $values
     */
    private function median(array $values): int
    {
        sort($values);
        $n = count($values);

        return $n === 0 ? 0 : (int) round($n % 2 === 1 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2);
    }
}
