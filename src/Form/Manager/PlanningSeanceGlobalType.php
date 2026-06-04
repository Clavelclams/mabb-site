<?php

namespace App\Form\Manager;

use App\Entity\Core\Club;
use App\Entity\Sport\Equipe;
use App\Entity\Sport\PlanningSeance;
use App\Entity\Sport\Seance;
use App\Repository\Sport\EquipeRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Variante de PlanningSeanceType pour la page Planning globale.
 *
 * Ici le champ "equipe" est INCLUS (alors qu'il est imposé dans
 * PlanningSeanceType utilisé depuis la fiche équipe).
 *
 * Filtré sur les équipes actives du club courant pour rester multi-tenant.
 */
class PlanningSeanceGlobalType extends AbstractType
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
                    ->orderBy('e.categorie', 'ASC')
                    ->addOrderBy('e.nom', 'ASC'),
                'placeholder'   => 'Choisir une équipe...',
            ])
            ->add('jourSemaine', ChoiceType::class, [
                'label'   => 'Jour de la semaine',
                'choices' => array_flip(PlanningSeance::JOURS),
                'placeholder' => 'Choisir un jour...',
            ])
            ->add('heureDebut', TextType::class, [
                'label' => 'Heure de début',
                'attr'  => ['placeholder' => '18:00', 'pattern' => '[0-2][0-9]:[0-5][0-9]'],
                'help'  => 'Format HH:MM (24h). Ex: 18:00, 19:30.',
            ])
            ->add('dureeMinutes', IntegerType::class, [
                'label' => 'Durée (minutes)',
                'attr'  => ['min' => 15, 'max' => 240, 'placeholder' => '90'],
            ])
            ->add('lieu', TextType::class, [
                'label' => 'Lieu',
                'attr'  => ['maxlength' => 120, 'placeholder' => 'Ex: Gymnase Étouvie, Salle B'],
            ])
            ->add('type', ChoiceType::class, [
                'label'   => 'Type de séance',
                'choices' => array_combine(Seance::TYPES, Seance::TYPES),
            ])
            ->add('notes', TextareaType::class, [
                'label'    => 'Notes par défaut (optionnel)',
                'required' => false,
                'attr'     => ['rows' => 2, 'placeholder' => 'Ex: Apporter chasubles. Focus shoot.'],
                'help'     => 'Reprises automatiquement sur chaque séance générée.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PlanningSeance::class]);
        $resolver->setRequired('club');
        $resolver->setAllowedTypes('club', Club::class);
    }
}
