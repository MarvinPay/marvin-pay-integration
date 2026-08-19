# Extension WordPress

> 🇬🇧 [English version](12-wordpress.md)

L'extension WordPress officielle de Marvin Pay transforme n'importe quel site
WordPress en site acceptant le mobile money en quelques minutes — avec ou sans
WooCommerce.

**Téléchargement :** récupérez `marvinpay-wordpress-<version>.zip` depuis la
[page Plugins](https://app.marvincorporate.co/plugins), ou construisez-la depuis
ce dépôt : `bash wordpress/build.sh` → `wordpress/dist/`.

## Installation et configuration

1. Admin WordPress → Extensions → Ajouter → Téléverser une extension →
   sélectionnez le zip → Activer.
2. Réglages → Marvin Pay :
   - **Mode** — Production, ou Test (l'URL de base et les identifiants de test
     sont fournis par le support Marvin Pay ; voir
     [Tests & Sandbox](11-testing-and-sandbox.md)).
   - **Clé API** — depuis votre portail marchand (envoyée en `X-API-KEY`).
   - **Pays** — l'un de CM, GA, CI, SN, BJ, TG, ML. La devise suit
     automatiquement le pays ([devise et pays vont toujours de
     pair](10-reference.md#currencies--countries)).
3. Copiez l'**URL de webhook** affichée sur cette page dans votre portail
   marchand (Compte → URL de webhook), et définissez si possible un **secret de
   webhook** des deux côtés pour recevoir des livraisons signées
   ([Webhooks](08-webhooks.md)).
4. Cliquez sur **Tester la connexion** — la liste des opérateurs de votre pays
   doit s'afficher.

## Widgets (shortcodes et blocs Gutenberg)

| Widget | Shortcode | Bloc |
|---|---|---|
| Bouton de paiement à montant fixe | `[marvinpay_button amount="5000" description="Consultation" button_text="Payer"]` | Bouton Marvin Pay |
| Don / montant libre | `[marvinpay_donation presets="1000,5000,10000" min="500" max="100000"]` | Don Marvin Pay |
| Formulaire de paiement intégré | `[marvinpay_form amount="" description="" show_name="1" show_email="1"]` | Formulaire Marvin Pay |
| Statut de paiement / reçu | `[marvinpay_status]` | Statut Marvin Pay |

Les montants sont des entiers, de 100 à 500 000 ([règles de
montant](10-reference.md#amount-rules)). Le widget de statut lit le paramètre
d'URL `?mp_ref=<référence>` — placez-le sur la page configurée comme « page de
succès » de l'extension.

## WooCommerce

Activez **Marvin Pay** dans WooCommerce → Réglages → Paiements. Le checkout
classique et le checkout en blocs sont pris en charge. La devise de la boutique
doit être celle du pays de votre compte (XAF ou XOF) et les totaux de commande
doivent être des montants entiers. Après la commande, le client confirme le
paiement sur son téléphone depuis la page de confirmation ; la commande passe
ensuite en *En cours* (succès) ou *Échouée*.

## Comment les résultats sont confirmés

L'extension suit le contrat d'intégration de bout en bout :

- chaque encaissement porte une `X-Idempotency-Key`
  ([Idempotence](06-idempotency.md)) ;
- le navigateur interroge le statut selon le calendrier du contrat — 5 s
  d'abord, backoff ×2 plafonné à 60 s, budget de 10 minutes
  ([Statut de transaction](05-transaction-status.md)) ;
- les webhooks sont vérifiés (HMAC-SHA256) et **toujours reconfirmés** auprès
  de l'API de statut avant toute action ([Webhooks](08-webhooks.md)) ;
- une tâche WP-Cron re-vérifie les transactions en attente toutes les
  5 minutes pendant 24 h : un résultat n'est jamais perdu, même si le payeur
  ferme son onglet.

## Mise en garde — cache de pages

Les formulaires de paiement contiennent un nonce et une configuration signée.
Excluez les pages contenant des widgets Marvin Pay de tout cache de page
complet, sinon les payeurs peuvent voir « ce formulaire de paiement a expiré ».

## Mise en garde — limitation de débit et proxys

L'extension limite les initiations de paiement par IP cliente (5 par
10 minutes) à partir du `REMOTE_ADDR` du serveur. Derrière un proxy inverse ou
un CDN (Cloudflare, etc.), tous les visiteurs partagent l'IP du proxy sauf si
votre serveur rétablit l'adresse cliente réelle (par ex. `mod_remoteip` /
`set_real_ip_from`) — configurez-le, sous peine de limiter des acheteurs
légitimes sur les boutiques à fort trafic. Les clients mobile money sont par
ailleurs souvent derrière du NAT opérateur : plusieurs acheteurs distincts
peuvent légitimement partager une même IP.
