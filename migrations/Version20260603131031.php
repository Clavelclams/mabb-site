<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260603131031 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE convocation (id INT AUTO_INCREMENT NOT NULL, reponse VARCHAR(20) DEFAULT NULL, motif LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, repondue_at DATETIME DEFAULT NULL, rencontre_id INT NOT NULL, joueur_id INT NOT NULL, INDEX IDX_C03B3F5F6CFC0818 (rencontre_id), INDEX IDX_C03B3F5FA9E2D76C (joueur_id), UNIQUE INDEX unique_convocation (rencontre_id, joueur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE equipe (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(100) NOT NULL, categorie VARCHAR(30) NOT NULL, saison VARCHAR(9) NOT NULL, niveau VARCHAR(80) DEFAULT NULL, is_active TINYINT NOT NULL, created_at DATETIME NOT NULL, club_id INT NOT NULL, INDEX IDX_2449BA1561190A32 (club_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE joueur (id INT AUTO_INCREMENT NOT NULL, prenom VARCHAR(80) NOT NULL, nom VARCHAR(80) NOT NULL, date_naissance DATE DEFAULT NULL, poste VARCHAR(40) DEFAULT NULL, numero_maillot SMALLINT DEFAULT NULL, licence VARCHAR(20) DEFAULT NULL, is_active TINYINT NOT NULL, created_at DATETIME NOT NULL, club_id INT NOT NULL, equipe_id INT DEFAULT NULL, user_id INT DEFAULT NULL, INDEX IDX_FD71A9C561190A32 (club_id), INDEX IDX_FD71A9C56D861B89 (equipe_id), INDEX IDX_FD71A9C5A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE presence (id INT AUTO_INCREMENT NOT NULL, present TINYINT NOT NULL, source VARCHAR(20) NOT NULL, motif_absence LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, joueur_id INT NOT NULL, seance_id INT DEFAULT NULL, rencontre_id INT DEFAULT NULL, INDEX IDX_6977C7A5A9E2D76C (joueur_id), INDEX IDX_6977C7A5E3797A94 (seance_id), INDEX IDX_6977C7A56CFC0818 (rencontre_id), UNIQUE INDEX unique_seance (joueur_id, seance_id), UNIQUE INDEX unique_rencontre (joueur_id, rencontre_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE rencontre (id INT AUTO_INCREMENT NOT NULL, adversaire VARCHAR(150) NOT NULL, date DATETIME NOT NULL, lieu VARCHAR(120) DEFAULT NULL, domicile TINYINT NOT NULL, score_equipe SMALLINT DEFAULT NULL, score_adverse SMALLINT DEFAULT NULL, statut VARCHAR(20) NOT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, club_id INT NOT NULL, equipe_id INT NOT NULL, INDEX IDX_460C35ED61190A32 (club_id), INDEX IDX_460C35ED6D861B89 (equipe_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE seance (id INT AUTO_INCREMENT NOT NULL, date DATETIME NOT NULL, lieu VARCHAR(120) NOT NULL, duree_minutes SMALLINT DEFAULT NULL, type VARCHAR(30) NOT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, club_id INT NOT NULL, equipe_id INT NOT NULL, INDEX IDX_DF7DFD0E61190A32 (club_id), INDEX IDX_DF7DFD0E6D861B89 (equipe_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE convocation ADD CONSTRAINT FK_C03B3F5F6CFC0818 FOREIGN KEY (rencontre_id) REFERENCES rencontre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE convocation ADD CONSTRAINT FK_C03B3F5FA9E2D76C FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE equipe ADD CONSTRAINT FK_2449BA1561190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE joueur ADD CONSTRAINT FK_FD71A9C561190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE joueur ADD CONSTRAINT FK_FD71A9C56D861B89 FOREIGN KEY (equipe_id) REFERENCES equipe (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE joueur ADD CONSTRAINT FK_FD71A9C5A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE presence ADD CONSTRAINT FK_6977C7A5A9E2D76C FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE presence ADD CONSTRAINT FK_6977C7A5E3797A94 FOREIGN KEY (seance_id) REFERENCES seance (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE presence ADD CONSTRAINT FK_6977C7A56CFC0818 FOREIGN KEY (rencontre_id) REFERENCES rencontre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE rencontre ADD CONSTRAINT FK_460C35ED61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE rencontre ADD CONSTRAINT FK_460C35ED6D861B89 FOREIGN KEY (equipe_id) REFERENCES equipe (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE seance ADD CONSTRAINT FK_DF7DFD0E61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE seance ADD CONSTRAINT FK_DF7DFD0E6D861B89 FOREIGN KEY (equipe_id) REFERENCES equipe (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE convocation DROP FOREIGN KEY FK_C03B3F5F6CFC0818');
        $this->addSql('ALTER TABLE convocation DROP FOREIGN KEY FK_C03B3F5FA9E2D76C');
        $this->addSql('ALTER TABLE equipe DROP FOREIGN KEY FK_2449BA1561190A32');
        $this->addSql('ALTER TABLE joueur DROP FOREIGN KEY FK_FD71A9C561190A32');
        $this->addSql('ALTER TABLE joueur DROP FOREIGN KEY FK_FD71A9C56D861B89');
        $this->addSql('ALTER TABLE joueur DROP FOREIGN KEY FK_FD71A9C5A76ED395');
        $this->addSql('ALTER TABLE presence DROP FOREIGN KEY FK_6977C7A5A9E2D76C');
        $this->addSql('ALTER TABLE presence DROP FOREIGN KEY FK_6977C7A5E3797A94');
        $this->addSql('ALTER TABLE presence DROP FOREIGN KEY FK_6977C7A56CFC0818');
        $this->addSql('ALTER TABLE rencontre DROP FOREIGN KEY FK_460C35ED61190A32');
        $this->addSql('ALTER TABLE rencontre DROP FOREIGN KEY FK_460C35ED6D861B89');
        $this->addSql('ALTER TABLE seance DROP FOREIGN KEY FK_DF7DFD0E61190A32');
        $this->addSql('ALTER TABLE seance DROP FOREIGN KEY FK_DF7DFD0E6D861B89');
        $this->addSql('DROP TABLE convocation');
        $this->addSql('DROP TABLE equipe');
        $this->addSql('DROP TABLE joueur');
        $this->addSql('DROP TABLE presence');
        $this->addSql('DROP TABLE rencontre');
        $this->addSql('DROP TABLE seance');
    }
}
