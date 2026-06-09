<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration : Réunions publiques.
 *
 * Ajoute le champ `visible_pour_tous` (BOOLEAN, default FALSE) sur la table reunion.
 * Permet de marquer une réunion comme publique → elle remonte dans le feed
 * "Pour toi" de tous les CLUB_MEMBER, même sans convocation nominative.
 *
 * Cas d'usage typique : AG ordinaire annuelle ouverte à tous les licenciés.
 */
final class Version20260607200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute reunion.visible_pour_tous (bool, default 0)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reunion ADD visible_pour_tous TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reunion DROP visible_pour_tous');
    }
}
