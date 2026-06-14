<?php

namespace App\Form\Manager;

use App\Entity\Core\Club;
use App\Entity\Sport\Equipe;
use App\Entity\Sport\Rencontre;
use App\Repository\Sport\EquipeRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire d'une rencontre (match).
 *
 * Pas de champ "statut" : il est géré exclusivement via l'action statut
 * du controller (workflow validé/verrouillé), pas modifiable librement
 * dans le formulaire.
 */
class RencontreType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Club $club */
        $club = $options['club'];

        $builder
            // [B31 12/06/2026] Type de rencontre — détermine UI et règles
            ->add('typeRencontre', ChoiceType::class, [
                'label'    => 'Type de rencontre',
                'required' => true,
                'choices'  => [
                    'Officiel (championnat/coupe)'        => Rencontre::TYPE_OFFICIEL,
                    'Amical (multi-catégorie possible)'   => Rencontre::TYPE_AMICAL,
                    'Entraînement interne (avec éphémères)' => Rencontre::TYPE_ENTRAINEMENT_INTERNE,
                ],
                'help'     => 'Officiel = championnat FFBB. Amical = inter-clubs hors compet. Entraînement = sparring avec joueuses éphémères possibles.',
            ])
            ->add('equipe', EntityType::class, [
                'label'         => 'Équipe',
                'class'         => Equipe::class,
                'choice_label'  => fn(Equipe $e) => sprintf('%s (%s)', $e->getNom(), $e->getCategorie()),
                'query_builder' => fn(EquipeRepository $r) => $r->createQueryBuilder('e')
                    ->andWhere('e.club = :club')
                    ->andWhere('e.isActive = true')
                    ->setParameter('club', $club)
                    ->orderBy('e.categorie', 'ASC'),
                'placeholder'   => 'Choisir une équipe principale...',
                'help'          => 'Si amical/entraînement, c\'est l\'équipe "hôte". D\'autres joueuses peuvent être ajoutées en plus.',
            ])
            ->add('adversaire', TextType::class, [
                'label'    => 'Adversaire',
                // [B31 fix 15/06/2026] Pour entraînement interne, l'adversaire est auto-rempli
                // "Entraînement interne" par le PRE_SUBMIT listener si l'user laisse vide.
                // required=false pour ne pas bloquer l'UI, mais l'entité garde Assert\NotBlank
                // (le listener garantit qu'on a toujours une valeur avant validate).
                'required' => false,
                'attr'     => ['maxlength' => 150, 'placeholder' => 'Ex: Eagles Basketball, Longueau BC (laisser vide si entraînement interne)'],
            ])
            ->add('date', DateTimeType::class, [
                'label'  => 'Date et heure du match',
                'widget' => 'single_text',
            ])
            ->add('lieu', TextType::class, [
                'label'    => 'Lieu',
                'required' => false,
                'attr'     => ['maxlength' => 120, 'placeholder' => 'Ex: Gymnase Étouvie, Salle adversaire...'],
            ])
            ->add('domicile', CheckboxType::class, [
                'label'    => 'À domicile',
                'required' => false,
                'help'     => 'Décocher si le match se joue à l\'extérieur (chez l\'adversaire).',
            ])
            ->add('scoreEquipe', IntegerType::class, [
                'label'    => 'Score de notre équipe',
                'required' => false,
                'attr'     => ['min' => 0, 'max' => 300],
                'help'     => 'À remplir après le match.',
            ])
            ->add('scoreAdverse', IntegerType::class, [
                'label'    => 'Score adverse',
                'required' => false,
                'attr'     => ['min' => 0, 'max' => 300],
                'help'     => 'À remplir après le match.',
            ])
            ->add('arbitreExterneDesigne', CheckboxType::class, [
                'label'    => 'Arbitre officiel FFBB désigné',
                'required' => false,
                'help'     => 'Cocher si la FFBB a désigné un arbitre officiel. Sinon, un bénévole du club pourra s\'inscrire pour arbitrer.',
            ])
            ->add('arbitreExterneNom', TextType::class, [
                'label'    => 'Nom de l\'arbitre FFBB (si désigné)',
                'required' => false,
                'attr'     => ['maxlength' => 120, 'placeholder' => 'Optionnel — info de convocation FFBB'],
            ])
            // === Format match (Stats Live V2.1a) ===
            // 2 champs : nombre de périodes + durée d'une période.
            // Combinés ils donnent le format (4×10, 4×8, 2×20…).
            ->add('nbPeriodes', ChoiceType::class, [
                'label'    => 'Nombre de périodes',
                'required' => true,
                'choices'  => [
                    '4 quart-temps' => 4,
                    '2 mi-temps'    => 2,
                ],
                'help'     => 'Détermine la structure du chrono en Stats Live.',
            ])
            ->add('dureePeriodeMinutes', IntegerType::class, [
                'label'    => 'Durée d\'une période (minutes)',
                'required' => true,
                'attr'     => ['min' => 1, 'max' => 30],
                'help'     => 'Standard FFBB : 10 min (seniors/U18/U17), 8 min (U15/U13/U11), 6 min (U9/U7).',
            ])
            ->add('notes', TextareaType::class, [
                'label'    => 'Notes',
                'required' => false,
                'attr'     => ['rows' => 3, 'placeholder' => 'Observations, points clés du match, commentaires arbitrage...'],
            ]);

        // [B31] Si amical/entraînement : champ pour joueuses éphémères (texte libre, parsé en JSON côté controller)
        // On l'affiche aussi pour officiel mais c'est rarement utilisé.
        $builder->add('joueursEphemeresTexte', TextareaType::class, [
            'label'    => 'Joueuses éphémères (1 par ligne)',
            'required' => false,
            'mapped'   => false,
            'attr'     => [
                'rows' => 4,
                'placeholder' => "Format : Prénom Nom (rôle optionnel)\nEx :\nFatou MAMAN (sparring)\nClément DIRIGEANT\nMarie BENEVOLE (formation OTM)",
            ],
            'help'     => 'Pour matchs amicaux / entraînements internes. Personnes sans licence FFBB qui jouent ou participent (maman, dirigeant, sparring partner). Apparaissent sur la feuille de match interne et utilisables en Stats Live.',
        ]);

        // [B31 fix 15/06/2026] PRE_SUBMIT : auto-remplit adversaire pour entraînement interne.
        //
        // Pourquoi PRE_SUBMIT et pas POST_SUBMIT :
        //   PRE_SUBMIT s'exécute AVANT le validate Symfony. C'est ce qu'il faut car
        //   l'entité Rencontre a #[Assert\NotBlank] sur adversaire — si on attend
        //   POST_SUBMIT, la validation a déjà échoué.
        //
        // Pourquoi pas dans le controller :
        //   Mettre la logique dans le form la rend réutilisable partout où le form
        //   est instancié (création + édition). DRY.
        //
        // Pourquoi pas en JS côté template :
        //   JS peut être contourné (curl, désactivation), la sécurité métier doit être
        //   server-side. Pattern défensif.
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $data = $event->getData();
            if (!is_array($data)) {
                return;
            }
            $type = $data['typeRencontre'] ?? null;
            $adversaire = trim($data['adversaire'] ?? '');

            // Pour entraînement interne, on s'en fout du nom adversaire — auto-rempli
            if ($type === Rencontre::TYPE_ENTRAINEMENT_INTERNE && $adversaire === '') {
                $data['adversaire'] = 'Entraînement interne';
                $event->setData($data);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Rencontre::class]);
        $resolver->setRequired('club');
        $resolver->setAllowedTypes('club', Club::class);
    }
}
