# Audit qualité — sylius-webhook-outbox-plugin

> Généré le 2026-10-14 · Branche `chore/quality-tooling` · Outillage claude-craft (ECS, PHPStan, Rector, PHPUnit) exécuté via Docker `ghcr.io/sylius/sylius-php:8.4-alpine`. Revue qualitative par l'orchestrateur (lecture directe des fichiers sources).

## 1. Audit factuel (outils)

| Contrôle | Commande | Résultat |
|---|---|---|
| Style (ECS) | `vendor/bin/ecs check` | ⚠️ 1 erreur fixable (`\SIGTERM` → `SIGTERM`) |
| Analyse statique (PHPStan **level max**) | `vendor/bin/phpstan analyse` | ✅ 0 erreur |
| Tests unitaires | `vendor/bin/phpunit --testsuite=unit` | ✅ 80 tests, 1 384 assertions |
| Validation composer | `composer validate --strict` | ✅ valide |
| Modernisation (Rector, dry-run) | `vendor/bin/rector process --dry-run` | ⚠️ 42 fichiers avec suggestions |
| Tests `non-unit` (functional/integration) | — | ⏭️ non exécutés localement (DB + app Sylius requise) — couverts par le CI |
| Vulnérabilités dépendances | `composer audit` | ⚠️ 2 packages abandonnés (behat/transliterator, php-http/message-factory — transitifs Sylius) |

**ECS** : 1 seule violation, `NativeConstantInvocationFixer` dans `DispatchWebhooksCommand:57` — `\SIGTERM`/`\SIGINT` utilisés sans escape après `\defined()`. Lancer `vendor/bin/ecs check --fix` pour corriger.

**Rector** vient d'être ajouté (`rector/rector ^2.0`, `rector.php`, cibles `make rector` / `make rector-fix`). Les 42 fichiers signalés relèvent principalement de `FlipTypeControlToUseExclusiveTypeRector` — suggestions de modernisation non bloquantes. Lancer `make rector-fix` puis relancer ECS/PHPStan/tests.

**Packages abandonnés** : dépendances transitives de `sylius/sylius` — aucune action requise côté plugin.

## 2. Audit qualitatif (revue par l'orchestrateur)

| Dimension | Score | Synthèse |
|---|---|---|
| PHP / Clean Archi / SOLID | **9/10** | Typage strict partout, `final`, `#[\SensitiveParameter]`, value objects immuables (`DeliveryAttempt`), couches bien séparées. |
| Intégration Symfony / Sylius | **8/10** | Pattern outbox correct (onFlush/postPersist), worker SKIP LOCKED, signal handling, EntityManager recovery. |
| Sécurité (OWASP 2025) | **9/10** | SSRF bloqué à la résolution DNS (CVE-2024-50342), HMAC-SHA256 Standard Webhooks, secret one-time, UrlRedactor systématique. |

### Points forts

