<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute le champ `sigle` (VARCHAR 20, nullable) à la table `club`.
 * Pré-remplit "MABB" pour Amiens Métropole Basket-Ball (id=2).
 */
final class Version20260629120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Club.sigle — acronyme court (ex: MABB, ASVEL…)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club ADD sigle VARCHAR(20) DEFAULT NULL');
        // Pré-remplissage : Amiens Métropole Basket-Ball → MABB
        $this->addSql("UPDATE club SET sigle = 'MABB' WHERE id = 2");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club DROP COLUMN sigle');
    }
}
