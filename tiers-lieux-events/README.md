# Événements Tiers-Lieux.org

Affiche dans WordPress les événements d'un tiers-lieu saisis sur tiers-lieux.org
(backend Communecter, endpoint public). Aucun lieu n'est préconfiguré : il faut le choisir dans les réglages.

## Choisir son tiers-lieu

Réglages → **Événements Tiers-Lieux** (ou lien « Choisir mon tiers-lieu » dans la liste des extensions) :
tapez le nom du lieu (3 lettres minimum), cliquez sur le bon résultat, vérifiez l'adresse proposée, enregistrez.
La recherche interroge l'API de tiers-lieux.org (`/co2/search/globalautocomplete`, via le serveur WordPress).
Le shortcode utilise ensuite ce lieu sans attribut `id`.
Tant qu'aucun lieu n'est choisi, les administrateurs voient une alerte avec un lien vers les réglages (les visiteurs ne voient rien).

## Installation

Zipper le dossier `tiers-lieux-events/`, puis Extensions → Ajouter → Téléverser, et activer.

## Utilisation

```
[tiers_lieux_events]
[tiers_lieux_events show="all" limit="20"]
[tiers_lieux_events show="past" limit="5" images="no"]
```

| Attribut | Défaut | Valeurs |
|---|---|---|
| `show` | `upcoming` | `upcoming` (à venir), `past` (passés, du plus récent), `all` |
| `limit` | `20` | nombre max, `0` = illimité |
| `cache` | `5` | minutes de cache |
| `images` | `yes` | `no` pour masquer |
| `empty` | « Aucun événement à venir… » | message si liste vide |
| `id` | lieu choisi dans les réglages | identifiant Communecter d'un autre tiers-lieu (afficher plusieurs lieux sur un même site) |
| `place` | adresse des réglages | adresse pour l'agenda et les données structurées |

Chaque titre pointe vers la fiche `https://www.tiers-lieux.org/profil/<slug>`.
Le cache se vide via « Vider le cache » dans la liste des extensions.

Chaque événement propose aussi « Ajouter à mon agenda » (Google, Outlook, .ics) et « Partager » (Facebook, WhatsApp, X, LinkedIn, Bluesky, e-mail) ; le lien partagé est la fiche de l'événement sur tiers-lieux.org.

Pour les administrateurs connectés, une ligne « créer sur Facebook » ouvre le formulaire de création et propose de copier le titre, la date et la description (avec lieu et lien) en un clic chacun : Facebook n'autorise plus ni la création automatique ni le pré-remplissage par lien.

Sous chaque événement, des boutons discrets « Partager », « Ajouter à mon agenda » (et « Facebook (admin) » pour les administrateurs) ouvrent chacun une petite fenêtre avec les choix.

Le bouton « Je participe » mène à la fiche de l'événement sur tiers-lieux.org, après une petite fenêtre rappelant qu'il faut un compte tiers-lieux.org et être connecté. Le lien ouvre la fiche avec `?participer=1` : la fenêtre de confirmation de participation s'ouvre directement (connexion requise).

En bas de liste, dans le prolongement du trait vertical, « + Ajouter un événement à <nom du lieu> » ouvre une petite fenêtre expliquant que chacun peut proposer des événements (compte tiers-lieux.org requis). Son lien ouvre la fiche du lieu avec `?ajouter=event` : le formulaire de création s'ouvre directement pour les personnes autorisées.

## OpenAgenda (administrateurs)

Dans Réglages → Événements Tiers-Lieux, renseignez l'UID de l'agenda, l'UID du lieu et votre clé API secrète OpenAgenda. Le bouton « Partager » propose alors, aux administrateurs seulement, « OpenAgenda (admin) » : l'événement est envoyé via l'API (création, ou mise à jour s'il a déjà été envoyé, grâce à un identifiant externe). Titre, description (avec le lien vers la fiche), horaires et image sont transmis. La clé reste côté serveur.

## Plusieurs lieux regroupés

Dans Réglages → Événements Tiers-Lieux, la section « Autres tiers-lieux » permet d'ajouter d'autres lieux (recherche par nom). Par défaut, `[tiers_lieux_events]` n'affiche que le lieu principal. Avec `[tiers_lieux_events groupe="oui"]`, il affiche ensemble, par ordre de date, les événements du lieu principal et de ces lieux ; chaque événement indique son lieu, et son adresse d'agenda est celle de son lieu. Un événement coorganisé par plusieurs lieux n'apparaît qu'une fois.

- `[tiers_lieux_events id="IDENTIFIANT"]` : un seul lieu précis.
- `[tiers_lieux_events ids="ID1,ID2"]` : une sélection de lieux.

« Ajouter un événement à… » et l'envoi OpenAgenda concernent le lieu principal (OpenAgenda retrouve cependant l'événement parmi tous les lieux).
