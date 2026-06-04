<?php

namespace App\Form\Manager;

use App\Entity\Core\Club;
use App\Entity\Sport\Equipe;
use App\Entity\Sport\Joueur;
use App\Repository\Sport\EquipeRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire de création / modification d'une Joueuse.
 *
 * Option REQUISE : 'club' => Club $clubActif
 *   → Sert à filtrer la liste des équipes proposées pour ne montrer
 *     QUE les équipes du club actif.
 *
 * Pourquoi cette option est obligatoire :
 *   Si on laissait Symfony lister TOUTES les équipes (autre clubs inclus),
 *   un attaquant pourrait inspecter le HTML, voir les equipe_id d'autres clubs
 *   et forger un POST qui affecte la joueuse à une équipe ennemie. Faille IDOR
 *   (Insecure Direct Object Reference) — top 10 OWASP.
 *
 * Le champ "club" N'EST PAS dans le formulaire (forcé côté contrôleur).
 */
class JoueurType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Club $club */
        $club = $options['club'];

        $builder
            ->add('prenom', TextType::class, [
                'label' => 'Prénom',
                'attr'  => ['maxlength' => 80, 'autofocus' => true],
            ])
            ->add('nom', TextType::class, [
                'label' => 'Nom',
                'attr'  => ['maxlength' => 80],
            ])
            ->add('dateNaissance', DateType::class, [
                'label'    => 'Date de naissance',
                'required' => false,
                'widget'   => 'single_text',  // input type="date" HTML5
                'help'     => 'Utilisée pour calculer la catégorie d\'âge.',
            ])
            ->add('poste', ChoiceType::class, [
                'label'    => 'Poste',
                'required' => false,
                'choices'  => array_combine(Joueur::POSTES, Joueur::POSTES),
                'placeholder' => 'Aucun poste défini',
            ])
            ->add('numeroMaillot', IntegerType::class, [
                'label'    => 'Numéro de maillot',
                'required' => false,
                'attr'     => ['min' => 0, 'max' => 99],
                'help'     => 'Entre 0 et 99.',
            ])
            ->add('licence', TextType::class, [
                'label'    => 'Numéro de licence FFBB',
                'required' => false,
                'attr'     => ['maxlength' => 20, 'placeholder' => 'Ex: VT123456'],
                'help'     => 'Optionnel — peut être saisi plus tard.',
            ])

            // ============================================================
            // CHAMP CRITIQUE : sélecteur d'équipe RESTREINT au club actif.
            // C'est ici que la sécurité multi-tenant se joue côté formulaire.
            // ============================================================
            ->add('equipe', EntityType::class, [
                'label'         => 'Équipe',
                'class'         => Equipe::class,
                'required'      => false,
                'placeholder'   => '— Non affectée —',
                'choice_label'  => fn(Equipe $e) => sprintf('%s (%s)', $e->getNom(), $e->getCategorie()),
                'query_builder' => fn(EquipeRepository $r) => $r->createQueryBuilder('e')
                    ->andWhere('e.club = :club')
                    ->andWhere('e.isActive = true')
                    ->setParameter('club', $club)
                    ->orderBy('e.categorie', 'ASC')
                    ->addOrderBy('e.nom', 'ASC'),
                'help' => 'Seules les équipes ACTIVES de ton club sont proposées.',
            ])

            ->add('notes', TextareaType::class, [
                'label'    => 'Notes (medical, contact, observations)',
                'required' => false,
                'attr'     => ['rows' => 4, 'placeholder' => "Allergies, infos medicales, contact urgence, observations coach...\n\nCONFIDENTIEL : visible uniquement par le staff du club."],
                'help'     => 'Texte libre. Visible uniquement par le staff (coachs, dirigeants). Peut contenir des donnees sensibles — ne PAS y mettre de donnees de tiers (parents, autres joueuses).',
            ])

            ->add('isActive', CheckboxType::class, [
                'label'    => 'Joueuse active',
                'required' => false,
                'help'     => 'Décocher pour archiver (joueuse partie du club, blessure longue...).',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Joueur::class,
        ]);
        // Option obligatoire : le club actif (utilisé pour filtrer la liste des équipes)
        $resolver->setRequired('club');
        $resolver->setAllowedTypes('club', Club::class);
    }
}
