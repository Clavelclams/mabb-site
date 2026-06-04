<?php

namespace App\Form\Manager;

use App\Entity\Core\Club;
use App\Entity\Sport\Equipe;
use App\Entity\Sport\Rencontre;
use App\Repository\Sport\EquipeRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
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
