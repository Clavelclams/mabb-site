<?php

namespace App\Service;

use App\Entity\Core\Club;
use App\Entity\Core\User;
use App\Entity\Sport\Joueur;
use App\Repository\Sport\JoueurRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * JoueurMatcherService — fait le pont entre un User qui s'inscrit et un
 * Joueur déjà existant en BDD (créé par le coach).
 *
 * Stratégie de match en cascade (du plus fiable au moins fiable) :
 *   1. Numéro de licence FFBB (BC123456, VT123456…) — clé unique nationale
 *   2. Email (si saisi par le coach lors de la création du Joueur)
 *   3. Téléphone normalisé (sans espaces, +33 → 0)
 *
 * Si un match est trouvé :
 *   - Joueur.user = User (lien persistant)
 *   - Le UserClubRole créé pour ce User est mis directement en status=active
 *     avec role=JOUEUR (l'admin n'a pas besoin de valider)
 *   - La gamification accumulée (XP, badges, missions) devient visible
 *
 * Si pas de match :
 *   - Workflow normal pending — l'admin valide via /demandes
 *
 * Pourquoi ce service séparé : Single Responsibility Principle. La logique
 * de matching est complexe et susceptible d'évoluer (V2 : fuzzy match nom+ddn,
 * match parent-enfant, etc.). Isolée ici, elle peut être testée unitairement
 * sans dépendre des controllers signup.
 */
class JoueurMatcherService
{
    public function __construct(
        private readonly JoueurRepository $joueurRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Cherche un Joueur en BDD qui corresponde aux infos fournies par le User
     * lors de son inscription. Retourne le Joueur trouvé ou null.
     *
     * @param string|null $licence  numéro de licence FFBB saisi (optionnel)
     * @param string|null $email    email saisi
     * @param string|null $telephone téléphone saisi (sera normalisé)
     */
    public function chercherJoueurCorrespondant(
        Club $club,
        ?string $licence,
        ?string $email,
        ?string $telephone,
    ): ?Joueur {
        // 1. Match par licence — la plus fiable (unique nationale)
        if ($licence !== null && trim($licence) !== '') {
            $j = $this->joueurRepository->findOneBy([
                'club'    => $club,
                'licence' => trim($licence),
            ]);
            if ($j && $j->getUser() === null) {
                return $j;  // Trouvé, et pas encore lié à un User
            }
        }

        // 2. Match par email — fiable si le coach l'a saisi
        if ($email !== null && trim($email) !== '') {
            $emailNorm = strtolower(trim($email));
            $j = $this->joueurRepository->findOneBy([
                'club'  => $club,
                'email' => $emailNorm,
            ]);
            if ($j && $j->getUser() === null) {
                return $j;
            }
        }

        // 3. Match par téléphone — fallback pour les sans-email
        if ($telephone !== null && trim($telephone) !== '') {
            $telNorm = $this->normaliserTelephone($telephone);
            // Parcourt tous les joueurs du club avec un tél et compare la version normalisée
            // (pas indexable directement en SQL car le format saisi peut varier)
            $candidats = $this->joueurRepository->createQueryBuilder('j')
                ->andWhere('j.club = :club')->setParameter('club', $club)
                ->andWhere('j.telephone IS NOT NULL')
                ->andWhere('j.user IS NULL')  // pas déjà lié à un User
                ->getQuery()->getResult();
            foreach ($candidats as $j) {
                if ($j->getTelephoneNormalise() === $telNorm) {
                    return $j;
                }
            }
        }

        return null;
    }

    /**
     * Lie un User à un Joueur trouvé. Persiste sans flush (l'appelant flush).
     */
    public function lierUserAuJoueur(User $user, Joueur $joueur): void
    {
        $joueur->setUser($user);
        $this->em->persist($joueur);
    }

    private function normaliserTelephone(string $tel): string
    {
        $clean = preg_replace('/[\s\.\-\(\)]/', '', $tel);
        if (str_starts_with($clean, '+33')) {
            $clean = '0' . substr($clean, 3);
        }
        return $clean;
    }
}
