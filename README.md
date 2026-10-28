# Laravel Sync Engine

Ce plugin (`laravel-sync-engine`) est un **Moteur de Synchronisation de base de données** pour Laravel.

Il sert principalement à faire le pont entre une application locale (un client) et un serveur centralisé (ou entre plusieurs applications) pour s'assurer qu'elles ont toutes les mêmes données. C'est idéal pour les applications capables de fonctionner hors connexion (offline-first) ou les architectures distribuées.

## Installation

Installez le package via Composer :

```bash
composer require png/laravel-sync-engine
```

### Publier la configuration

Afin de générer le fichier de configuration `config/sync-engine.php` :

```bash
php artisan vendor:publish --provider="png\SyncEngine\SyncServiceProvider"
```

### Configuration du fichier `.env`

Ajoutez les clés de configuration pertinentes sur vos plateformes :

```env
SYNC_SERVER_URL=https://votre-serveur-central.com
SYNC_CLIENT_ID=client-caisse-paris
SYNC_API_KEY=votre_cle_secrete_hyper_longue

# Ajoutez ceci UNIQUEMENT sur le serveur central pour activer l'historique global :
# SYNC_IS_SERVER=true
```

## Fonctionnement du Moteur

### 1. Traçabilité des données (`Syncable` et `SyncObserver`)

Lorsque vous ajoutez le trait `Syncable` à l'un de vos modèles Laravel, le plugin observe chaque création, modification ou suppression. Dès qu'une donnée change, il la marque comme "sale" (`is_synced = false`), augmente son numéro de version, lui attribue un identifiant unique universel (`uuid`), et enregistre l'action dans un journal d'événements (la table `sync_logs`).

### 2. Envoi au Serveur (`SyncPushJob`)

Le plugin possède une tâche asynchrone (Job) qui cherche régulièrement toutes les données non synchronisées en base locales (`is_synced = false`). Il les regroupe en lots (la limite est paramétrable via la variable `SYNC_BATCH_SIZE`, 1000 par défaut) et les envoie via une requête HTTP `POST` vers l'URL du serveur central (configurée dans `sync-engine.php`). Une fois l'envoi réussi, ces données sont marquées comme "synchronisées" (`is_synced = true`).

### 3. Réception sur le Serveur (`SyncController`)

Le plugin expose une route API (la méthode `push`) conçue pour _recevoir_ des données provenant de clients locaux. Lorsqu'il reçoit une liste de données, il vérifie l'`uuid` :

- Si la donnée existe déjà, elle est mise à jour avec les nouvelles informations.
- Sinon, elle est créée dans la base de données.
  Le tout est géré nativement via la fonction `updateOrCreate`.

### 4. Récupération depuis le Serveur (`SyncPullJob`)

À l'inverse du Push, le plugin possède également une tâche `SyncPullJob` pour récupérer automatiquement les dernières modifications du serveur (Bidirectionnel). Cette tâche télécharge les changements distants et les fusionne doucement dans la base locale (via l'`uuid`) sans relancer d'événements inutiles (`saveQuietly()`).

### 5. Tableau de Bord de Télémétrie (`DashboardController`)

Une petite interface visuelle est incluse, accessible via l'URL `/sync-dashboard`. Elle permet d'afficher des statistiques en temps réel sur la santé de la synchronisation :

- Nombre de données en attente (Pending)
- Nombre de données synchronisées (Synced)
- Cas de conflits (Conflicts)
- Cas d'erreurs de transfert (Errors)

### 6. Historique du Serveur Central (`sync_histories`)

Sur le serveur central uniquement, vous pouvez tracer toutes les opérations de requêtes entrantes de Push et de Pull effectuées par vos clients sur le réseau.
Pour activer cette fonctionnalité, déclarez explicitement votre application comme serveur dans le fichier `.env` :

```env
SYNC_IS_SERVER=true
```

Puis exécutez vos migrations sur le serveur (`php artisan migrate`). Une nouvelle table d'audit `sync_histories` sera créée et enregistrera chaque transaction (Client, type d'opération Push/Pull, Statut, Nombre d'enregistrements insérés ou envoyés).
_Note : Pour éviter tout encombrement inutile, cette table d'historique globale n'est volontairement pas créée sur les bases de données des applications clientes._

### 7. Gestion Précise des Conflits (Optimistic Concurrency Control - OCC)

Le plugin garantit l'intégrité absolue des données grâce à un contrôle de concurrence optimiste basé sur la notion de **`base_version`** :
- **`version`** : Représente le compteur d'évolution de la ligne.
- **`base_version`** : Mémorise la version exacte dont le client est parti lors de sa dernière synchronisation avec le serveur.

