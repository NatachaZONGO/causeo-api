<?php

namespace Database\Seeders;

use App\Models\BusinessTemplate;
use Illuminate\Database\Seeder;

class BusinessTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $templates = [
            [
                'slug' => 'restaurant',
                'name' => 'Restaurant / Traiteur',
                'icon' => '🍽️',
                'description' => 'Restaurant, traiteur ou service de repas',
                'type' => 'restaurant',
                'default_greeting' => 'Bienvenue chez {nom} ! 😊 Que puis-je faire pour vous aujourd\'hui ?',
                'default_ai_instructions' => 'Tu es l\'assistant de {nom}, un restaurant. Aide les clients avec le menu, les prix, les réservations et les commandes. Sois chaleureux et donne envie de commander. Si on te demande de réserver, note le nombre de personnes, la date et l\'heure souhaitées.',
                'sample_faq' => [
                    ['question' => 'Quels sont vos horaires ?', 'answer' => 'Nous sommes ouverts du lundi au samedi de 11h à 22h.'],
                    ['question' => 'Faites-vous la livraison ?', 'answer' => 'Oui, nous livrons dans un rayon de 5 km. Commande minimum 2000 FCFA.'],
                    ['question' => 'Peut-on réserver une table ?', 'answer' => 'Bien sûr ! Précisez le nombre de personnes, la date et l\'heure souhaitées.'],
                ],
                'sort_order' => 1,
            ],
            [
                'slug' => 'boutique',
                'name' => 'Boutique / Mode',
                'icon' => '👗',
                'description' => 'Boutique de vêtements, mode et accessoires',
                'type' => 'boutique',
                'default_greeting' => 'Bienvenue chez {nom} ! 👋 N\'hésitez pas à nous demander les tailles, couleurs et prix de nos articles.',
                'default_ai_instructions' => 'Tu es l\'assistant de {nom}, une boutique de vêtements/accessoires. Aide les clients avec les tailles disponibles, les prix, les couleurs et les nouveautés. Si un article intéresse le client, propose-lui de le réserver.',
                'sample_faq' => [
                    ['question' => 'Quelles tailles avez-vous ?', 'answer' => 'Nous avons du S au XXL pour la plupart de nos articles.'],
                    ['question' => 'Faites-vous des retouches ?', 'answer' => 'Oui, nous proposons un service de retouche sur place.'],
                    ['question' => 'Avez-vous un service de livraison ?', 'answer' => 'Oui, nous livrons à domicile. Contactez-nous pour les détails.'],
                ],
                'sort_order' => 2,
            ],
            [
                'slug' => 'photographe',
                'name' => 'Photographe / Studio',
                'icon' => '📸',
                'description' => 'Photographe ou studio photo',
                'type' => 'photographe',
                'default_greeting' => 'Bienvenue chez {nom} ! 📸 Comment puis-je vous aider pour votre projet photo ?',
                'default_ai_instructions' => 'Tu es l\'assistant de {nom}, un studio photo/photographe. Aide les clients avec les forfaits, les tarifs, la prise de rendez-vous et le type de shooting (mariage, portrait, événement, produit). Sois enthousiaste sur les projets des clients.',
                'sample_faq' => [
                    ['question' => 'Quels types de shooting proposez-vous ?', 'answer' => 'Nous faisons des shootings portrait, mariage, événements, mode et produits.'],
                    ['question' => 'Combien coûte un shooting ?', 'answer' => 'Nos tarifs varient selon le type de shooting. Contactez-nous pour un devis personnalisé.'],
                    ['question' => 'Comment réserver une séance ?', 'answer' => 'Choisissez une date et un créneau, nous confirmerons la disponibilité.'],
                ],
                'sort_order' => 3,
            ],
            [
                'slug' => 'salon_beaute',
                'name' => 'Salon de coiffure / Beauté',
                'icon' => '💇',
                'description' => 'Salon de coiffure, esthétique et beauté',
                'type' => 'salon_beaute',
                'default_greeting' => 'Bienvenue chez {nom} ! 💇‍♀️ Que souhaitez-vous comme prestation aujourd\'hui ?',
                'default_ai_instructions' => 'Tu es l\'assistant de {nom}, un salon de coiffure/beauté. Aide les clients avec les prestations disponibles, les tarifs, et la prise de rendez-vous. Sois attentionné et conseille les clients sur les soins adaptés.',
                'sample_faq' => [
                    ['question' => 'Quelles prestations proposez-vous ?', 'answer' => 'Coiffure, tresses, tissage, soins capillaires, maquillage et manucure.'],
                    ['question' => 'Prenez-vous sur rendez-vous ?', 'answer' => 'Oui, sur rendez-vous ou sans rendez-vous selon la disponibilité.'],
                    ['question' => 'Quels sont vos tarifs ?', 'answer' => 'Nos tarifs varient selon la prestation. N\'hésitez pas à nous demander.'],
                ],
                'sort_order' => 4,
            ],
            [
                'slug' => 'cabinet_medical',
                'name' => 'Cabinet médical / Clinique',
                'icon' => '🏥',
                'description' => 'Cabinet médical, clinique ou centre de santé',
                'type' => 'cabinet_medical',
                'default_greeting' => 'Bienvenue au cabinet {nom}. Comment puis-je vous aider ?',
                'default_ai_instructions' => 'Tu es l\'assistant du cabinet {nom}. Aide les patients avec les horaires de consultation, la prise de rendez-vous et les informations générales. IMPORTANT : ne donne JAMAIS de diagnostic ou de conseil médical. Oriente toujours vers une consultation avec le médecin.',
                'sample_faq' => [
                    ['question' => 'Quels sont vos horaires de consultation ?', 'answer' => 'Consultations du lundi au vendredi de 8h à 17h, samedi de 8h à 12h.'],
                    ['question' => 'Comment prendre rendez-vous ?', 'answer' => 'Appelez-nous ou envoyez un message avec la date et l\'heure souhaitées.'],
                    ['question' => 'Quelles spécialités proposez-vous ?', 'answer' => 'Médecine générale, pédiatrie et consultations spécialisées.'],
                ],
                'sort_order' => 5,
            ],
            [
                'slug' => 'agence_services',
                'name' => 'Agence / Services',
                'icon' => '🏢',
                'description' => 'Agence de services professionnels',
                'type' => 'agence_services',
                'default_greeting' => 'Bienvenue chez {nom} ! Comment pouvons-nous vous accompagner ?',
                'default_ai_instructions' => 'Tu es l\'assistant de {nom}, une agence de services professionnels. Aide les clients avec les services proposés, les tarifs et la prise de contact. Sois professionnel et rassurant.',
                'sample_faq' => [
                    ['question' => 'Quels services proposez-vous ?', 'answer' => 'Consultez notre catalogue de services ou décrivez votre besoin, nous vous orienterons.'],
                    ['question' => 'Comment obtenir un devis ?', 'answer' => 'Décrivez votre projet et nous vous enverrons un devis sous 24h.'],
                    ['question' => 'Quels sont vos délais ?', 'answer' => 'Les délais dépendent du projet. Nous vous informerons dès la validation du devis.'],
                ],
                'sort_order' => 6,
            ],
            [
                'slug' => 'nettoyage',
                'name' => 'Nettoyage / Entretien',
                'icon' => '🧹',
                'description' => 'Entreprise de nettoyage et d\'entretien',
                'type' => 'nettoyage',
                'default_greeting' => 'Bienvenue chez {nom} ! 🧹 Nous sommes là pour rendre vos espaces impeccables.',
                'default_ai_instructions' => 'Tu es l\'assistant de {nom}, une entreprise de nettoyage/entretien. Aide les clients avec les services disponibles (nettoyage de bureaux, domicile, fin de chantier), les tarifs et la planification des interventions.',
                'sample_faq' => [
                    ['question' => 'Quels types de nettoyage faites-vous ?', 'answer' => 'Nettoyage de bureaux, domiciles, fin de chantier, et espaces commerciaux.'],
                    ['question' => 'Intervenez-vous le week-end ?', 'answer' => 'Oui, nous intervenons 7j/7 selon vos besoins.'],
                    ['question' => 'Comment obtenir un devis ?', 'answer' => 'Décrivez la surface et le type de nettoyage souhaité, nous vous ferons un devis gratuit.'],
                ],
                'sort_order' => 7,
            ],
            [
                'slug' => 'communication',
                'name' => 'Communication / Marketing',
                'icon' => '📱',
                'description' => 'Agence de communication et marketing digital',
                'type' => 'communication',
                'default_greeting' => 'Bienvenue chez {nom} ! 🚀 Prêts à booster votre visibilité ?',
                'default_ai_instructions' => 'Tu es l\'assistant de {nom}, une agence de communication/marketing. Aide les clients avec les services (community management, design, stratégie digitale, publicité), les tarifs et la prise de rendez-vous.',
                'sample_faq' => [
                    ['question' => 'Quels services proposez-vous ?', 'answer' => 'Community management, design graphique, stratégie digitale, publicité en ligne et création de contenu.'],
                    ['question' => 'Combien coûte le community management ?', 'answer' => 'Nos forfaits démarrent à partir de 50 000 FCFA/mois. Contactez-nous pour un devis adapté.'],
                    ['question' => 'Travaillez-vous avec les petites entreprises ?', 'answer' => 'Absolument ! Nous avons des offres adaptées à chaque budget.'],
                ],
                'sort_order' => 8,
            ],
            [
                'slug' => 'formation',
                'name' => 'Formation / Éducation',
                'icon' => '🎓',
                'description' => 'Centre de formation ou établissement éducatif',
                'type' => 'formation',
                'default_greeting' => 'Bienvenue chez {nom} ! 🎓 Découvrez nos formations.',
                'default_ai_instructions' => 'Tu es l\'assistant de {nom}, un centre de formation. Aide les clients avec les formations disponibles, les dates, les tarifs et les modalités d\'inscription.',
                'sample_faq' => [
                    ['question' => 'Quelles formations proposez-vous ?', 'answer' => 'Consultez notre catalogue ou décrivez le domaine qui vous intéresse.'],
                    ['question' => 'Les formations sont-elles certifiantes ?', 'answer' => 'Oui, la plupart de nos formations délivrent une attestation ou un certificat.'],
                    ['question' => 'Peut-on suivre les formations en ligne ?', 'answer' => 'Certaines formations sont disponibles en présentiel et en ligne.'],
                ],
                'sort_order' => 9,
            ],
            [
                'slug' => 'autre',
                'name' => 'Autre activité',
                'icon' => '➕',
                'description' => 'Autre type d\'activité',
                'type' => 'other',
                'default_greeting' => 'Bienvenue chez {nom} ! Comment puis-je vous aider ?',
                'default_ai_instructions' => 'Tu es l\'assistant de {nom}. Réponds aux questions des clients de manière professionnelle et chaleureuse.',
                'sample_faq' => [],
                'sort_order' => 10,
            ],
        ];

        foreach ($templates as $template) {
            BusinessTemplate::updateOrCreate(
                ['slug' => $template['slug']],
                $template,
            );
        }
    }
}
