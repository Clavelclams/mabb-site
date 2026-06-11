<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * B9 — PIRB Notation séances anonyme (#20).
 * Table feedback_seance + UNIQUE(seance, joueur) hors mode anonyme.
 */
final class Version20260610120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'B9 PIRB : table feedback_seance (note 0-5 + commentaire + anonyme)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE feedback_seance (
                id INT AUTO_INCREMENT NOT NULL,
                seance_id INT NOT NULL,
                joueur_id INT DEFAULT NULL,
                note SMALLINT NOT NULL,
                commentaire TEXT DEFAULT NULL,
                est_anonyme TINYINT(1) NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_FS_SEANCE (seance_id),
                INDEX IDX_FS_JOUEUR (joueur_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // UNIQUE partial : un joueur n'a qu'un seul feedback NON-anonyme par séance
        // (l'anonyme peut être multiple — pour ne pas révéler par déduction)
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX UNQ_FS_SEANCE_JOUEUR_NON_ANON
            ON feedback_seance (seance_id, joueur_id)
            WHERE joueur_id IS NOT NULL AND est_anonyme = 0
        SQL);
        // Note : MariaDB ne supporte pas les UNIQUE WHERE — la contrainte
        // est doublée côté service (anti-doublon vérifié dans le controller)

        $this->addSql(<<<'SQL'
            ALTER TABLE feedback_seance
            ADD CONSTRAINT FK_FS_SEANCE FOREIGN KEY (seance_id)
            REFERENCES seance (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE feedback_seance
            ADD CONSTRAINT FK_FS_JOUEUR FOREIGN KEY (joueur_id)
            REFERENCES joueur (id) ON DELETE SET NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE feedback_seance DROP FOREIGN KEY FK_FS_SEANCE');
        $this->addSql('ALTER TABLE feedback_seance DROP FOREIGN KEY FK_FS_JOUEUR');
        $this->addSql('DROP TABLE feedback_seance');
    }
}
