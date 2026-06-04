<?php

namespace App\Form\Manager;

use App\Entity\Core\Club;
use App\Entity\Sport\Equipe;
use App\Entity\Sport\Seance;
use App\Repository\Sport\EquipeRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire d'une séance d'entraînement.
 *
 * Option requise : 'club' => Club → filtre la liste des équipes proposées
 * pour ne montrer que les équipes du club actif (protection IDOR).
 */
class SeanceType extends AbstractType
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
            ->add('date', DateTimeType::class, [
                'label'  => 'Date et heure',
                'widget' => 'single_text',
                'help'   => 'Date et heure de début de la séance.',
            ])
            ->add('lieu', TextType::class, [
                'label' => 'Lieu',
                'attr'  => ['maxlength' => 120, 'placeholder' => 'Ex: Gymnase Étouvie, Salle B'],
            ])
            ->add('dureeMinutes', IntegerType::class, [
                'label'    => 'Durée (minutes)',
                'required' => false,
                'attr'     => ['min' => 15, 'max' => 240, 'placeholder' => '90'],
                'help'     => 'Durée prévue de la séance.',
            ])
            ->add('type', ChoiceType::class, [
                'label'   => 'Type de séance',
                'choices' => array_combine(Seance::TYPES, Seance::TYPES),
            ])
            ->add('notes', TextareaType::class, [
                'label'    => 'Notes (objectif, focus, matériel)',
                'required' => false,
                'attr'     => ['rows' => 3, 'placeholder' => 'Ex: Focus sur les tirs à 3 points. Apporter chasubles.'],
                'help'     => 'Visible par toutes les joueuses convoquées.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Seance::class]);
        $resolver->setRequired('club');
        $resolver->setAllowedTypes('club', Club::class);
    }
}
