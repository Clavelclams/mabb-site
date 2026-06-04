<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260604002331 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE planning_seance (id INT AUTO_INCREMENT NOT NULL, jour_semaine SMALLINT NOT NULL, heure_debut VARCHAR(5) NOT NULL, duree_minutes SMALLINT NOT NULL, lieu VARCHAR(120) NOT NULL, type VARCHAR(30) NOT NULL, notes LONGTEXT DEFAULT NULL, is_active TINYINT NOT NULL, created_at DATETIME NOT NULL, club_id INT NOT NULL, equipe_id INT NOT NULL, INDEX IDX_BF5CDF6161190A32 (club_id), INDEX IDX_BF5CDF616D861B89 (equipe_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE planning_seance ADD CONSTRAINT FK_BF5CDF6161190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE planning_seance ADD CONSTRAINT FK_BF5CDF616D861B89 FOREIGN KEY (equipe_id) REFERENCES equipe (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE seance ADD planning_source_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE seance ADD CONSTRAINT FK_DF7DFD0E3D6CC662 FOREIGN KEY (planning_source_id) REFERENCES planning_seance (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_DF7DFD0E3D6CC662 ON seance (planning_source_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE planning_seance DROP FOREIGN KEY FK_BF5CDF6161190A32');
        $this->addSql('ALTER TABLE planning_seance DROP FOREIGN KEY FK_BF5CDF616D861B89');
        $this->addSql('DROP TABLE planning_seance');
        $this->addSql('ALTER TABLE seance DROP FOREIGN KEY FK_DF7DFD0E3D6CC662');
        $this->addSql('DROP INDEX IDX_DF7DFD0E3D6CC662 ON seance');
        $this->addSql('ALTER TABLE seance DROP planning_source_id');
    }
}
