<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260604085846 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE sport_evenement (id INT AUTO_INCREMENT NOT NULL, titre VARCHAR(150) NOT NULL, description LONGTEXT DEFAULT NULL, type VARCHAR(30) NOT NULL, statut VARCHAR(20) NOT NULL, date DATETIME NOT NULL, date_fin DATETIME DEFAULT NULL, lieu VARCHAR(150) DEFAULT NULL, ouvert_a VARCHAR(20) NOT NULL, inscriptions_max SMALLINT DEFAULT NULL, created_at DATETIME NOT NULL, club_id INT NOT NULL, createur_id INT DEFAULT NULL, INDEX IDX_20A4907361190A32 (club_id), INDEX IDX_20A4907373A201E5 (createur_id), INDEX idx_evenement_club_date (club_id, date), INDEX idx_evenement_statut (statut), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sport_evenement_participation (id INT AUTO_INCREMENT NOT NULL, statut VARCHAR(20) NOT NULL, commentaire LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, evenement_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_7B0AB782FD02F13 (evenement_id), INDEX IDX_7B0AB782A76ED395 (user_id), UNIQUE INDEX uniq_evenement_user (evenement_id, user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sport_rencontre_role (id INT AUTO_INCREMENT NOT NULL, role VARCHAR(20) NOT NULL, present TINYINT NOT NULL, created_at DATETIME NOT NULL, rencontre_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_4755F586CFC0818 (rencontre_id), INDEX IDX_4755F58A76ED395 (user_id), UNIQUE INDEX uniq_rencontre_role (rencontre_id, role), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE sport_evenement ADD CONSTRAINT FK_20A4907361190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sport_evenement ADD CONSTRAINT FK_20A4907373A201E5 FOREIGN KEY (createur_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE sport_evenement_participation ADD CONSTRAINT FK_7B0AB782FD02F13 FOREIGN KEY (evenement_id) REFERENCES sport_evenement (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sport_evenement_participation ADD CONSTRAINT FK_7B0AB782A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sport_rencontre_role ADD CONSTRAINT FK_4755F586CFC0818 FOREIGN KEY (rencontre_id) REFERENCES rencontre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sport_rencontre_role ADD CONSTRAINT FK_4755F58A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE rencontre ADD arbitre_externe_designe TINYINT NOT NULL, ADD arbitre_externe_nom VARCHAR(120) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE sport_evenement DROP FOREIGN KEY FK_20A4907361190A32');
        $this->addSql('ALTER TABLE sport_evenement DROP FOREIGN KEY FK_20A4907373A201E5');
        $this->addSql('ALTER TABLE sport_evenement_participation DROP FOREIGN KEY FK_7B0AB782FD02F13');
        $this->addSql('ALTER TABLE sport_evenement_participation DROP FOREIGN KEY FK_7B0AB782A76ED395');
        $this->addSql('ALTER TABLE sport_rencontre_role DROP FOREIGN KEY FK_4755F586CFC0818');
        $this->addSql('ALTER TABLE sport_rencontre_role DROP FOREIGN KEY FK_4755F58A76ED395');
        $this->addSql('DROP TABLE sport_evenement');
        $this->addSql('DROP TABLE sport_evenement_participation');
        $this->addSql('DROP TABLE sport_rencontre_role');
        $this->addSql('ALTER TABLE rencontre DROP arbitre_externe_designe, DROP arbitre_externe_nom');
    }
}
