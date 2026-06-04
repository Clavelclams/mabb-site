<?php

namespace App\Form\Manager;

use App\Entity\Sport\Equipe;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire de création / modification d'une Equipe.
 *
 * IMPORTANT : le champ "club" N'EST PAS dans le formulaire.
 * Le club est forcé côté contrôleur (EquipeController::new) pour empêcher
 * un utilisateur malveillant de modifier le champ via DevTools et créer une
 * équipe dans un autre club. C'est une règle de défense en profondeur :
 * tout ce qui n'a pas besoin d'être éditable côté front ne doit PAS être
 * dans le formulaire.
 */
class EquipeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'Nom de l\'équipe',
                'attr'  => [
                    'placeholder' => 'Ex: U13 Féminines, Séniors A...',
                    'maxlength'   => 100,
                ],
            ])
            ->add('categorie', ChoiceType::class, [
                'label'   => 'Catégorie',
                'choices' => array_combine(Equipe::CATEGORIES, Equipe::CATEGORIES),
                'placeholder' => 'Choisir une catégorie...',
            ])
            ->add('saison', TextType::class, [
                'label' => 'Saison',
                'attr'  => [
                    'placeholder' => 'Ex: 2025-2026',
                    'pattern'     => '\d{4}-\d{4}',
                ],
                'help'  => 'Format : YYYY-YYYY (ex: 2025-2026)',
            ])
            ->add('niveau', TextType::class, [
                'label'    => 'Niveau de jeu',
                'required' => false,
                'attr'     => [
                    'placeholder' => 'Ex: Départemental, Régional, Pré-National...',
                    'maxlength'   => 80,
                ],
                'help' => 'Optionnel — le niveau auquel évolue l\'équipe cette saison.',
            ])
            ->add('isActive', CheckboxType::class, [
                'label'    => 'Équipe active',
                'required' => false,
                'help'     => 'Décocher pour archiver l\'équipe en fin de saison.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Equipe::class,
        ]);
    }
}
