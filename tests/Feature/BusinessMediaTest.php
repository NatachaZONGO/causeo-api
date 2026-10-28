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
    private function upload(UploadedFile $file, array $extra = []): TestResponse
    {
        return $this->post(
            "/api/v1/businesses/{$this->business->id}/media",
            ['file' => $file, 'title' => 'Média', ...$extra],
            ['Accept' => 'application/json'],
        );
    }
}
