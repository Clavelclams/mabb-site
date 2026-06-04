<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260604065901 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE sport_joueur_badge (id INT AUTO_INCREMENT NOT NULL, code_badge VARCHAR(50) NOT NULL, saison VARCHAR(9) DEFAULT NULL, debloque_at DATETIME NOT NULL, joueur_id INT NOT NULL, INDEX IDX_B99920FAA9E2D76C (joueur_id), INDEX idx_joueur_saison (joueur_id, saison), UNIQUE INDEX uniq_joueur_badge_saison (joueur_id, code_badge, saison), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sport_mission (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(30) NOT NULL, date DATE NOT NULL, description LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, club_id INT NOT NULL, joueur_id INT NOT NULL, valide_par_id INT DEFAULT NULL, INDEX IDX_31D2BE9661190A32 (club_id), INDEX IDX_31D2BE96A9E2D76C (joueur_id), INDEX IDX_31D2BE966AF12ED9 (valide_par_id), INDEX idx_mission_joueur_date (joueur_id, date), INDEX idx_mission_club_date (club_id, date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE sport_joueur_badge ADD CONSTRAINT FK_B99920FAA9E2D76C FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sport_mission ADD CONSTRAINT FK_31D2BE9661190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sport_mission ADD CONSTRAINT FK_31D2BE96A9E2D76C FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sport_mission ADD CONSTRAINT FK_31D2BE966AF12ED9 FOREIGN KEY (valide_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE sport_joueur_badge DROP FOREIGN KEY FK_B99920FAA9E2D76C');
        $this->addSql('ALTER TABLE sport_mission DROP FOREIGN KEY FK_31D2BE9661190A32');
        $this->addSql('ALTER TABLE sport_mission DROP FOREIGN KEY FK_31D2BE96A9E2D76C');
        $this->addSql('ALTER TABLE sport_mission DROP FOREIGN KEY FK_31D2BE966AF12ED9');
        $this->addSql('DROP TABLE sport_joueur_badge');
        $this->addSql('DROP TABLE sport_mission');
    }
}
