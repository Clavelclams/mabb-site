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
            ->add('equipe', EntityType::class, [
                'label'         => 'Équipe',
                'class'         => Equipe::class,
                'choice_label'  => fn(Equipe $e) => sprintf('%s (%s)', $e->getNom(), $e->getCategorie()),
                'query_builder' => fn(EquipeRepository $r) => $r->createQueryBuilder('e')
                    ->andWhere('e.club = :club')
                    ->andWhere('e.isActive = true')
                    ->setParameter('club', $club)
                    ->orderBy('e.categorie', 'ASC'),
                'placeholder'   => 'Choisir une équipe...',
            ])
            ->add('adversaire', TextType::class, [
                'label' => 'Adversaire',
                'attr'  => ['maxlength' => 150, 'placeholder' => 'Ex: Eagles Basketball, Longueau BC'],
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
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Rencontre::class]);
        $resolver->setRequired('club');
        $resolver->setAllowedTypes('club', Club::class);
    }
}