- `declare(strict_types=1)` + classes `final` sur tous les fichiers de `src/` sauf `WebhookEndpoint` (pattern d'extension Sylius, intentionnel).
- `#[\SensitiveParameter]` sur tous les paramètres `$secret` de `WebhookSigner` et `WebhookEndpoint::setSecret()`, qui empêche la fuite dans les stack traces.
- `EndpointHttpClientFactory` : `NoPrivateNetworkHttpClient` (résolution DNS incluse, CVE-2024-50342), `proxy: null`, `no_proxy: '*'`, `max_redirects: 0` — toutes les surfaces SSRF couvertes.
- `WebhookSigner` : Standard Webhooks v1, `hash_equals` (comparaison en temps constant), tolérance replay 300 s.
- `SecretRevealer` : en-têtes `Cache-Control: no-store, private` + `Pragma: no-cache` ; secret transmis une seule fois, jamais en flash ni en session.
- `WebhookEndpoint::__debugInfo()` : redacte le secret et l'URL dans `var_dump()` / contextes d'exception.
- `OutboxCaptureListener` : `catch \Throwable` avec log `critical` et poursuite du flush — un webhook ne casse jamais un checkout.
- `DeliveryClaimer` : two-step (lecture candidate + `SKIP LOCKED` sur PK unique), fallback `FOR UPDATE` pour vieilles versions MySQL, réconciliation endpoint-état toutes les 30 s.
- `WebhookDispatcher` : re-lecture sous verrou avant enregistrement (`refresh($delivery)`) — les anonymisations concurrentes gagnent toujours.
- `OutboxRecorder.nextId()` : UUIDv7 strictement croissant dans le process (garantit l'ordre des événements d'un même flush).
- `PayloadRedactor` + `CustomerWebhookDataAnonymizer` : suppression des données personnelles pour le droit à l'effacement RGPD.
- `OrderPayloadNormalizer` : token de commande et IP client explicitement exclus des payloads.

## 3. Recommandations priorisées

### 🟠 Majeur

1. **Corriger la violation ECS immédiatement** (`src/Command/DispatchWebhooksCommand.php:57`). `\SIGTERM` et `\SIGINT` utilisés dans le tableau après le `\defined()` : `NativeConstantInvocationFixer` impose `SIGTERM`/`SIGINT` sans backslash. → `vendor/bin/ecs check --fix` puis commit.

2. **Appliquer les 42 suggestions Rector** (`make rector-fix`). La règle majoritaire `FlipTypeControlToUseExclusiveTypeRector` remplace des conditions redondantes par des types exclusifs, améliorant la lisibilité et activant des optimisations OPcache. Vérifier la suite complète après application (PHPStan + tests).

3. **Documenter le risque de `allowHttp: true`** (`src/Http/EndpointHttpClientFactory.php:22`, `src/DependencyInjection/Configuration.php`). Cette option est nécessaire pour les tests locaux mais expose à des webhooks en clair si activée en production. → Ajouter un warning dans le README et dans le message de configuration Symfony (`Configuration.php`) ; interdire la combinaison `allowHttp: true` + `kernel.environment = prod` via un `PrependExtensionInterface` ou un `CompilerPassInterface`.

### 🟡 Mineur

4. **`WebhookEndpoint` non `final`** (`src/Entity/WebhookEndpoint.php:11`). L'override de `getSecret()` dans une sous-classe pourrait contourner `__debugInfo()` et exposer le secret. → Acceptable dans le pattern d'extension Sylius (Doctrine discriminator map), mais documenter que les surcharges doivent conserver la redaction.

5. **État mutable partagé dans `DeliveryClaimer`** (`src/Delivery/DeliveryClaimer.php:38-39`). `$skipLockedSupported` et `$lastReconciliation` sont mémorisés sur l'instance. Si Symfony réutilise le service dans plusieurs requêtes (worker --loop), c'est intentionnel. En contexte web (edge case), cela lie le résultat de `isSkipLockedSupported()` à la première connexion de la vie du processus. → OK pour le cas d'usage CLI, à documenter.

6. **`EnvelopeFactory` purement statique** (`src/Delivery/EnvelopeFactory.php`). Rend les tests plus difficiles à mocker et contourne DIP. → Faible impact, mais convertir en service injectable permettrait d'injecter un normalizer externe (ex. chiffrement de payload).

7. **`DispatchWebhooksCommand` accepte `--sleep 0`** (`src/Command/DispatchWebhooksCommand.php:72`). `max(0.1, ...)` limite à 100 ms minimum — correct — mais le commentaire dans la doc n'en parle pas. → Mentionner dans `--help`.

8. **`tracking` dans `PayloadRedactor.PERSONAL_KEYS`** (`src/Maintenance/PayloadRedactor.php:27`) mais aussi dans `OrderPayloadNormalizer::shipment()` (`src/Payload/OrderPayloadNormalizer.php:127`). Le numéro de suivi est envoyé dans le webhook normal (attendu par les ERP) et redacté uniquement lors de l'anonymisation GDPR — comportement correct mais à documenter explicitement dans le README comme donnée personnelle exposée.

### ℹ️ Pour information (choix de conception assumés)

- Pas de Symfony Messenger : le pattern outbox est implémenté en SQL pur (SKIP LOCKED + lease) pour éviter toute dépendance à un transport externe — cohérent avec l'objectif d'autonomie du plugin.
- `max_redirects: 0` et `no_proxy: '*'` : aucun redirect suivi, aucun proxy d'environnement — choix de sécurité délibéré documenté dans `EndpointHttpClientFactory`.
- `postPersist` DBAL pour résoudre les IDs différés : contourne la limitation Doctrine (les IDs des nouvelles entités ne sont pas disponibles dans `onFlush`) ; le `setOriginalEntityProperty` maintient la cohérence de l'UnitOfWork.
- Response body cappé à 2 048 octets avec normalisation UTF-8 : évite les payloads de réponse malveillants dans l'historique admin.

## 4. Verdict

Plugin **exceptionnel** pour un premier jet : outillage quasi au vert (PHPStan level max 0 erreur, 80 tests unitaires 1 384 assertions), architecture outbox correcte, modèle de sécurité très soigné (SSRF, HMAC, secret lifecycle, redaction). Les deux points orange — violation ECS et Rector — sont mécaniques et sans impact fonctionnel. Le seul risque applicatif à traiter avant release est la combinaison `allowHttp: true` en production, qui mérite une garde-fou au démarrage. Rien de bloquant.