Même si un client hors-ligne modifie une ligne 10 fois en local (faisant grimper sa `version` locale), sa `base_version` reste ancrée à la version qu'il avait récupérée du serveur. Lors du Push, si le serveur a également été modifié entre-temps (`version_serveur !== client.base_version`), la modification du client est interceptée, bloquée et consignée dans `sync_logs` avec le statut `"conflict"`, évitant tout écrasement silencieux !

Vous pouvez résoudre ces conflits grâce à deux routes API pour vos interfaces d'administration :

**A. Obtenir la comparaison des Conflits (`GET /api/sync/conflicts`)**  
Renvoie un relevé complet des conflits avec, pour chacun, la version actuelle sur la base du serveur (`server_data`) juxtaposée avec la donnée proposée et rejetée du client en cause (`client_data`).

**B. Résoudre le Conflit manuellement (`POST /api/sync/resolve-conflict`)**  
Charge payload attendue :

```json
{
  "table": "users",
  "uuid": "xxxxx-yyyy-zzzz",
  "resolution": "client" // ou "server"
}
```

- Si `"server"` : Le serveur maintient sa donnée intacte et incrémente sa version. Le conflit est acquitté et le client s'alignera lors de son prochain _Pull_.
- Si `"client"` : L'administrateur décide de forcer les données du client. Le serveur intègre la modification et force une version artificiellement haute (`+50`) afin que cette donnée s'impose définitivement sur l'ensemble du réseau !

## Comment Communiquent l'Application Locale et le Serveur ?

La communication s'effectue via Internet au moyen de **requêtes HTTP/API (REST)** classiques au format **JSON**. Il y a deux grandes étapes :

### 1. Le Client envoie ses données (Local vers Serveur)

L'application locale utilise une tâche en arrière-plan (`SyncPushJob.php`) pour rassembler les enregistrements modifiés et non synchronisés (`is_synced = false`). Elle construit un "colis" (payload JSON) contenant le nom de la table et une liste de lignes de données dont la quantité maximale à envoyer en une seule fois est administrable via la variable `SYNC_BATCH_SIZE`.
Elle envoie ensuite une requête **HTTP POST** vers l'URL du serveur (paramétrée via votre variable globale `SYNC_SERVER_URL` et la route `/sync/push`).

Exemple de colis JSON expédié :

```json
{
  "table": "users",
  "data": [
    { "uuid": "abc-123", "name": "Pascal", "is_synced": false, "version": 2 },
    { "uuid": "def-456", "name": "Jean", "is_synced": false, "version": 1 }
  ]
}
```

### 2. Le Serveur reçoit et intègre les données

De l'autre côté, le serveur expose une route API (`/sync/push` gérée par `SyncController.php`).
À la réception du colis JSON mentionné plus haut :

1. Il lit le nom de la `"table"` (ici : `users`) et retrouve le Modèle Laravel correspondant dans sa configuration (`sync-engine.php`).
2. Pour chaque élément dans le tableau `"data"`, il utilise la méthode native `updateOrCreate()` basée sur l'`"uuid"`.
3. En conséquence : si l'UUID existe déjà sur le serveur, la donnée est mise à jour. S'il n'existe pas, la donnée est créée.

### 3. Le Client télécharge les nouveautés (Serveur vers Local - PULL)

Une seconde tâche en arrière-plan (`SyncPullJob.php`) est exécutée du côté de l'application locale. Son rôle est de récupérer les modifications effectuées par les autres noeuds ou sur le serveur.

1. L'application locale demande au serveur (`GET /sync/pull`) de lui envoyer toutes les données modifiées après sa dernière synchronisation.
2. Le serveur lui renvoie les enregistrements modifiés, en excluant ceux que ce client a lui-même envoyés.
3. À la réception, le client télécharge le colis JSON, supprime l'ID physique du serveur et intègre les données localement (`updateOrCreate` sur l'UUID) en utilisant `saveQuietly()` pour éviter de déclencher des boucles de synchronisation infinies !

## Sécurité (Clé d'API / Token)

Pour éviter que quiconque ne puisse altérer vos données en ciblant la route publique `/sync/push`, le Moteur de Synchronisation utilise une **protection par Clé d'API (API Key)**.

1. **Configuration (`SYNC_API_KEY`)** : Vous devez générer une clé secrète complexe et l'inscrire dans le fichier `.env` du Serveur et de tous vos Clients locaus.
   _Exemple : `SYNC_API_KEY=v1O9s3lXmZ5l...`_
2. **Vérification (Middleware)** : Lorsque le client (`SyncPushJob`) expédie ses données vers le serveur, il ajoute cette clé secrète dans son en-tête privé (`X-Sync-Key`). Le serveur possède un garde-barrière (Middleware `VerifySyncApiKey`) qui vérifiera cette clé avant d'accepter la synchronisation. Si la clé est mauvaise, le serveur refuse tout accès `(Erreur 401 Unauthorized)`.

