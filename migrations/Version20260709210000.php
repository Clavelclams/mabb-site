<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Chantier Dashboard Secrétaire + Organisation week-end [V2.4g 09/07/2026].
 *
 * DELTA écrit à la main (même raison que Version20260708021754 : introspection
 * Doctrine/MySQL 8.4 KO). Deux nouvelles tables + une extension :
 *   - sport_dossier_licence   : suivi licences/paiements/relances (ex-Excel secrétaire)
 *   - sport_responsable_legal : contacts parents rattachés aux joueuses
 *   - affectation_match       : + nom_libre / numero_licence / heure_rdv
 *     (saisie libre pour services civiques & externes sans compte — on étend
 *     l'entité EXISTANTE AffectationMatch, pas de table doublon).
 */
final class Version20260709210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Secrétariat : dossiers licences + responsables légaux + affectations rencontres';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sport_dossier_licence (id INT AUTO_INCREMENT NOT NULL, saison VARCHAR(9) NOT NULL, site VARCHAR(60) DEFAULT NULL, categorie VARCHAR(60) DEFAULT NULL, type_licence VARCHAR(30) DEFAULT NULL, numero_licence VARCHAR(20) DEFAULT NULL, nom_complet VARCHAR(160) NOT NULL, date_naissance DATE DEFAULT NULL, telephone VARCHAR(30) DEFAULT NULL, tarif VARCHAR(30) DEFAULT NULL, aides JSON DEFAULT NULL, paiement_statut VARCHAR(20) NOT NULL, relance_le DATE DEFAULT NULL, relance_note VARCHAR(255) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, club_id INT NOT NULL, joueur_id INT DEFAULT NULL, INDEX idx_dossier_licence_club_saison (club_id, saison), INDEX IDX_DOSSIER_LIC_JOUEUR (joueur_id), UNIQUE INDEX uniq_dossier_licence_numero_saison (club_id, saison, numero_licence), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE sport_dossier_licence ADD CONSTRAINT FK_DOSSIER_LIC_CLUB FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sport_dossier_licence ADD CONSTRAINT FK_DOSSIER_LIC_JOUEUR FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE SET NULL');

        $this->addSql('CREATE TABLE sport_responsable_legal (id INT AUTO_INCREMENT NOT NULL, nom_complet VARCHAR(160) NOT NULL, telephone VARCHAR(30) DEFAULT NULL, telephone2 VARCHAR(30) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, adresse VARCHAR(220) DEFAULT NULL, code_postal VARCHAR(10) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, joueur_id INT NOT NULL, INDEX idx_responsable_joueur (joueur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE sport_responsable_legal ADD CONSTRAINT FK_RESP_LEGAL_JOUEUR FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE affectation_match ADD nom_libre VARCHAR(120) DEFAULT NULL, ADD numero_licence VARCHAR(20) DEFAULT NULL, ADD heure_rdv VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE affectation_match DROP nom_libre, DROP numero_licence, DROP heure_rdv');
        $this->addSql('ALTER TABLE sport_responsable_legal DROP FOREIGN KEY FK_RESP_LEGAL_JOUEUR');
        $this->addSql('DROP TABLE sport_responsable_legal');
        $this->addSql('ALTER TABLE sport_dossier_licence DROP FOREIGN KEY FK_DOSSIER_LIC_CLUB');
        $this->addSql('ALTER TABLE sport_dossier_licence DROP FOREIGN KEY FK_DOSSIER_LIC_JOUEUR');
        $this->addSql('DROP TABLE sport_dossier_licence');
    }
}
