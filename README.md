# Magarrou ERP

ERP interne du groupe Magarrou : une fondation technique commune (« Core
ERP ») partagée par plusieurs activités indépendantes du groupe, complétée
par une couche transversale d'intelligence artificielle (« Magarrou AI »)
appliquée à l'ensemble du système.

## Architecture

### Core ERP — fondation transversale et neutre

Le Core ERP n'appartient à aucune activité en particulier : il fournit les
briques génériques utilisées par toutes les activités du groupe —
catalogue produits (avec un système d'attributs génériques), stock
multi-entrepôts, achats, ventes, facturation, reporting, notifications,
rôles et permissions.

### Les 5 activités du groupe

Toutes les activités s'appuient sur le même Core ERP et sont au même
niveau hiérarchique — **aucune d'entre elles ne constitue le cœur ou le
centre de l'ERP** :

- **Magarrou Sport**
- **Magarrou VTC**
- **Magarrou Bébé**
- **Magarrou Moto**
- **Magarrou Artisanats du Maroc**

### Magarrou AI — couche transversale

Magarrou AI n'est pas une activité du groupe : c'est une couche transversale
d'assistance et d'automatisation destinée à s'appliquer à l'ensemble du
système et de ses activités, au même titre que le Core ERP.

## Stack technique

- **Backend** : Laravel 13, PHP 8.4+
- **Interface d'administration** : Filament 5
- **Frontend applicatif** : Inertia.js + React 18, Tailwind CSS
- **Authentification & autorisations** : Laravel Sanctum, rôles/permissions
  via `spatie/laravel-permission`
- **Documents PDF** : `barryvdh/laravel-dompdf` (factures, avoirs, bons de
  retour, reçus VTC)
- **Base de données** : SQLite en développement (par défaut, voir
  `DB_CONNECTION` dans `.env`) ; MySQL/PostgreSQL supportés en production
- **Tests** : PHPUnit, exécutés via `php artisan test`

## Ce qui est effectivement implémenté aujourd'hui dans ce dépôt

Le Core ERP générique est le plus avancé et sert de socle commun :

- **Catalogue produits** — produits, variantes, marques, catégories, et un
  système d'attributs génériques en cours de généralisation (voir
  « État du projet » ci-dessous). Le modèle générique inclut des attributs
  orientés vêtement (saison, taille, équipe, couleur) utilisés notamment
  par l'activité Sport, mais reste un module du Core ERP, pas un module
  Sport dédié.
- **Stock** — mouvements, gestion multi-entrepôts, alertes de stock bas,
  permissions d'accès par entrepôt.
- **Achats** — commandes fournisseurs, retours physiques, avoirs
  fournisseurs, rapprochement des paiements.
- **Ventes** — commandes clients, factures, avoirs, suivi des paiements.
- **Reporting** — tableaux de bord commercial/financier, achats et stock.
- **Notifications & communication** (V1).
- **Rôles & permissions** — restriction d'accès par rôle (ex. chauffeur)
  sur les ressources sensibles du back-office.

Une seule activité dispose à ce jour de modules métier qui lui sont
propres, en plus du Core ERP :

- **Magarrou VTC** — courses, chauffeurs, véhicules, facturation légale
  des courses, workflow d'annulation.

Les activités **Magarrou Bébé**, **Magarrou Moto** et **Magarrou
Artisanats du Maroc** ne disposent pas encore, dans ce dépôt, de modules
métier qui leur soient spécifiques : elles s'appuient pour l'instant sur
les modules génériques du Core ERP décrits ci-dessus.

**Magarrou AI** ne dispose pas encore, dans ce dépôt, d'implémentation
métier dédiée identifiable ; sa place dans l'architecture est décrite ici
à titre de couche transversale prévue, distincte des 5 activités.

## État du projet (Core ERP)

Le développement du système d'attributs génériques du Core ERP avance par
paliers (« Tier ») documentés dans l'historique Git :

- **Tier 1** — fondations du système d'attributs génériques produits :
  terminé.
- **Tier 2** — migration progressive du catalogue vers ces attributs
  génériques (écriture miroir, peuplement rétroactif, vérification
  croisée) : en cours.

Un système de checkpoint indépendant de tout agent de développement
encadre la validation et le commit des étapes sensibles — voir
[`checkpoints/README.md`](checkpoints/README.md).

## Installation locale

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm install
npm run build
```

## Développement

```bash
composer dev
```

Lance en parallèle le serveur Artisan, le worker de queue, les logs
(`pail`) et Vite.

## Tests

```bash
composer test
# equivalent a :
php artisan test
```

## Licence

Projet privé — usage interne Magarrou Group.
