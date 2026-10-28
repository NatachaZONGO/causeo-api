<?php

namespace App\Services\Conversations;

use App\Models\Message;
use App\Services\AI\CustomerImageService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Liens TikTok, Instagram et Facebook envoyés par le client. Pour TikTok, la vignette et la
 * légende de la vidéo sont lues avec l'oEmbed public (sans clé) et montrées à l'assistant
 * comme une photo du client. Instagram et Facebook n'ont plus d'oEmbed public qui donne
 * l'image : l'assistant demande une capture d'écran, comme quand TikTok échoue.
 */
class SocialLinkService
{
    public const TIKTOK_OEMBED = 'https://www.tiktok.com/oembed';

    /** Délai court : un lien illisible ne doit pas retarder la réponse. */
    private const TIMEOUT_SECONDS = 4;

    /** Liens traités par rafale, au plus. */
    private const MAX_LINKS = 2;

    /** Liens courts de TikTok (vm.tiktok.com/…), redirigés vers la vidéo. */
    private const TIKTOK_SHORT_HOSTS = ['vm.tiktok.com', 'vt.tiktok.com'];

    private const PLATFORMS = [
        'TikTok' => '/(^|\.)tiktok\.com$/',
        'Instagram' => '/(^|\.)(instagram\.com|instagr\.am)$/',
        'Facebook' => '/(^|\.)(facebook\.com|fb\.com|fb\.watch)$/',
    ];

    public function __construct(
        private readonly CustomerImageService $images,
    ) {}

    /**
     * Liens des messages d'une rafale, décrits pour l'assistant, et les vignettes à lui montrer.
     *
     * @param  Collection<int, Message>  $batch
     * @return array{quotes: list<array{note: string, search: ?string}>, images: list<array<string, mixed>>}
     */
    public function forBatch(Collection $batch): array
    {
        $quotes = [];
        $images = [];

        foreach (array_slice(self::links($batch->pluck('content')->implode("\n")), 0, self::MAX_LINKS) as [$platform, $url]) {
            $video = $platform === 'TikTok' ? $this->tiktok($url) : null;

            if ($video === null) {
                $quotes[] = [
                    'note' => "Le client a envoyé un lien {$platform} que tu ne peux pas ouvrir. Ne devine pas ce qu'il montre : "
                        ."demande-lui simplement une capture d'écran de l'article qui l'intéresse.",
                    'search' => null,
                ];

                continue;
            }

            $caption = $video['title'] !== '' ? " (légende : « {$video['title']} »)" : '';
            $quotes[] = [
                'note' => "Le client a envoyé un lien vers une vidéo TikTok{$caption}."
                    .($video['image'] !== null
                        ? " L'image de la vidéo est jointe au message : traite-la comme une photo envoyée par le client et cherche l'article dans le catalogue."
                        : " L'image de la vidéo n'a pas pu être affichée : si la légende ne suffit pas, demande une capture d'écran de l'article."),
                'search' => $video['title'] !== '' ? $video['title'] : null,
            ];
            $images[] = $video['image'];
        }

        return ['quotes' => $quotes, 'images' => array_values(array_filter($images))];
    }

    /**
     * Liens de réseaux sociaux d'un texte, avec ou sans « https:// ».
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function links(string $text): array
    {
        preg_match_all('~(?:https?://)?(?:[a-z0-9-]+\.)*(?:tiktok\.com|instagram\.com|instagr\.am|facebook\.com|fb\.com|fb\.watch)(?![\w-]|\.[\w-])(?:/[^\s<>"«»]*)?~i', $text, $matches);

        $links = [];
        foreach (array_unique($matches[0]) as $url) {
            $url = rtrim(preg_match('~^https?://~i', $url) ? $url : "https://{$url}", '.,;:!?)');
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));

            foreach (self::PLATFORMS as $platform => $pattern) {
                if (preg_match($pattern, $host) === 1) {
                    $links[] = [$platform, $url];

                    break;
                }
            }
        }

        return $links;
    }

    /**
     * Légende et vignette d'une vidéo TikTok, ou null si l'oEmbed échoue.
     *
     * @return array{title: string, image: ?array<string, mixed>}|null
     */
    private function tiktok(string $url): ?array
    {
        try {
            $url = $this->resolveShortLink($url);

            if ($url === null) {
                return null;
            }

            $response = Http::timeout(self::TIMEOUT_SECONDS)->acceptJson()->get(self::TIKTOK_OEMBED, ['url' => $url]);
            $thumbnail = (string) $response->json('thumbnail_url', '');

            if (! $response->successful() || $thumbnail === '') {
                Log::info('SocialLinkService: oEmbed TikTok sans vignette.', ['status' => $response->status()]);

                return null;
            }

            return [
                'title' => Str::limit(trim((string) $response->json('title', '')), 300),
                'image' => $this->images->fromUrl($thumbnail, CustomerImageService::TIKTOK_HOSTS, self::TIMEOUT_SECONDS),
            ];
        } catch (Throwable $e) {
            Log::info('SocialLinkService: lien TikTok illisible.', ['exception' => $e::class]);

            return null;
        }
    }

    /**
     * Adresse de la vidéo derrière un lien court (vm.tiktok.com/…), lue dans la redirection
     * sans la suivre : seul tiktok.com est appelé.
     */
    private function resolveShortLink(string $url): ?string
    {
        if (! in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), self::TIKTOK_SHORT_HOSTS, true)) {
            return $url;
        }

        $location = (string) Http::timeout(self::TIMEOUT_SECONDS)->withoutRedirecting()->get($url)->header('Location');
        $host = strtolower((string) parse_url($location, PHP_URL_HOST));

        return str_starts_with($location, 'https://') && preg_match(self::PLATFORMS['TikTok'], $host) === 1 ? $location : null;
    }
}
