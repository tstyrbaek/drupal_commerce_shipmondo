const translations = {
  da: {
    title: 'Vælg pakkeshop',
    selectedTitle: 'Din pakkeshop',
    changePickupPoint: 'Skift pakkeshop',
    close: 'Luk',
    openingHours: 'Åbningstider',
    closed: 'Lukket',
    metersFromYou: 'meter fra dig',
    kmFromYou: 'km fra dig',
    loading: 'Henter pakkeshops...',
    noResults: 'Ingen pakkeshops fundet for dette postnummer.',
    error: 'Kunne ikke hente pakkeshops.',
    selectOnMap: 'Klik på kortet for at vælge',
  },
  en: {
    title: 'Select pickup point',
    selectedTitle: 'Your pickup point',
    changePickupPoint: 'Change pickup point',
    close: 'Close',
    openingHours: 'Opening hours',
    closed: 'Closed',
    metersFromYou: 'meters from you',
    kmFromYou: 'km from you',
    loading: 'Loading pickup points...',
    noResults: 'No pickup points found for this postal code.',
    error: 'Could not load pickup points.',
    selectOnMap: 'Click on the map to select',
  },
  de: {
    title: 'Paketshop auswählen',
    selectedTitle: 'Ihr Paketshop',
    changePickupPoint: 'Paketshop ändern',
    close: 'Schließen',
    openingHours: 'Öffnungszeiten',
    closed: 'Geschlossen',
    metersFromYou: 'Meter von Ihnen',
    kmFromYou: 'km von Ihnen',
    loading: 'Paketshops werden geladen...',
    noResults: 'Keine Paketshops für diese Postleitzahl gefunden.',
    error: 'Paketshops konnten nicht geladen werden.',
    selectOnMap: 'Klicken Sie auf die Karte zur Auswahl',
  },
  sv: {
    title: 'Välj ombud',
    selectedTitle: 'Ditt ombud',
    changePickupPoint: 'Byt ombud',
    close: 'Stäng',
    openingHours: 'Öppettider',
    closed: 'Stängt',
    metersFromYou: 'meter från dig',
    kmFromYou: 'km från dig',
    loading: 'Hämtar ombud...',
    noResults: 'Inga ombud hittades för detta postnummer.',
    error: 'Kunde inte hämta ombud.',
    selectOnMap: 'Klicka på kartan för att välja',
  },
  no: {
    title: 'Velg hentested',
    selectedTitle: 'Ditt hentested',
    changePickupPoint: 'Bytt hentested',
    close: 'Lukk',
    openingHours: 'Åpningstider',
    closed: 'Stengt',
    metersFromYou: 'meter fra deg',
    kmFromYou: 'km fra deg',
    loading: 'Henter hentesteder...',
    noResults: 'Ingen hentesteder funnet for dette postnummeret.',
    error: 'Kunne ikke hente hentesteder.',
    selectOnMap: 'Klikk på kartet for å velge',
  },
};

const SUPPORTED_LANGUAGES = ['da', 'en', 'de', 'sv', 'no'];

/**
 * Returns translated strings for the given language code.
 */
export function getTranslations(language = 'da') {
  const lang = SUPPORTED_LANGUAGES.includes(language) ? language : 'da';
  return translations[lang];
}
