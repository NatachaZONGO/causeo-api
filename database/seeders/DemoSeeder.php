<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Escalation;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Embedding\EmbeddingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

/**
 * Données de démonstration pour la base de développement (causeo-dev) : une boutique de
 * mode avec commandes et une coiffure avec rendez-vous, chacune avec son catalogue et
 * des conversations. Relancer le seeder remplace ces données (comptes @demo.causeo.test).
 * Jamais en production.
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'demo1234';

    public const DOMAIN = 'demo.causeo.test';

    /** Le catalogue n'est trouvé par le bot qu'avec ses embeddings (clé Gemini nécessaire). */
    private bool $embeddingsAvailable = true;

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException('DemoSeeder ne s\'exécute jamais en production.');
        }

        $this->removePreviousDemo();

        User::forceCreate(['name' => 'Admin Causeo', 'email' => 'admin@'.self::DOMAIN, 'password' => Hash::make(self::PASSWORD), 'is_admin' => true]);

        $this->fashionShop();
        $this->hairSalon();

        if (! $this->embeddingsAvailable) {
            $this->command?->warn('Catalogues enregistrés sans embeddings (GEMINI_API_KEY absente ou API injoignable) : le bot ne les trouvera pas. Relancez le seeder une fois la clé renseignée.');
        }
    }

    private function fashionShop(): void
    {
        $owner = $this->owner('Awa Ouédraogo', 'boutique');
        $business = $this->business($owner, [
            'name' => 'Awa Couture',
            'type' => 'boutique',
            'description' => 'Boutique de mode à Ouagadougou : robes et chemises en wax, en Faso Dan Fani et en lin.',
            'city' => 'Ouagadougou',
            'address' => 'Avenue Kwame Nkrumah, à côté de la pharmacie du Progrès',
            'opening_hours' => ['lundi-samedi' => '9h - 20h', 'dimanche' => 'fermé'],
            'modules' => ['orders'],
            'delivery_enabled' => true,
            'pickup_enabled' => true,
            'shipping_enabled' => false,
            'brand_color' => '#B45309',
        ]);

        $this->catalog($business, 'Catalogue et conditions', [
            "Robe wax Awa (longue, rouge et jaune) : 12 500 FCFA. Tailles 38 à 46. En stock.\nRobe Faso Dan Fani brodée : 18 000 FCFA. Tailles 38 à 44. En stock.\nEnsemble pagne wax bleu (haut + jupe) : 15 000 FCFA. Tailles S, M, L.",
            "Chemise homme en lin, manches courtes : 9 000 FCFA. Tailles M, L, XL. Couleurs : blanc, beige, bleu ciel.\nChemise en Faso Dan Fani : 11 500 FCFA. Tailles M à XXL.\nPagne wax 6 yards : 7 500 FCFA.",
            "Livraison à Ouagadougou : 1 000 FCFA, sous 24 h. Retrait gratuit à la boutique.\nPaiement : Orange Money, Moov Money ou espèces au retrait.\nRetouche de taille offerte dans les 7 jours avec le ticket.",
        ]);

        $now = CarbonImmutable::now();

        $mariam = $this->conversation($business, '22670112201', 'Mariam Sawadogo', [
            [-180, 'in', 'Bonjour, la robe wax Awa est disponible en 42 ?'],
            [-179, 'ai', 'Bonjour ! Oui, la robe wax Awa est disponible en 42, à 12 500 FCFA. Souhaitez-vous la commander ?'],
            [-175, 'in', 'Oui, livrée à Ouaga 2000 svp'],
            [-174, 'ai', "Récapitulatif :\n1 × Robe wax Awa (42) : 12 500 FCFA\nLivraison à Ouagadougou : 1 000 FCFA\nTotal : 13 500 FCFA, paiement Orange Money.\nJe confirme la commande ?"],
            [-173, 'in', 'Oui'],
            [-172, 'ai', 'Votre commande est enregistrée ✅ Nous revenons vers vous très vite pour finaliser la livraison et le paiement 😊'],
        ]);
        $order = Order::create([
            'business_id' => $business->id, 'conversation_id' => $mariam->id, 'customer_phone' => $mariam->customer_phone, 'customer_name' => 'Mariam Sawadogo',
            'items' => [['name' => 'Robe wax Awa', 'options' => 'Taille 42', 'quantity' => 1, 'unit_price' => 12500], ['name' => 'Frais de livraison', 'quantity' => 1, 'unit_price' => 1000]],
            'total_amount' => 13500, 'fulfillment_type' => 'delivery', 'delivery_city' => 'Ouagadougou', 'delivery_address' => 'Ouaga 2000',
            'payment_method' => 'Orange Money', 'status' => 'new',
        ]);
        $this->backdate($order, $now->subMinutes(172));

        $issa = $this->conversation($business, '22676543202', 'Issa Kaboré', [
            [-2 * 1440, 'in', 'Vous avez des chemises en lin ?'],
            [-2 * 1440 + 1, 'ai', 'Oui, la chemise homme en lin manches courtes est à 9 000 FCFA, en M, L et XL (blanc, beige ou bleu ciel).'],
            [-2 * 1440 + 3, 'in', 'Une beige en L, je passe la chercher demain'],
            [-2 * 1440 + 5, 'ai', 'Votre commande est enregistrée ✅ Vous pouvez la retirer à la boutique, avenue Kwame Nkrumah, de 9h à 20h.'],
        ]);
        $delivered = Order::create([
            'business_id' => $business->id, 'conversation_id' => $issa->id, 'customer_phone' => $issa->customer_phone, 'customer_name' => 'Issa Kaboré',
            'items' => [['name' => 'Chemise homme en lin', 'options' => 'L, beige', 'quantity' => 1, 'unit_price' => 9000]],
            'total_amount' => 9000, 'fulfillment_type' => 'pickup', 'payment_method' => 'Espèces', 'status' => 'delivered',
        ]);
        $this->backdate($delivered, $now->subDays(2));

        $fatim = $this->conversation($business, '22665432103', 'Fatimata Traoré', [
            [-25, 'in', 'Bonsoir, vous livrez à Koudougou ?'],
            [-24, 'ai', 'Je vérifie ça et je reviens vers vous très vite 😊', ['escalated' => true]],
        ], inboundStatus: 'escalated');
        $this->escalate($fatim, 'Bonsoir, vous livrez à Koudougou ?', $now->subMinutes(24));
    }

    private function hairSalon(): void
    {
        $owner = $this->owner('Joëlle Zongo', 'coiffure');
        $business = $this->business($owner, [
            'name' => 'Salon Belle Tresse',
            'type' => 'salon_beaute',
            'description' => 'Salon de coiffure femme à Ouagadougou : tresses, nattes, défrisage et soins.',
            'city' => 'Ouagadougou',
            'address' => 'Quartier Gounghin, rue 17.42, près du marché',
            'opening_hours' => ['mardi-samedi' => '8h - 19h', 'dimanche' => '10h - 15h', 'lundi' => 'fermé'],
            'modules' => ['appointments'],
            'brand_color' => '#BE185D',
        ]);

        $this->catalog($business, 'Prestations et tarifs', [
            "Tresses africaines (box braids) : 15 000 FCFA, environ 4 h, mèches comprises.\nNattes collées simples : 5 000 FCFA, environ 1 h 30.\nTresses enfant (moins de 10 ans) : 6 000 FCFA.",
            "Défrisage + brushing : 7 500 FCFA. Soin hydratant profond : 4 000 FCFA. Coupe femme : 3 000 FCFA.\nCoiffure de mariage à domicile : à partir de 25 000 FCFA, sur rendez-vous uniquement.",
            "Rendez-vous conseillé, surtout le samedi. Merci de prévenir 24 h à l'avance en cas d'empêchement.\nPaiement : espèces, Orange Money ou Moov Money.",
        ]);

        $now = CarbonImmutable::now();
        $saturday = $now->next(CarbonImmutable::SATURDAY);

        $rasmata = $this->conversation($business, '22670998804', 'Rasmata Ilboudo', [
            [-90, 'in', 'Bonjour, je veux des box braids samedi matin'],
            [-89, 'ai', "Bonjour ! Les tresses africaines (box braids) sont à 15 000 FCFA, comptez environ 4 h. À quelle heure samedi {$saturday->locale('fr')->isoFormat('D MMMM')} ?"],
            [-87, 'in', '9h'],
            [-86, 'ai', "Récapitulatif : tresses africaines, samedi {$saturday->locale('fr')->isoFormat('D MMMM')} à 9h, 15 000 FCFA. J'enregistre votre demande ?"],
            [-85, 'in', 'Oui merci'],
            [-84, 'ai', 'Votre demande est enregistrée ✅ Le salon vous confirme le créneau très vite.'],
        ]);
        $requested = Appointment::create([
            'business_id' => $business->id, 'conversation_id' => $rasmata->id, 'customer_phone' => $rasmata->customer_phone, 'customer_name' => 'Rasmata Ilboudo',
            'service' => 'Tresses africaines (box braids)', 'requested_date' => $saturday->toDateString(), 'requested_time' => '9h', 'price' => 15000, 'status' => 'requested',
        ]);
        $this->backdate($requested, $now->subMinutes(84));

        $salam = $this->conversation($business, '22678776605', 'Salamata Ouattara', [
            [-3 * 1440, 'in', 'Un défrisage jeudi à 15h c\'est possible ?'],
            [-3 * 1440 + 1, 'ai', "Récapitulatif : défrisage + brushing, jeudi à 15h, 7 500 FCFA. J'enregistre votre demande ?"],
            [-3 * 1440 + 2, 'in', 'Oui'],
            [-3 * 1440 + 3, 'ai', 'Votre demande est enregistrée ✅ Le salon vous confirme le créneau très vite.'],
            [-3 * 1440 + 30, 'human', 'Bonne nouvelle 😊 Votre rendez-vous « Défrisage + brushing » de jeudi à 15h est confirmé. À bientôt chez Salon Belle Tresse !'],
        ]);
        $confirmed = Appointment::create([
            'business_id' => $business->id, 'conversation_id' => $salam->id, 'customer_phone' => $salam->customer_phone, 'customer_name' => 'Salamata Ouattara',
            'service' => 'Défrisage + brushing', 'requested_date' => $now->next(CarbonImmutable::THURSDAY)->toDateString(), 'requested_time' => '15h', 'price' => 7500, 'status' => 'confirmed',
        ]);
        $this->backdate($confirmed, $now->subDays(3));

        $this->conversation($business, '22660123406', 'Aminata Compaoré', [
            [-10, 'in', 'Vous faites les coiffures de mariage à domicile ?'],
            [-9, 'ai', 'Oui, la coiffure de mariage à domicile est à partir de 25 000 FCFA, uniquement sur rendez-vous. Pour quelle date ?'],
        ]);
    }

    private function owner(string $name, string $login): User
    {
        return User::forceCreate([
            'name' => $name,
            'email' => "{$login}@".self::DOMAIN,
            'password' => Hash::make(self::PASSWORD),
            'country' => 'BF',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function business(User $owner, array $attributes): Business
    {
        $business = $owner->businesses()->create($attributes + ['country' => 'BF', 'is_active' => true]);

        // Pro : commandes, rendez-vous et apprentissage, sans limite d'essai à surveiller.
        app(BillingService::class)->assignPlan($business, Plan::bySlug(Plan::PRO));

        return $business->fresh();
    }

    /**
     * Document « prêt » découpé en morceaux, avec leurs embeddings quand la clé Gemini est là.
     *
     * @param  list<string>  $chunks
     */
    private function catalog(Business $business, string $title, array $chunks): void
    {
        $document = Document::create([
            'business_id' => $business->id,
            'title' => $title,
            'file_path' => "demo/{$business->id}/".Str::slug($title).'.txt',
            'file_type' => 'txt',
            'file_size' => strlen(implode("\n\n", $chunks)),
            'status' => 'ready',
            'chunk_count' => count($chunks),
            'processed_at' => now(),
        ]);

        foreach ($chunks as $index => $content) {
            DB::table('document_chunks')->insert([
                'id' => (string) Str::uuid(),
                'document_id' => $document->id,
                'business_id' => $business->id,
                'content' => $content,
                'chunk_index' => $index,
                'embedding' => $this->embedding($content),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** Vecteur au format pgvector, ou null sans clé Gemini (le catalogue reste visible dans le dashboard). */
    private function embedding(string $content): ?string
    {
        if (! $this->embeddingsAvailable || empty(config('services.gemini.api_key'))) {
            $this->embeddingsAvailable = false;

            return null;
        }

        try {
            return '['.implode(',', app(EmbeddingService::class)->embed($content)).']';
        } catch (Throwable) {
            $this->embeddingsAvailable = false;

            return null;
        }
    }

    /**
     * Conversation et ses messages : [minutes par rapport à maintenant, in|ai|human, texte, metadata?].
     *
     * @param  list<array{0: int, 1: string, 2: string, 3?: array<string, mixed>}>  $messages
     */
    private function conversation(Business $business, string $phone, string $name, array $messages, string $inboundStatus = 'answered_by_ai'): Conversation
    {
        $now = CarbonImmutable::now();
        $conversation = Conversation::create(['business_id' => $business->id, 'customer_phone' => $phone, 'customer_name' => $name, 'is_active' => true]);

        foreach ($messages as $message) {
            [$minutes, $sender, $content] = $message;
            $at = $now->addMinutes($minutes);

            $created = $conversation->messages()->create([
                'direction' => $sender === 'in' ? 'inbound' : 'outbound',
                'sender_type' => match ($sender) { 'in' => 'customer', 'ai' => 'ai', default => 'human' },
                'content' => $content,
                'status' => match ($sender) { 'in' => $inboundStatus, 'ai' => 'sent', default => 'answered_by_human' },
                'confidence_score' => $sender === 'ai' ? 0.82 : null,
                'whatsapp_message_id' => 'wamid.DEMO'.strtoupper(Str::random(16)),
                'metadata' => $message[3] ?? ($sender === 'ai' ? ['context_used' => []] : null),
            ]);
            $this->backdate($created, $at);
        }

        $conversation->update(['last_message_at' => $now->addMinutes(end($messages)[0])]);

        return $conversation;
    }

    private function escalate(Conversation $conversation, string $question, CarbonImmutable $at): void
    {
        $escalation = Escalation::create([
            'conversation_id' => $conversation->id,
            'message_id' => $conversation->messages()->where('direction', 'inbound')->latest()->value('id'),
            'business_id' => $conversation->business_id,
            'customer_question' => $question,
            'status' => Escalation::PENDING,
        ]);
        $this->backdate($escalation, $at);
    }

    private function backdate(object $model, \DateTimeInterface $at): void
    {
        $model->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();
    }

    /**
     * Supprimer la démo précédente : comptes @demo.causeo.test et leurs businesses
     * (conversations, commandes, rendez-vous… suivent par cascade).
     */
    private function removePreviousDemo(): void
    {
        $users = User::query()->where('email', 'like', '%@'.self::DOMAIN)->get();

        foreach ($users as $user) {
            foreach ($user->businesses as $business) {
                $business->delete();
            }

            $user->delete();
        }
    }
}
