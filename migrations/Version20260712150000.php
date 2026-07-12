<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * [OTM V2 — 12/07/2026] Organisation du week-end : renforts + interdictions de poste.
 *
 * ⚠️ Migration ÉCRITE À LA MAIN (la base de DEV est désynchronisée : un
 * `doctrine:migrations:diff` génère des centaines de requêtes parasites).
 * Elle ne contient QUE les deux changements voulus.
 *
 * 1. affectation_match.est_assistant
 *    false = TITULAIRE du poste (staff, supervision, auto-affecté à la clôture)
 *    true  = ASSISTANT (« assisté de ») : bénévole/parent/joueuse en renfort,
 *            jamais auto-affecté, plusieurs possibles sur un même poste.
 *
 * 2. otm_interdiction
 *    « Cette personne peut tout tenir SAUF ce poste » (ex. l'arbitrage).
 *    Bloque l'auto-inscription, l'auto-affectation ET le glisser-déposer admin.
 */
final class Version20260712150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'OTM V2 : affectation_match.est_assistant + table otm_interdiction';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE affectation_match ADD est_assistant TINYINT(1) DEFAULT 0 NOT NULL');

        $this->addSql('CREATE TABLE otm_interdiction (
            id INT AUTO_INCREMENT NOT NULL,
            club_id INT NOT NULL,
            user_id INT NOT NULL,
            role VARCHAR(30) NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE INDEX uniq_otm_interdiction (club_id, user_id, role),
            INDEX idx_otm_interdiction_user (club_id, user_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('ALTER TABLE otm_interdiction ADD CONSTRAINT FK_OTM_INTERD_CLUB
            FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE otm_interdiction ADD CONSTRAINT FK_OTM_INTERD_USER
            FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE otm_interdiction DROP FOREIGN KEY FK_OTM_INTERD_CLUB');
        $this->addSql('ALTER TABLE otm_interdiction DROP FOREIGN KEY FK_OTM_INTERD_USER');
        $this->addSql('DROP TABLE otm_interdiction');
        $this->addSql('ALTER TABLE affectation_match DROP est_assistant');
    }
}
