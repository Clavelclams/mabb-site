<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lien Coach <-> Équipe.
 *
 * Jusqu'ici, le rôle COACH ne désignait rien de précis : un coach voyait toutes les
 * équipes du club, et aucune séance n'appartenait à personne. Impossible, donc,
 * d'afficher "tes entraînements", et impossible de savoir qui n'a pas fait l'appel.
 *
 * ManyToMany, parce que les deux sens existent en vrai : une équipe a souvent un
 * coach principal et un adjoint, et un coach suit fréquemment deux catégories.
 */
final class Version20260713160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table de liaison equipe_coach : qui entraine quelle equipe.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE equipe_coach (
                equipe_id INT NOT NULL,
                user_id INT NOT NULL,
                INDEX IDX_EC_EQUIPE (equipe_id),
                INDEX IDX_EC_USER (user_id),
                PRIMARY KEY(equipe_id, user_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql('ALTER TABLE equipe_coach ADD CONSTRAINT FK_EC_EQUIPE FOREIGN KEY (equipe_id) REFERENCES equipe (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE equipe_coach ADD CONSTRAINT FK_EC_USER FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE equipe_coach');
    }
}