## Cohabitation avec des Clés Primaires Auto-Incrémentées (`id`)

Il n'est **absolument pas nécessaire** d'abandonner vos clés primaires traditionnelles `id` existantes. Votre code local et vos relations classiques (`$article->user_id`) continuent de fonctionner tels quels.

Le plugin gère intelligemment les bases de données distribuées grâce à la séparation `id` / `uuid` :

1. **L'`id` (Auto-Incrémenté)** : Il reste totalement _Local_. Chaque base (Client ou Serveur) garde son propre système d'ID physiques sans jamais les écraser (afin d'éviter les collisions dans le cas où plusieurs clients créeraient l'ID #5 en même temps).
2. **L'`uuid` (Universel)** : C'est le lien Global généré dynamiquement par le trait `Syncable`. Il permet au Serveur de retrouver de façon absolue un enregistrement distant. Le serveur "bloque" nativement (`unset($row['id'])`) l'injection d'IDs d'un client distant et se base uniquement sur l'`uuid` pour mettre à jour ou créer la donnée avec son propre auto-incrément.

> 🛠️ **Configuration :** Vous pouvez informer le plugin du système de clé primaire que votre base de données utilise via la configuration `uses_auto_increment` (`config/sync-engine.php`) :
>
> - Si `uses_auto_increment = true` (Défaut) : le plugin ignore et coupe votre `id` auto-incrément local lors des communications entre serveurs pour éviter tout conflit.
> - Si `uses_auto_increment = false` : le plugin devine que votre application utilise uniquement les UUID en tant que clé primaire absolue et laisse transiter la clé telle quelle.
>
> ⚙️ **Paramétrage du statut initial de synchronisation (`default_is_synced`) :**  
> Lorsque vous utilisez la macro `$table->syncable();`, vous pouvez configurer l'état par défaut de la colonne de traçabilité `is_synced`.
>
> - Ajoutez `SYNC_DEFAULT_IS_SYNCED=false` (Défaut) dans votre `.env` pour que toute nouvelle migration ou donnée massive considère les enregistrements comme non-synchronisés (et force le client à les expédier).
> - Passez à `true` si votre logique de départ considère les données comme déjà ancrées.
>
> 🎯 **Exception par Table :** Vous pouvez facilement surcharger cette valeur globale lors de l'exécution d'une migration pour une table spécifique en passant l'argument `true` ou `false` à la macro :
> `$table->syncable(true); // Ignore le .env et force à true !`

**Mise en place sur une application existante :**
Afin que cela fonctionne, vous devez simplement ajouter les colonnes nécessaires dans vos migrations de tables existantes sans altérer la colonne `id`.
Pour vous simplifier la vie, le plugin inclut une **macro Laravel native**. Vous n'avez qu'à appeler `$table->syncable();` !

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use png\SyncEngine\SyncEngine;

return new class extends Migration {
    public function up()
    {
        // 1. On ajoute les nouvelles colonnes via la macro
        Schema::table('votre_table', function (Blueprint $table) {
            $table->syncable();
        });

        // 2. (Très Important) On génère un UUID pour les anciennes données existantes
        // Clé simple (par défaut 'id') :
        SyncEngine::populateExistingData('votre_table');
        // Ou clé composite :
        // SyncEngine::populateExistingData('votre_table', ['store_id', 'numero']);
    }

    public function down()
    {
        Schema::table('votre_table', function (Blueprint $table) {
            // Méthode inverse très pratique pour le rollback
            $table->dropSyncable();
        });
    }
};
```

### Mise à Niveau d'une Application Déjà en Production (Sans Casse)

Si vos applications (clients ou serveur) tournent déjà en production avec d'anciennes versions du plugin :

> 🛡️ **Rétrocompatibilité Garantie :**  
> Le trait `Syncable` et les jobs vérifient dynamiquement la présence de la colonne `base_version`. Tant que la table n'a pas été mise à niveau, **aucune erreur SQL ne survient** et le moteur fonctionne en mode de compatibilité.

Pour activer le contrôle de concurrence OCC (`base_version`) sur vos tables existantes :

#### Méthode 1 : Commande Automatique Artisan (Recommandée)
Exécutez simplement la commande fournie par le package sur le serveur et sur chaque client :

```bash
php artisan sync:upgrade-base-version
```

Cette commande parcourt automatiquement toutes les tables déclarées dans votre fichier `config/sync-engine.php` :
1. Elle ajoute la colonne `base_version` si elle est absente.
2. Elle initialise immédiatement `base_version = version` pour tous les enregistrements existants.

#### Méthode 2 : Via une Migration Laravel Standard
Si vous préférez exécuter une migration `php artisan migrate` classique dans votre pipeline CI/CD :

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up()
    {
        $tables = array_keys(config('sync-engine.tables', []));

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'base_version')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->integer('base_version')->default(1)->after('version');
                });

                DB::table($table)->update(['base_version' => DB::raw('version')]);
            }
        }
    }

    public function down()
    {
        $tables = array_keys(config('sync-engine.tables', []));

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'base_version')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn('base_version');
                });
            }
        }
    }
};
```

## Activer la Synchronisation sur un Modèle

Pour rendre un modèle de votre application 100% synchronisable, voici les 2 étapes obligatoires :

**1. Ajouter le Trait "Syncable" au Modèle**  
Ouvrez la classe de votre Modèle (ex: `Customer.php`) et instanciez le trait du plugin :

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use png\SyncEngine\Traits\Syncable;

class Customer extends Model
{
    use Syncable; // Active l'observation magique des événements Eloquent

    protected $fillable = ['nom', 'email'];
}
```

**2. Enregistrer la table dans la configuration**  
Ouvrez le fichier `config/sync-engine.php` et associez le nom SQL de la table avec son Modèle Laravel équivalent :

```php
'tables' => [
    'customers' => \App\Models\Customer::class,
]
```

_(Cette configuration vitale permet aux requêtes Pull/Push réseau de savoir quel modèle instancier lorsqu'elles reçoivent un colis visant la table SQL 'customers')._

## Automatisation en Arrière-Plan (CRON)

Le plugin est fourni avec une commande universelle permettant de tout synchroniser automatiquement. Vous n'avez pas besoin de programmer vos modèles un par un !

Sur **vos serveurs locaux (clients)**, ouvrez le fichier `app/Console/Kernel.php` et ajoutez cette ligne dans la méthode `schedule` :

```php
protected function schedule(Schedule $schedule)
{
    // Lance un cycle de synchronisation global pour TOUS les modèles configurés
    $schedule->command('sync:run')->everyMinute();
}
```

_(Vous pouvez évidemment remplacer `->everyMinute();` par `->everyFiveMinutes();` ou `->hourly();` ou même lire la fréquence depuis votre `.env` avec `->cron(env('SYNC_CRON'))`)._

Assurez-vous bien entendu que le CRON de Linux est activé sur vos machines clientes (les serveurs centraux, eux, n'ont besoin d'aucun Cron !). En environnement de développement (Sail/Docker), laissez tourner dans un terminal :

```bash
php artisan schedule:work
```

---

En résumé, **Laravel Sync Engine** est un outil qui permet à votre application de collecter localement toutes les alertes de modifications de la base de données, pour ensuite les synchroniser de manière silencieuse en réseau avec un nœud distant garantissant une équivalence parfaite sur tous vos environnements.

---

## 📋 Récapitulatif : Serveur Central vs Application Locale

Voici le guide rapide pour configurer chaque environnement :

### 1. Serveur Central (Cloud / Hub)

- **Rôle** : Reçoit les données (`Push`), sert de référence pour les conflits et fournit les mises à jour (`Pull`).
- **Variables d'environnement (`.env`)** :
  ```env
  SYNC_IS_SERVER=true
  SYNC_API_KEY=votre_cle_secrete_partagee
  ```
- **Base de données** :
  - Exécuter les migrations (`php artisan migrate`). La table d'historique global `sync_histories` sera automatiquement créée grâce à `SYNC_IS_SERVER=true`.
  - Vos tables synchronisées doivent comporter la macro `$table->syncable();`.
- **Planificateur (Cron)** :
  - ❌ **Aucun cron requis** pour la synchronisation (le serveur agit de manière passive aux requêtes API).

---

### 2. Application Locale (Client / Offline-first)

- **Rôle** : Enregistre les modifications hors-ligne ou locales, pousse les données vers le serveur et récupère les nouveautés.
- **Variables d'environnement (`.env`)** :
  ```env
  SYNC_SERVER_URL=https://votre-serveur-central.com
  SYNC_CLIENT_ID=client-caisse-01 # Identifiant unique de ce client
  SYNC_API_KEY=votre_cle_secrete_partagee
  SYNC_BATCH_SIZE=1000 # Optionnel (défaut: 1000)
  ```
- **Base de données** :
  - Vos tables synchronisées doivent comporter la macro `$table->syncable();`.
  - La table `sync_histories` n'est pas créée localement.
- **Planificateur (Cron)** :
  - ⚠️ **Requis** : Ajouter la commande dans `app/Console/Kernel.php` (ou `routes/console.php` pour Laravel 11+) :
    ```php
    $schedule->command('sync:run')->everyMinute();
    ```
  - S'assurer que le planificateur Laravel tourne (`crontab` ou `php artisan schedule:work`).
