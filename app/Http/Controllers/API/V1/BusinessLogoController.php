<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Logo du business : téléversé dans le bucket media (supabase_media), réduit à
 * MAX_DIMENSION pixels ; l'ancien fichier est supprimé. Réservé au gérant.
 */
class BusinessLogoController extends Controller
{
    /** Plus grand côté du logo enregistré. */
    public const MAX_DIMENSION = 512;

    public const MAX_KB = 2048;

    public function store(Request $request, Business $business): JsonResponse
    {
        $this->checkOwnership($business);

        $request->validate([
            'logo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_KB],
        ], [
            'logo.required' => 'Choisissez une image pour le logo.',
            'logo.file' => 'Le logo doit être un fichier image.',
            'logo.mimes' => 'Le logo doit être une image JPG, PNG ou WebP.',
            'logo.max' => 'Le logo ne doit pas dépasser 2 Mo.',
        ]);

        $file = $request->file('logo');
        [$body, $extension, $mimeType] = $this->resize((string) file_get_contents($file->getRealPath()), $file->getMimeType());

        abort_if($body === null, 422, 'Cette image est illisible : essayez avec un autre fichier JPG, PNG ou WebP.');

        // Nom unique à chaque envoi : l'ancienne URL n'est pas resservie par un cache.
        $path = "logos/{$business->id}/".Str::uuid().".{$extension}";
        $disk = Storage::disk(Business::LOGO_DISK);
        $disk->put($path, $body, ['visibility' => 'public', 'ContentType' => $mimeType]);

        $previous = $business->logo_path;
        $business->update(['logo_path' => $path]);
        $this->deleteFile($previous);

        return response()->json([
            'message' => 'Le logo a été enregistré.',
            'business' => $business->fresh(),
        ]);
    }

    public function destroy(Business $business): JsonResponse
    {
        $this->checkOwnership($business);

        $this->deleteFile($business->logo_path);
        $business->update(['logo_path' => null]);

        return response()->json([
            'message' => 'Le logo a été retiré.',
            'business' => $business->fresh(),
        ]);
    }

    /**
     * Réduire l'image à MAX_DIMENSION pixels de côté, en gardant la transparence d'un PNG
     * ou d'un WebP (enregistrés en PNG) ; un JPEG reste un JPEG.
     *
     * @return array{0: ?string, 1: string, 2: string}
     */
    private function resize(string $body, ?string $mimeType): array
    {
        $isJpeg = in_array($mimeType, ['image/jpeg', 'image/jpg'], true);
        $size = @getimagesizefromstring($body);
        $source = $size !== false ? @imagecreatefromstring($body) : false;

        if ($source === false) {
            return [null, '', ''];
        }

        [$width, $height] = $size;
        $ratio = min(1, self::MAX_DIMENSION / max($width, $height));
        $target = imagecreatetruecolor(max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio)));

        if (! $isJpeg) {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            imagefill($target, 0, 0, (int) imagecolorallocatealpha($target, 0, 0, 0, 127));
        }

        imagecopyresampled($target, $source, 0, 0, 0, 0, imagesx($target), imagesy($target), $width, $height);

        ob_start();
        $isJpeg ? imagejpeg($target, null, 88) : imagepng($target, null, 9);
        $resized = (string) ob_get_clean();

        return [$resized !== '' ? $resized : null, $isJpeg ? 'jpg' : 'png', $isJpeg ? 'image/jpeg' : 'image/png'];
    }

    /** Un ancien fichier introuvable ou non supprimé ne bloque pas le changement de logo. */
    private function deleteFile(?string $path): void
    {
        if (empty($path)) {
            return;
        }

        try {
            Storage::disk(Business::LOGO_DISK)->delete($path);
        } catch (Throwable $e) {
            Log::warning('BusinessLogoController: ancien logo non supprimé.', ['path' => $path, 'exception' => $e::class]);
        }
    }

    private function checkOwnership(Business $business): void
    {
        abort_if($business->user_id !== auth()->id(), 403, 'Cette entreprise ne vous appartient pas.');
    }
}
