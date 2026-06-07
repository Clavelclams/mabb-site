<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bureau Manager Phase D.3 — Cotisations licenciées.
 *
 * Crée la table `cotisation_joueur` qui track le paiement de chaque joueuse
 * pour chaque saison.
 *
 * MULTI-TENANT :
 *   - Le club est récupéré via joueur (délégation ClubAwareInterface).
 *   - Pas de FK club_id directe → 1 colonne en moins, source de vérité unique.
 *
 * INDEX :
 *   - PK (id)
 *   - FK joueur_id (CASCADE)
 *   - UNIQUE (joueur_id, saison) → garantit qu'une joueuse n'a qu'UNE cotisation
 *     par saison (verrou BDD, pas seulement contrainte applicative).
 *   - (joueur_id, statut) → utile pour les requêtes "qui doit encore payer"
 *
 * STATUTS POSSIBLES :
 *   A_PAYER, PAYEE, ECHEANCIER, EXEMPTEE
 *   Validés côté entité (pas de CHECK constraint pour simplicité).
 */
final class Version20260607130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bureau D.3 — Cotisations licenciées : table cotisation_joueur';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            CREATE TABLE cotisation_joueur (
                id INT AUTO_INCREMENT NOT NULL,
                joueur_id INT NOT NULL,
                saison VARCHAR(9) NOT NULL,
                montant_attendu NUMERIC(10, 2) NOT NULL,
                montant_paye NUMERIC(10, 2) NOT NULL,
                statut VARCHAR(16) NOT NULL,
                date_paiement DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)',
                motif_exemption LONGTEXT DEFAULT NULL,
                notes LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_COTI_JOUEUR (joueur_id),
                INDEX IDX_COTI_JOUEUR_STATUT (joueur_id, statut),
                UNIQUE INDEX UNIQ_COTI_JOUEUR_SAISON (joueur_id, saison),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<SQL
            ALTER TABLE cotisation_joueur
            ADD CONSTRAINT FK_COTI_JOUEUR FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotisation_joueur DROP FOREIGN KEY FK_COTI_JOUEUR');
        $this->addSql('DROP TABLE cotisation_joueur');
    }
}
