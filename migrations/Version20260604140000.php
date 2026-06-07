<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * V1 trombinoscope — ajout du champ photo_path à Joueur.
 *
 * Stocke le chemin relatif du fichier image dans public/uploads/joueurs/.
 * VARCHAR(255) NULL : nullable car la majorité des joueuses existantes
 * n'ont pas encore de photo (fallback initiales colorées dans la vue).
 *
 * Pattern aligné sur User::photoPath pour cohérence.
 */
final class Version20260604140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout du champ photo_path à joueur (V1 trombinoscope upload photo)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE joueur ADD photo_path VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE joueur DROP photo_path');
    }
}
