<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * V2.4h — Classeur secrétariat par secteur + pré-inscriptions publiques.
 * Delta à la main (introspection Doctrine/MySQL 8.4 KO). Deux tables :
 *   - sport_secteur         : référentiel des sites (+ responsable de secteur)
 *   - sport_pre_inscription : demandes de licence déposées via la vitrine
 */
final class Version20260709233000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Secrétariat V2 : secteurs (responsables) + pré-inscriptions publiques';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sport_secteur (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(60) NOT NULL, responsable_nom VARCHAR(120) DEFAULT NULL, responsable_telephone VARCHAR(30) DEFAULT NULL, ordre INT DEFAULT 0 NOT NULL, club_id INT NOT NULL, UNIQUE INDEX uniq_secteur_club_nom (club_id, nom), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE sport_secteur ADD CONSTRAINT FK_SECTEUR_CLUB FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE sport_pre_inscription (id INT AUTO_INCREMENT NOT NULL, saison VARCHAR(9) NOT NULL, statut VARCHAR(20) NOT NULL, nom VARCHAR(80) NOT NULL, prenom VARCHAR(80) NOT NULL, date_naissance DATE DEFAULT NULL, categorie VARCHAR(60) DEFAULT NULL, telephone_joueuse VARCHAR(30) DEFAULT NULL, secteur_souhaite VARCHAR(60) DEFAULT NULL, parent_nom VARCHAR(160) DEFAULT NULL, parent_telephone VARCHAR(30) DEFAULT NULL, parent_email VARCHAR(180) DEFAULT NULL, parent_adresse VARCHAR(220) DEFAULT NULL, parent_code_postal VARCHAR(10) DEFAULT NULL, consentement_at DATETIME NOT NULL, created_at DATETIME NOT NULL, traite_le DATETIME DEFAULT NULL, note_traitement VARCHAR(255) DEFAULT NULL, club_id INT NOT NULL, traite_par_id INT DEFAULT NULL, INDEX idx_pre_inscription_club_statut (club_id, statut), INDEX IDX_PREINSC_TRAITE_PAR (traite_par_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE sport_pre_inscription ADD CONSTRAINT FK_PREINSC_CLUB FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sport_pre_inscription ADD CONSTRAINT FK_PREINSC_TRAITE_PAR FOREIGN KEY (traite_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sport_pre_inscription DROP FOREIGN KEY FK_PREINSC_CLUB');
        $this->addSql('ALTER TABLE sport_pre_inscription DROP FOREIGN KEY FK_PREINSC_TRAITE_PAR');
        $this->addSql('DROP TABLE sport_pre_inscription');
        $this->addSql('ALTER TABLE sport_secteur DROP FOREIGN KEY FK_SECTEUR_CLUB');
        $this->addSql('DROP TABLE sport_secteur');
    }
}
