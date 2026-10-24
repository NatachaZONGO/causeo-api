<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesConversationSchema;
use Tests\TestCase;

/**
 * Personnalisation du business : logo et couleur de marque.
 */
class BusinessBrandingTest extends TestCase
{
    use CreatesConversationSchema;

    private User $owner;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createConversationSchema();
        Storage::fake('supabase_media');

        $this->owner = User::forceCreate(['name' => 'Joyce', 'email' => 'joyce@example.test', 'password' => 'x']);
        $id = (string) Str::uuid();
        DB::table('businesses')->insert(['id' => $id, 'user_id' => $this->owner->id, 'name' => 'Joyce Boutique', 'modules' => '[]']);
        $this->business = Business::findOrFail($id);
    }

    public function test_the_owner_uploads_a_logo_reduced_to_512_px_and_replaces_the_previous_one(): void
    {
        Sanctum::actingAs($this->owner);
        $url = "/api/v1/businesses/{$this->business->id}/logo";

        $response = $this->post($url, ['logo' => $this->image('logo.png', 1600, 800, 'png')], ['Accept' => 'application/json'])->assertOk();

        $first = $this->business->fresh()->logo_path;
        $this->assertMatchesRegularExpression("#^logos/{$this->business->id}/[0-9a-f-]{36}\\.png$#", $first);
        Storage::disk('supabase_media')->assertExists($first);
        $this->assertSame([512, 256], array_slice(getimagesizefromstring(Storage::disk('supabase_media')->get($first)), 0, 2));
        $response->assertJsonPath('business.logo_url', Storage::disk('supabase_media')->url($first));
        $this->assertArrayNotHasKey('logo_path', $response->json('business'));

        // Un JPEG plus petit que 512 px reste un JPEG, à sa taille ; l'ancien fichier est supprimé.
        $this->post($url, ['logo' => $this->image('logo.jpg', 300, 200, 'jpeg')], ['Accept' => 'application/json'])->assertOk();
        $second = $this->business->fresh()->logo_path;
        $this->assertStringEndsWith('.jpg', $second);
        $this->assertSame([300, 200], array_slice(getimagesizefromstring(Storage::disk('supabase_media')->get($second)), 0, 2));
        Storage::disk('supabase_media')->assertMissing($first);

        // Business courant (auth/me) : logo_url renvoyé.
        $this->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('user.businesses.0.logo_url', Storage::disk('supabase_media')->url($second));

        $this->deleteJson($url)->assertOk()->assertJsonPath('business.logo_url', null);
        Storage::disk('supabase_media')->assertMissing($second);
        $this->assertNull($this->business->fresh()->logo_path);
    }

    public function test_the_logo_must_be_a_light_enough_image_and_only_the_owner_can_change_it(): void
    {
        Sanctum::actingAs($this->owner);
        $url = "/api/v1/businesses/{$this->business->id}/logo";

        $this->postJson($url, ['logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')])
            ->assertUnprocessable()->assertJsonPath('errors.logo.0', 'Le logo doit être une image JPG, PNG ou WebP.');
        $this->postJson($url, ['logo' => UploadedFile::fake()->create('logo.png', 2100, 'image/png')])
            ->assertUnprocessable()->assertJsonPath('errors.logo.0', 'Le logo ne doit pas dépasser 2 Mo.');
        $this->postJson($url)->assertUnprocessable()->assertJsonPath('errors.logo.0', 'Choisissez une image pour le logo.');

        Sanctum::actingAs(User::forceCreate(['name' => 'Autre', 'email' => 'autre@example.test', 'password' => 'x']));
        $this->postJson($url, ['logo' => $this->image('logo.png', 100, 100, 'png')])->assertForbidden();
        $this->deleteJson($url)->assertForbidden();
        $this->assertSame([], Storage::disk('supabase_media')->allFiles());
    }

    public function test_brand_color_is_validated_and_returns_a_readable_text_color(): void
    {
        Sanctum::actingAs($this->owner);
        $url = "/api/v1/businesses/{$this->business->id}";

        $this->putJson($url, ['brand_color' => '#0f766e'])->assertOk()
            ->assertJsonPath('business.brand_color', '#0F766E')
            ->assertJsonPath('business.brand_text_color', '#FFFFFF');

        // Couleur claire : texte foncé, sans refuser la couleur.
        $this->putJson($url, ['brand_color' => '#FDE68A'])->assertOk()
            ->assertJsonPath('business.brand_text_color', '#111827');

        $this->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('user.businesses.0.brand_color', '#FDE68A')
            ->assertJsonPath('user.businesses.0.brand_text_color', '#111827');

        foreach (['0F766E', '#0F766', 'teal', '#GGGGGG'] as $invalid) {
            $this->putJson($url, ['brand_color' => $invalid])->assertUnprocessable()
                ->assertJsonPath('errors.brand_color.0', 'La couleur doit être au format hexadécimal #RRGGBB, par exemple #0F766E.');
        }

        $this->putJson($url, ['brand_color' => null])->assertOk()
            ->assertJsonPath('business.brand_color', null)
            ->assertJsonPath('business.brand_text_color', null);
    }

    public function test_text_color_follows_the_contrast_of_the_brand_color(): void
    {
        foreach (['#000000' => '#FFFFFF', '#FFFFFF' => '#111827', '#DC2626' => '#FFFFFF', '#22C55E' => '#111827', '#1D4ED8' => '#FFFFFF'] as $brand => $text) {
            $this->assertSame($text, (new Business(['brand_color' => $brand]))->brand_text_color, $brand);
        }
    }

    private function image(string $name, int $width, int $height, string $format): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'logo');
        $image = imagecreatetruecolor($width, $height);
        $format === 'png' ? imagepng($image, $path) : imagejpeg($image, $path);

        return new UploadedFile($path, $name, $format === 'png' ? 'image/png' : 'image/jpeg', null, true);
    }
}
