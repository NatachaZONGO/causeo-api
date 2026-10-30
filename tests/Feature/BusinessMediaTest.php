<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMedia;
use App\Models\User;
use App\Services\Embedding\EmbeddingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Support\CreatesConversationSchema;
use Tests\TestCase;

/**
 * Médias que le bot envoie sur WhatsApp : seuls des fichiers que WhatsApp sait envoyer sont acceptés
 * (images JPG/PNG de 5 Mo maximum, documents PDF/DOC/DOCX de 16 Mo maximum).
 */
class BusinessMediaTest extends TestCase
{
    use CreatesConversationSchema;

    private User $owner;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createConversationSchema();
        Storage::fake('supabase_media');
        $this->mock(EmbeddingService::class)->shouldReceive('embed')->andThrow(new RuntimeException('hors ligne'));

        $this->owner = User::forceCreate(['name' => 'Awa', 'email' => 'awa@example.test', 'password' => 'x']);
        $id = (string) Str::uuid();
        DB::table('businesses')->insert(['id' => $id, 'user_id' => $this->owner->id, 'name' => 'Chez Awa', 'modules' => '[]']);
        $this->business = Business::findOrFail($id);

        Sanctum::actingAs($this->owner);
    }

    public function test_jpg_image_is_stored_as_an_image_with_its_keywords(): void
    {
        $this->upload(UploadedFile::fake()->image('menu.jpg', 800, 600), ['keywords' => '["menu","plats"]'])
            ->assertCreated()
            ->assertJsonPath('media.type', 'image')
            ->assertJsonPath('media.keywords', ['menu', 'plats']);

        Storage::disk('supabase_media')->assertExists(BusinessMedia::sole()->file_path);
    }

    public function test_png_image_can_be_a_catalog(): void
    {
        $this->upload(UploadedFile::fake()->image('tarifs.png', 600, 900), ['type' => 'catalog'])
            ->assertCreated()
            ->assertJsonPath('media.type', 'catalog');
    }

    public function test_pdf_is_stored_as_a_document(): void
    {
        $this->upload(UploadedFile::fake()->create('catalogue.pdf', 9000, 'application/pdf'))
            ->assertCreated()
            ->assertJsonPath('media.type', 'document');
    }

    public function test_webp_and_gif_images_are_refused_because_whatsapp_cannot_send_them(): void
    {
        foreach (['produit.webp' => 'image/webp', 'promo.gif' => 'image/gif'] as $name => $mime) {
            $this->upload(UploadedFile::fake()->create($name, 200, $mime))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['file' => 'image JPG ou PNG']);
        }

        $this->assertSame(0, BusinessMedia::count());
    }

    public function test_image_over_5_mb_is_refused_but_a_document_up_to_16_mb_is_accepted(): void
    {
        $this->upload(UploadedFile::fake()->image('affiche.jpg')->size(5121))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'ne doit pas dépasser 5 Mo']);

        $this->upload(UploadedFile::fake()->create('fiche.pdf', 16384, 'application/pdf'))->assertCreated();
        $this->upload(UploadedFile::fake()->create('trop-lourd.pdf', 16385, 'application/pdf'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => '16 Mo']);
    }

    public function test_a_pdf_cannot_be_sent_as_an_image_or_a_catalog(): void
    {
        foreach (['image', 'catalog'] as $type) {
            $this->upload(UploadedFile::fake()->create('catalogue.pdf', 300, 'application/pdf'), ['type' => $type])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['type' => 'envoyé comme document']);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    // --- Modification sans renvoyer le fichier ----------------------------------------------------

    public function test_the_owner_edits_the_name_description_and_keywords_without_the_file(): void
    {
        $this->upload(UploadedFile::fake()->image('robe.jpg', 800, 600), ['title' => 'Robe', 'keywords' => '["robe"]'])->assertCreated();
        $media = BusinessMedia::sole();
        $file = $media->file_path;

        // Nouvelle description : l'embedding est recalculé avec les mots-clés, le nom et la description.
        $this->mock(EmbeddingService::class)->shouldReceive('embed')->once()
            ->with('robe, wax. Robe wax Awa. Robe longue en wax rouge')->andReturn([0.1, 0.2]);

        $this->patchJson("/api/v1/businesses/{$this->business->id}/media/{$media->id}", [
            'title' => 'Robe wax Awa',
            'description' => 'Robe longue en wax rouge',
            'keywords' => ['robe', ' wax ', ''],
        ])->assertOk()
            ->assertJsonPath('message', 'Le média a été mis à jour.')
            ->assertJsonPath('media.title', 'Robe wax Awa')
            ->assertJsonPath('media.description', 'Robe longue en wax rouge')
            ->assertJsonPath('media.keywords', ['robe', 'wax'])
            ->assertJsonPath('media.file_path', $file);

        Storage::disk('supabase_media')->assertExists($file);
        $this->assertSame(1, BusinessMedia::count());
    }

    public function test_keywords_can_be_sent_as_text_and_an_unchanged_media_is_not_reembedded(): void
    {
        $this->upload(UploadedFile::fake()->image('robe.jpg'), ['title' => 'Robe', 'description' => 'Rouge', 'keywords' => '["robe"]'])->assertCreated();
        $media = BusinessMedia::sole();

        // Deux recalculs en tout : pas pour la modification sans changement.
        $this->mock(EmbeddingService::class)->shouldReceive('embed')->twice()->andReturn([0.1]);
        $this->patchJson("/api/v1/businesses/{$this->business->id}/media/{$media->id}", ['title' => 'Robe', 'description' => 'Rouge', 'keywords' => 'robe'])
            ->assertOk()->assertJsonPath('media.keywords', ['robe']);

        $this->patchJson("/api/v1/businesses/{$this->business->id}/media/{$media->id}", ['keywords' => 'robe, wax , pagne'])
            ->assertOk()->assertJsonPath('media.keywords', ['robe', 'wax', 'pagne'])->assertJsonPath('media.title', 'Robe');

        // Description effacée.
        $this->patchJson("/api/v1/businesses/{$this->business->id}/media/{$media->id}", ['description' => null])
            ->assertOk()->assertJsonPath('media.description', null);
    }

    public function test_media_edits_are_validated_and_private(): void
    {
        $this->upload(UploadedFile::fake()->image('robe.jpg'))->assertCreated();
        $media = BusinessMedia::sole();

        $this->patchJson("/api/v1/businesses/{$this->business->id}/media/{$media->id}", ['title' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors(['title' => 'Le nom est obligatoire.']);
        $this->patchJson("/api/v1/businesses/{$this->business->id}/media/{$media->id}", ['title' => str_repeat('a', 256)])
            ->assertUnprocessable()->assertJsonValidationErrors('title');

        // Un média d'une autre entreprise, même la sienne en paramètre : introuvable.
        $otherId = (string) Str::uuid();
        DB::table('businesses')->insert(['id' => $otherId, 'user_id' => $this->owner->id, 'name' => 'Autre boutique', 'modules' => '[]']);
        $this->patchJson("/api/v1/businesses/{$otherId}/media/{$media->id}", ['title' => 'X'])->assertNotFound();

        // L'entreprise d'un autre gérant : interdit.
        Sanctum::actingAs(User::forceCreate(['name' => 'Intrus', 'email' => 'intrus@example.test', 'password' => 'x']));
        $this->patchJson("/api/v1/businesses/{$this->business->id}/media/{$media->id}", ['title' => 'X'])->assertForbidden();
        $this->assertSame('Média', $media->fresh()->title);
    }

    private function upload(UploadedFile $file, array $extra = []): TestResponse
    {
        return $this->post(
            "/api/v1/businesses/{$this->business->id}/media",
            ['file' => $file, 'title' => 'Média', ...$extra],
            ['Accept' => 'application/json'],
        );
    }
}
