<?php

declare(strict_types=1);

namespace App\Form\Manager;

use App\Entity\Core\Club;
use App\Entity\Sport\ContenuSeance;
use App\Entity\Sport\ThemeSeance;
use App\Repository\Sport\ThemeSeanceRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\File;

/**
 * Formulaire de création / édition d'une fiche séance.
 *
 * UX : 2-3 clics max. Seuls `titre` et `categoriesAge` sont vraiment importants.
 * Tout le reste est optionnel mais plaisant à remplir.
 *
 * Option requise : 'club' → pour filtrer les thèmes du club.
 */
class ContenuSeanceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Club $club */
        $club = $options['club'];

        $builder
            // ─── Essentiel ─────────────────────────────────────────────────
            ->add('titre', TextType::class, [
                'label'       => 'Titre de la séance',
                'attr'        => ['placeholder' => 'Ex: Travail défense zone, Prépa finale U15…', 'maxlength' => 150],
                'help'        => 'Ce titre sera visible par les joueuses dans PIRB.',
            ])
            ->add('categoriesAge', ChoiceType::class, [
                'label'    => 'Catégories concernées',
                'choices'  => array_combine(ContenuSeance::CATEGORIES_AGE, ContenuSeance::CATEGORIES_AGE),
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'help'     => 'Coche les catégories pour lesquelles cet exercice est adapté.',
            ])

            // ─── Thèmes (multi-select groupé) ──────────────────────────────
            ->add('themes', EntityType::class, [
                'label'         => 'Thèmes',
                'class'         => ThemeSeance::class,
                'multiple'      => true,
                'expanded'      => false, // dropdown multi-select
                'required'      => false,
                'choice_label'  => fn(ThemeSeance $t) => $t->getLibelle(),
                'group_by'      => fn(ThemeSeance $t) => $t->getGroupe(),
                'query_builder' => fn(ThemeSeanceRepository $r) => $r->createQueryBuilder('t')
                    ->where('t.isSysteme = true OR t.club = :club')
                    ->setParameter('club', $club)
                    ->orderBy('t.groupe', 'ASC')
                    ->addOrderBy('t.libelle', 'ASC'),
                'attr'          => ['size' => 8, 'style' => 'height: auto;'],
                'help'          => 'Ctrl+clic (ou ⌘+clic sur Mac) pour sélectionner plusieurs thèmes.',
            ])

            // ─── Description ───────────────────────────────────────────────
            ->add('description', TextareaType::class, [
                'label'    => 'Description / plan de séance',
                'required' => false,
                'attr'     => ['rows' => 5, 'placeholder' => 'Décris les exercices, la progression, les points d\'attention…'],
                'help'     => 'Optionnel. Visible par les joueuses sauf si tu actives "Contenu privé" sur la séance.',
            ])

            // ─── Upload fichiers ────────────────────────────────────────────
            ->add('fichiers_upload', FileType::class, [
                'label'    => 'Fichiers joints (photos, PDFs)',
                'mapped'   => false,
                'multiple' => true,
                'required' => false,
                'attr'     => ['accept' => 'image/*,application/pdf', 'multiple' => true],
                'help'     => 'Max 5 fichiers · 5 Mo chacun. Photos ou PDFs (exercices, schémas terrain).',
                'constraints' => [
                    new All([
                        'constraints' => [
                            new File([
                                'maxSize'  => '5M',
                                'mimeTypes' => [
                                    'image/jpeg', 'image/png', 'image/webp', 'image/gif',
                                    'application/pdf',
                                ],
                                'mimeTypesMessage' => 'Formats acceptés : JPG, PNG, WEBP, GIF, PDF.',
                            ]),
                        ],
                    ]),
                ],
            ])

            // ─── Visibilité ────────────────────────────────────────────────
            ->add('isPublicClub', CheckboxType::class, [
                'label'    => 'Partager avec tous les coachs du club',
                'required' => false,
                'help'     => 'Si coché, les autres coachs voient et réutilisent cette fiche. Sinon, privée.',
                'attr'     => ['checked' => true],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ContenuSeance::class]);
        $resolver->setRequired('club');
        $resolver->setAllowedTypes('club', Club::class);
    }
}
