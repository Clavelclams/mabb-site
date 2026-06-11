# 📨 Conventions emails MABB

> Référence pour tous les futurs blocs : quel expéditeur utiliser selon le contexte.
> Décidé par Clavel le 10/06/2026, à appliquer systématiquement.

## Les 2 adresses officielles

| Adresse | Usage | Variable .env |
|---|---|---|
| **admin@mabb.fr** | TOUT ce qui est administration du site / sécurité / interne | `MAILER_FROM` |
| **contact@mabb.fr** | Contact public vitrine (licences FFBB, partenariats) | `MAILER_FROM_CONTACT` |

## Règle de décision

### Utiliser `admin@mabb.fr` (MAILER_FROM)

- **Sécurité** : reset password, alertes anti-brute-force, notifications de connexion suspecte
- **Administratif interne** : convocations CA, PV, alertes admin, audit RGPD
- **Système** : notifications de crash, alertes mailer, cron logs
- **Workflow Manager** : validation parent-enfant, lien User↔Joueur, élections, badges
- **Pirb** : notifs in-app email, feedback séances digest staff
- **RGPD** : confirmation d'effacement de compte, export de données

### Utiliser `contact@mabb.fr` (MAILER_FROM_CONTACT)

- Réponses automatiques aux demandes de licence FFBB
- Formulaire de contact vitrine
- Newsletter publique du club (si V2)
- Réponses aux partenariats sponsors

## Comment l'utiliser dans le code

### En PHP (service)

```php
// 1. Injection dans services.yaml
App\Service\Security\ResetPasswordTokenManager:
    arguments:
        $mailerFrom: '%env(MAILER_FROM)%'         // → admin@mabb.fr

App\Service\VitrineContactMailer:
    arguments:
        $mailerFrom: '%env(MAILER_FROM_CONTACT)%' // → contact@mabb.fr

// 2. Dans le service
public function __construct(
    private string $mailerFrom,
) {}

$email = (new TemplatedEmail())
    ->from(new Address($this->mailerFrom, 'MABB Manager'));
```

### En .env

```bash
# Convention : ne JAMAIS écrire admin@mabb.fr en clair dans le code,
# toujours passer par %env(MAILER_FROM)% / %env(MAILER_FROM_CONTACT)%
MAILER_FROM=admin@mabb.fr
MAILER_FROM_CONTACT=contact@mabb.fr
```

## Sigle "MABB Manager" (display name)

Quand on construit l'`Address` :

```php
new Address($this->mailerFrom, 'MABB Manager')   // pour Manager / sécu
new Address($this->mailerFrom, 'MABB PIRB')      // pour PIRB
new Address($this->mailerFrom, 'MABB')           // générique
```

## Configuration prod (Brevo)

Le DSN Brevo doit être uniquement dans `.env.local` (jamais commit) :

```bash
# .env.local — NON commité (cf. .gitignore)
MAILER_DSN=brevo+smtp://USERNAME:API_KEY@smtp-relay.brevo.com:587
```

Pour récupérer la clé : Brevo > Settings > SMTP & API > clé Master / API key.

⚠️ **velito-credentials-policy** : ne JAMAIS demander à Claude de lire ou
réécrire la clé en clair dans le chat, les outputs, ou un fichier commité.

## Setup Brevo (one-time)

1. Connexion à brevo.com avec ton compte
2. Vérifier les domaines : `admin@mabb.fr` et `contact@mabb.fr` doivent être
   validés via SPF + DKIM (sinon les mails atterrissent en spam Outlook/Gmail)
3. Tester l'envoi via console depuis `.env.local` :
   ```powershell
   php bin/console mailer:test admin@mabb.fr --from=admin@mabb.fr
   ```
4. Quota Brevo gratuit : 300 mails/jour — suffisant pour V1.

## Tracking
- Date adoption : 10/06/2026 (B1 reset password)
- Mise à jour : à chaque changement de convention, dater la modif ici
