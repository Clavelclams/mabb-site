<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Multi-club Lot 2b-1 : ajoute à `club` les champs de création / officialisation.
 *
 * ⚠️ Migration RÉÉCRITE À LA MAIN le 09/07 : le `doctrine:migrations:diff`
 * d'origine avait généré ~294 requêtes parasites (DROP INDEX sur plein de
 * tables, re-CREATE de organisme_ffbb, changements de type) à cause d'une base
 * de DEV désynchronisée. On ne garde QUE les changements voulus sur `club`.
 * Les noms FK/index reprennent ceux générés par Doctrine (déterministes).
 */
final class Version20260709124204 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'club: discipline, numero_ffbb (unique), is_officiel, plan, createur (FK user)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE club ADD discipline VARCHAR(20) DEFAULT NULL, ADD numero_ffbb VARCHAR(20) DEFAULT NULL, ADD is_officiel TINYINT(1) NOT NULL DEFAULT 0, ADD plan VARCHAR(20) DEFAULT 'decouverte' NOT NULL, ADD createur_id INT DEFAULT NULL");
        $this->addSql('ALTER TABLE club ADD CONSTRAINT FK_B8EE387273A201E5 FOREIGN KEY (createur_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B8EE3872D214B8A9 ON club (numero_ffbb)');
        $this->addSql('CREATE INDEX IDX_B8EE387273A201E5 ON club (createur_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club DROP FOREIGN KEY FK_B8EE387273A201E5');
        $this->addSql('DROP INDEX UNIQ_B8EE3872D214B8A9 ON club');
        $this->addSql('DROP INDEX IDX_B8EE387273A201E5 ON club');
        $this->addSql('ALTER TABLE club DROP discipline, DROP numero_ffbb, DROP is_officiel, DROP plan, DROP createur_id');
    }
}
