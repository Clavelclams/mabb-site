<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * [Bloc K, 13/07/2026] Table push_token : les appareils qui reçoivent les
 * notifications push de l'app Venaball.
 *
 * Un utilisateur peut avoir PLUSIEURS appareils (téléphone, tablette) : pas de
 * contrainte d'unicité sur user_id. En revanche, un même appareil ne doit exister
 * qu'une fois, d'où l'index unique sur le jeton lui-même.
 *
 * ON DELETE CASCADE sur l'utilisateur : compte supprimé, jetons supprimés. On ne
 * garde pas des identifiants d'appareil orphelins (RGPD, et bon sens).
 */
final class Version20260713220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cree la table push_token (notifications push de l app Venaball).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS push_token (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                token VARCHAR(255) NOT NULL,
                plateforme VARCHAR(20) DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                vu_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX unique_push_token (token),
                INDEX idx_push_token_user (user_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE push_token
                ADD CONSTRAINT FK_push_token_user
                FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS push_token');
    }
}
