<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bureau Manager Phase A — création tables reunion + reunion_convocation.
 *
 * - reunion : titre, type (CA/AG/...), date, lieu, ODJ, PV, statut
 * - reunion_convocation : pivot User×Reunion + statut présence + PV lu
 *
 * Multi-tenant via reunion.club_id (FK CASCADE).
 * ReunionConvocation : multi-tenant délégué via la reunion.
 *
 * UNIQUE INDEX (reunion_id, user_id) : un user n'est convoqué qu'une fois par réunion.
 * INDEX (user_id, pv_lu_at) : optimise la requête "Mes PV non lus" sur dashboard.
 */
final class Version20260605200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bureau Manager Phase A — tables reunion + reunion_convocation';
    }

    public function up(Schema $schema): void
    {
        // ====== Table reunion ======
        $this->addSql(<<<SQL
            CREATE TABLE reunion (
                id INT AUTO_INCREMENT NOT NULL,
                club_id INT NOT NULL,
                createur_id INT DEFAULT NULL,
                titre VARCHAR(200) NOT NULL,
                type VARCHAR(30) NOT NULL,
                date DATETIME NOT NULL,
                lieu VARCHAR(200) DEFAULT NULL,
                ordre_du_jour LONGTEXT NOT NULL,
                pv_contenu LONGTEXT DEFAULT NULL,
                statut VARCHAR(20) NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME DEFAULT NULL,
                INDEX IDX_REUNION_CLUB (club_id),
                INDEX IDX_REUNION_CREATEUR (createur_id),
                INDEX IDX_REUNION_DATE (date),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql('ALTER TABLE reunion ADD CONSTRAINT FK_REUNION_CLUB FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reunion ADD CONSTRAINT FK_REUNION_CREATEUR FOREIGN KEY (createur_id) REFERENCES user (id) ON DELETE SET NULL');

        // ====== Table reunion_convocation ======
        $this->addSql(<<<SQL
            CREATE TABLE reunion_convocation (
                id INT AUTO_INCREMENT NOT NULL,
                reunion_id INT NOT NULL,
                user_id INT NOT NULL,
                statut VARCHAR(20) NOT NULL,
                note LONGTEXT DEFAULT NULL,
                pv_lu_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE INDEX unique_reunion_user (reunion_id, user_id),
                INDEX idx_rc_user_pv_lu (user_id, pv_lu_at),
                INDEX IDX_RC_REUNION (reunion_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql('ALTER TABLE reunion_convocation ADD CONSTRAINT FK_RC_REUNION FOREIGN KEY (reunion_id) REFERENCES reunion (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reunion_convocation ADD CONSTRAINT FK_RC_USER FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE reunion_convocation');
        $this->addSql('DROP TABLE reunion');
    }
}
