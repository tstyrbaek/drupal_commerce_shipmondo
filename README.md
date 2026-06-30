# Commerce Shipmondo

Drupal Commerce-modul der opretter forsendelseslabels i [Shipmondo](https://www.shipmondo.com) ud fra webshop-ordrer via API v3.

Modulet mapper Commerce-forsendelser til Shipmondo-payloads, opretter labels manuelt fra ordresiden, gemmer PDF'en lokalt og synkroniserer trackingnummer og sporingslink tilbage til forsendelsen. Valgfrit undermodul tilføjer pakkeshop-valg i checkout.

## Krav

- Drupal 10 eller 11
- [Drupal Commerce](https://www.drupal.org/project/commerce) (`commerce_order`)
- [Commerce Shipping](https://www.drupal.org/project/commerce_shipping) — forsendelser på ordretypen
- [Physical](https://www.drupal.org/project/physical) — vægt på produktvariationer
- [Profile](https://www.drupal.org/project/profile) og [Address](https://www.drupal.org/project/address) — leveringsadresse
- [Key](https://www.drupal.org/project/key) — sikker opbevaring af API-hemmeligheder
- Shipmondo-konto med API-bruger og API-nøgle ([kontoindstillinger](https://app.shipmondo.com/))

## Installation

### Via Composer (anbefalet)

Tilføj git-repository og pakken i projektets `composer.json`:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/tstyrbaek/drupal_commerce_shipmondo.git"
    }
  ],
  "require": {
    "tstyrbaek/commerce_shipmondo": "^1.0"
  },
  "extra": {
    "installer-paths": {
      "web/modules/custom/{$name}": [
        "type:drupal-custom-module"
      ]
    }
  }
}
```

Tilpas stien `web/modules/custom/{$name}` til dit projekts docroot (fx `docroot/modules/custom/{$name}`).

Installer og aktivér:

```bash
composer require tstyrbaek/commerce_shipmondo:^1.0
drush en commerce_shipping physical commerce_shipmondo -y
drush cr
```

Indtil der er udgivet en versionstag (fx `1.0.0`) i git, kan du i stedet bruge:

```bash
composer require tstyrbaek/commerce_shipmondo:dev-main
```

### Manuel installation

1. Placér modulet i `web/modules/custom/commerce_shipmondo`.
2. Aktivér afhængighederne og modulet:

```bash
drush en commerce_shipping physical commerce_shipmondo -y
drush cr
```

3. Giv relevante roller tilladelsen **Create Commerce Shipmondo labels**.

### Pakkeshop i checkout (valgfrit)

Aktivér undermodulet `commerce_shipmondo_checkout` for at lade kunder vælge pakkeshop under checkout:

```bash
drush en commerce_shipmondo_checkout -y
drush cr
```

Undermodulet tilføjer feltet `shipmondo_service_point` på ordre og forsendelse og en checkout-pane **Shipmondo service point**.

## Opsætning i Shipmondo

Du skal have følgende fra Shipmondo:

| Oplysning | Hvor findes den |
|-----------|-----------------|
| **API user** | [Shipmondo kontoindstillinger](https://app.shipmondo.com/) → API |
| **API key** | Samme sted som API user |
| **Frontend key** (valgfri) | Til Shipping Module API / pakkeshop-opslag i checkout |

Til test: brug sandbox-credentials fra [Shipmondo sandbox](https://sandbox.shipmondo.com/) og slå **Use Shipmondo Sandbox API** til i modulets indstillinger.

Opret nøgler i Key-modulet (Authentication type) og vælg dem i modulets konfiguration.

## Konfiguration

Gå til **Commerce → Configuration → Shipmondo** (`/admin/commerce/config/shipmondo`).

### API credentials

- **API user** og **API key** — påkrævet
- **Frontend key** — valgfri; bruges til pakkeshop-opslag i checkout. Hvis tom, bruges API user/key med Basic Auth
- **Use Shipmondo Sandbox API** — sender requests til `sandbox.shipmondo.com`
- **Own carrier agreement** — aktiver hvis I bruger egen transportøraftale
- **Default service codes** — services der tilføjes til alle labels (standard: `EMAIL_NT`)
- **Label format** — A4 PDF, 10×19 cm PDF/ZPL, Compact PDF/ZPL
- **Default receiver country** — ISO-landekode til indlæsning af produkter og services i admin (f.eks. `DK`)

### Afsenderadresse

Udfyld navn, adresse, postnummer, by og landekode. Afsenderen sendes med hver label-oprettelse og skal være komplet.

### Modtager-kontaktfelter

Valgfri maskinnavne for telefon/e-mail på leveringsprofilen. Tom = automatisk detektion (f.eks. `field_phone_number`, `field_telefonnummer`, `field_email`).

### Fragtmetoder

Redigér hver **Commerce shipping method** og udfyld sektionen **Shipmondo**:

- **Product** — Shipmondo-produktkode (f.eks. `GLSDK_HD`, `PDK_MH`)
- **Service codes** — valgfrie ekstraservices (f.eks. `EMAIL_NT`, `SMS_NT`)

Når API-credentials er konfigureret, indlæses tilgængelige produkter og services automatisk fra Shipmondo. Påkrævede services for det valgte produkt merges automatisk ved label-oprettelse.

Sørg også for at ordretypen har forsendelser aktiveret (Commerce Shipping order type settings).

## Brug

### Opret label fra en ordre

1. Gennemfør en testordre med fragtmetode, leveringsadresse og produkter med vægt.
2. Åbn ordren i admin.
3. Klik **Create Shipmondo label** under ordrens handlinger (eller på forsendelsens side).
4. Gennemgå opsummeringen på bekræftelsessiden, og bekræft oprettelsen.
5. Download PDF-labelen — den åbnes automatisk efter oprettelse.

En forsendelse kan kun få én Shipmondo-label. Når labelen findes, vises **Download Shipmondo label** i stedet.

Modulet:

- Validerer fragtmetode, adresse, vægt og service codes
- Opretter forsendelsen i Shipmondo via API v3
- Gemmer label-PDF som fil og metadata på forsendelsen
- Sætter trackingnummer på forsendelsen og bygger sporingslink via `track.shipmondo.com`

### Label på forsendelsessiden

På forsendelsens redigeringsside vises knappen **Create Shipmondo label** / **Shipmondo label** ved siden af state transitions.

### Tracking

Modulet erstatter Commerce's `commerce_tracking_link`-formatter med en Shipmondo-specifik version, der linker til `track.shipmondo.com/{carrier}/{tracking}` når carrier og trackingnummer er kendt.

På ordresiden vises sektionen **Shipmondo labels** med shipment-ID og tracking for forsendelser med label.

## Pakkeshop i checkout

Med `commerce_shipmondo_checkout` aktiveret:

1. Checkout-pane **Shipmondo service point** tilføjes automatisk til checkout flows ved installation (trin *Order information*). Eksisterende sites: kør `drush updb -y && drush cr`.
2. Map hver fragtmetode til en Shipmondo carrier-kode i pane-konfigurationen.
3. Kunden vælger pakkeshop via React-komponenten (kort + liste).
4. Valget gemmes på ordren og kopieres til matchende forsendelser.
5. Ved label-oprettelse sendes `service_point_id` med i Shipmondo-payloaden.

Pakkeshop-data gemmes som JSON i feltet `shipmondo_service_point` og vises på forsendelsen (ikke på ordrevisningen).

### Byg React-komponenten

Ved ændringer i `modules/commerce_shipmondo_checkout/react/`:

```bash
cd modules/commerce_shipmondo_checkout/react
npm install
npm run build
```

Build kopierer UMD-bundlen til `modules/commerce_shipmondo_checkout/js/`.

## Hvad sendes til Shipmondo?

| Felt | Kilde |
|------|-------|
| Product code | Fragtmetodens Shipmondo-mapping |
| Service codes | Fragtmetode + globale defaults + API-påkrævede |
| Afsender (`parties`) | Modulets afsenderadresse |
| Modtager (`parties`) | Leveringsprofil + ordre-e-mail/telefon |
| Vægt | Forsendelsens vægt (gram) |
| Reference | `Order {ordrenummer}` |
| Service point | `service_point_id` fra checkout (hvis valgt) |
| Label format | Modulets indstilling |
| Own agreement | Modulets indstilling |

Modtageradressen kommer fra **leveringsprofilen** på forsendelsen — ikke faktureringsadressen.

## Service codes

| Kode | Krav |
|------|------|
| `EMAIL_NT` | Modtager skal have e-mail (profil, konfigureret felt eller ordre-e-mail) |
| `SMS_NT` | Modtager skal have telefonnummer på leveringsprofilen |

Service codes merges fra tre kilder: fragtmetode, globale defaults og Shipmondo API's påkrævede services for det valgte produkt.

## Tilladelser

| Tilladelse | Beskrivelse |
|------------|-------------|
| `administer commerce shipmondo` | Konfigurér API, afsender og globale indstillinger |
| `create commerce shipmondo labels` | Opret og download labels fra ordre/forsendelse |

## Data på forsendelsen

Modulet gemmer følgende i forsendelsens `data`-felt under nøglen `commerce_shipmondo`:

| Nøgle | Indhold |
|-------|---------|
| `shipment_id` | Shipmondo forsendelses-ID |
| `tracking_number` | Trackingnummer (`pkg_no`) |
| `carrier_code` | Transportørkode fra Shipmondo |
| `tracking_url` | Færdigbygget sporings-URL |
| `label_fid` | Fil-ID for gemt label-PDF |
| `created` | Unix-tidsstempel for oprettelse |

Derudover sættes `tracking_code` på forsendelsen, så Commerce's tracking-visning virker.

## Begrænsninger

- Labels oprettes **manuelt** fra admin — ingen automatisk oprettelse ved ordreafslutning
- Forsendelsen skal have vægt; produktvariationer skal have vægt konfigureret
- Kun én label pr. forsendelse
- Sandbox og produktion bruger separate credentials og miljøer
- Pakkeshop kræver undermodulet `commerce_shipmondo_checkout`

## Fejlfinding

- **Create Shipmondo label vises ikke** — tjek at forsendelsen har fragtmetode, leveringsprofil med adresse, vægt og Shipmondo product code på metoden
- **Shipment has no weight** — konfigurér vægt på produktvariationer og sørg for at forsendelsen har items med vægt
- **EMAIL_NT / SMS_NT errors** — tilføj e-mail/telefon på leveringsprofilen eller angiv feltmaskinnavne under *Receiver contact fields*
- **Forkert adresse på label** — tjek at forsendelsen bruger korrekt **leveringsprofil** (ikke faktureringsadresse)
- **Pakkeshop-label fejl** (`parties` and `service_point` cannot be combined) — modulet bruger `service_point_id` med `parties` per Shipmondo API v3; ryd cache efter modulopdatering
- **Forsendelse findes ikke i Shipmondo** — med sandbox slået til skal du kigge i [Shipmondo sandbox](https://sandbox.shipmondo.com/), ikke produktion
- **API-fejl** — verificér nøgler og tjek **Reports → Recent log messages** (kanal: `commerce_shipmondo`)

Logbeskeder skrives til kanalerne `commerce_shipmondo` og `commerce_shipmondo_checkout`.

## Services

| Service ID | Klasse |
|------------|--------|
| `commerce_shipmondo.api_client` | HTTP-klient mod Shipmondo REST API v3 |
| `commerce_shipmondo.shipping_method_mapping` | Gem/hent product code og service codes pr. fragtmetode |
| `commerce_shipmondo.order_shipment_mapper` | Map forsendelse til Shipmondo payload |
| `commerce_shipmondo.label_manager` | Opret label, gem PDF og metadata |
| `commerce_shipmondo.tracking_url_builder` | Byg sporings-URL'er |
| `commerce_shipmondo_checkout.service_point` | Pakkeshop-opslag via Shipping Module API |
| `commerce_shipmondo_checkout.service_point_sync` | Synk pakkeshop fra ordre til forsendelser |

## API-dokumentation

- [Opret forsendelse](https://shipmondo.dev/docs/api/create-shipment)
- [API specification v3](https://app.shipmondo.com/api/public/v3/specification)

## Licens

GPL-2.0-or-later
