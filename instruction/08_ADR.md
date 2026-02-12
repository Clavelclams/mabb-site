# ADR — Architecture Decision Records

## Format ADR
Chaque ADR suit le format : Date / Contexte / Options / Decision / Consequences.

---

### ADR-0001 — Monolithe modulaire Symfony
- Date : 2026-02-12
- Contexte : Le projet sert 3 espaces (vitrine, manager, pirb) + API. Faut-il un repo par espace ou un monolithe ?
- Options : (A) Microservices separes (B) Monolithe modulaire unique (C) Monorepo multi-apps
- Decision : (B) Monolithe modulaire unique. Un seul projet Symfony, decoupage par domaines (Core, Sport, Pirb, Vitrine) dans src/Entity, src/Controller, etc.
- Consequences : Deploiement simplifie. Partage facile des entites Core. Necessite une discipline de decoupage par dossier.

---

### ADR-0002 — Separation par host + firewalls
- Date : 2026-02-12
- Contexte : Les 3 espaces doivent etre isoles. Comment ?
- Options : (A) Prefixes d'URL (/vitrine, /manager, /pirb) (B) Sous-domaines + firewalls Symfony
- Decision : (B) Sous-domaines. mabb.fr = vitrine, manager.mabb.fr = manager, pirb.mabb.fr = pirb. Chaque espace a son fichier de routes (config/routes/{vitrine,manager,pirb}.yaml) et son firewall dans security.yaml.
- Consequences : Isolation forte. En dev local, necesssite config hosts ou proxy. Implemente dans config/routes/*.yaml et config/packages/security.yaml.

---

### ADR-0003 — Multi-tenant par club_id (une DB, isolation logique)
- Date : 2026-02-12
- Contexte : Manager et PIRB sont multi-clubs. Comment isoler les donnees ?
- Options : (A) Une DB par club (B) Une DB unique avec filtrage par club_id
- Decision : (B) Une DB unique. Chaque table metier porte un club_id. Filtrage systematique cote serveur via Voters Symfony (ClubScopeVoter). Jamais de filtrage uniquement cote front.
- Consequences : Plus simple a deployer. Necessite vigilance absolue : chaque requete metier doit etre filtree par club_id. Tests anti-fuite inter-club obligatoires.

---

### ADR-0004 — API Platform + Twig/UX (hybride web + API)
- Date : 2026-02-12
- Contexte : Faut-il une SPA (React/Vue) ou du rendu serveur ?
- Options : (A) SPA full JS (B) Twig + Symfony UX (Stimulus/Turbo) pour le web, API Platform pour le mobile (C) SSR Next.js
- Decision : (B) Hybride. La V1 web utilise Twig + Stimulus/Turbo. API Platform expose des endpoints REST/JSON pour le futur mobile (V3). La vitrine est en MVC classique (Twig).
- Consequences : UX rapide sans complexite SPA. Le mobile V3 consommera l'API Platform sans refonte backend. Les modules critiques (stats, PIRB) sont API-first.
- Note : Cette decision supersede la recommandation Node.js/React du CDC "Site web MABB.fr" pour la vitrine.

---

### ADR-0005 — Symfony 7.4 (au lieu de 6.4 LTS du CDC)
- Date : 2026-02-12
- Contexte : Le CDC MABB/PIRB V1 mentionne "Symfony 6.4 LTS" comme stack. Or le projet a ete initialise avec Symfony 7.4 (derniere version stable au moment du demarrage).
- Options : (A) Rester sur 6.4 LTS (support long terme garanti) (B) Utiliser 7.4 (fonctionnalites recentes, performances ameliorees)
- Decision : (B) Symfony 7.4. Le projet est en phase de developpement initial (pas de contrainte de stabilite LTS en production). Symfony 7.4 apporte des ameliorations de performance et des fonctionnalites recentes. Le passage en LTS (8.4 ou suivant) sera evalue avant la mise en production.
- Consequences : composer.json configure sur "7.4.*". Les dependances Symfony sont toutes en 7.4. Le CDC reste valide comme reference fonctionnelle mais la version Symfony y est obsolete.
