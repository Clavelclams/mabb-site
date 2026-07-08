<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module Sorties — Lot A (ADR-0011).
 *
 * DELTA écrit à la main : l'auto-génération a produit un CREATE de tout le
 * schéma (l'introspection de Doctrine ne reconnaît pas bien MySQL 8.4 → base
 * vue comme vide). On ne garde donc que les vrais changements :
 *   - 3 colonnes sur sport_evenement (estPayant, prix, autorisationRequise)
 *   - table sport_inscription_sortie + ses 3 clés étrangères
 */
final class Version20260708021754 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sorties Lot A : champs sport_evenement + table sport_inscription_sortie';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sport_evenement ADD est_payant TINYINT DEFAULT 0 NOT NULL, ADD prix NUMERIC(6, 2) DEFAULT NULL, ADD autorisation_requise TINYINT DEFAULT 0 NOT NULL');

        $this->addSql('CREATE TABLE sport_inscription_sortie (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(80) DEFAULT NULL, prenom VARCHAR(80) DEFAULT NULL, date_naissance DATE DEFAULT NULL, responsable_legal VARCHAR(120) DEFAULT NULL, telephone_contact VARCHAR(30) DEFAULT NULL, autorisation_statut VARCHAR(20) NOT NULL, autorisation_fichier VARCHAR(255) DEFAULT NULL, paiement_statut VARCHAR(20) NOT NULL, montant_paye NUMERIC(6, 2) DEFAULT NULL, moyen_paiement VARCHAR(20) DEFAULT NULL, paiement_date DATE DEFAULT NULL, presence VARCHAR(20) NOT NULL, commentaire LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, evenement_id INT NOT NULL, joueur_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, INDEX IDX_C740C0A9A9E2D76C (joueur_id), INDEX IDX_C740C0A9B03A8386 (created_by_id), INDEX idx_inscription_sortie_evenement (evenement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('ALTER TABLE sport_inscription_sortie ADD CONSTRAINT FK_C740C0A9FD02F13 FOREIGN KEY (evenement_id) REFERENCES sport_evenement (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sport_inscription_sortie ADD CONSTRAINT FK_C740C0A9A9E2D76C FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE sport_inscription_sortie ADD CONSTRAINT FK_C740C0A9B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sport_inscription_sortie DROP FOREIGN KEY FK_C740C0A9FD02F13');
        $this->addSql('ALTER TABLE sport_inscription_sortie DROP FOREIGN KEY FK_C740C0A9A9E2D76C');
        $this->addSql('ALTER TABLE sport_inscription_sortie DROP FOREIGN KEY FK_C740C0A9B03A8386');
        $this->addSql('DROP TABLE sport_inscription_sortie');
        $this->addSql('ALTER TABLE sport_evenement DROP est_payant, DROP prix, DROP autorisation_requise');
    }
}
