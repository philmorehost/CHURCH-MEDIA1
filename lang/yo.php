<?php
declare(strict_types=1);

/**
 * Yorùbá — a deliberately partial catalogue.
 *
 * **These strings are a first pass and have not been reviewed by a native speaker.** They are here so
 * that the mechanism can be proved against a real second language rather than against a fake one, and
 * because the strings below are the ones with an unambiguous everyday meaning. Anything not listed here
 * falls back to English, so a gap is English — never blank, never wrong.
 *
 * That is the whole point of the design, and it is why a partial file like this one is a reasonable thing
 * to ship: a volunteer can translate eleven strings today and eleven more next month, and the site is
 * correct at every step. `php cli/lang_check.php` lists what is left, and never treats a missing
 * translation as a failure.
 *
 * What is deliberately absent, and why:
 *
 *  - `nav.prayer_wall` is translated as "Àdúrà", which is *prayer*, not *prayer wall*. There is no
 *    settled Yoruba phrase for the wall as a feature, and a wrong phrase is worse than an English one, so
 *    the shorter accurate word is used and the rest of the label stays English.
 *  - Colloquial or idiomatic labels (`footer.get_updates`, `footer.no_service_times`) are left out
 *    entirely rather than rendered word-for-word.
 *
 * Diacritics are part of the spelling: `Ilé`, not `Ile`. If a font or form mangles them, the file is
 * being written in the wrong encoding — it must stay UTF-8 without a BOM, because a BOM before `<?php`
 * is sent to the browser as output.
 */
return [
    '__name' => 'Yorùbá',

    // Not yet offered to visitors in the footer switcher — see `Lang::offered()`. A church can still
    // choose Yorùbá as its own default language on /admin/branding, which is a deliberate act by somebody
    // who knows what the site will show. Flip this to true once the catalogue has been reviewed.
    '__offered' => false,

    'nav.home'           => 'Ilé',
    'nav.sermons'        => 'Ìwàásù',
    'nav.bible'          => 'Bíbélì Mímọ́',
    'nav.events'         => 'Ìṣẹ̀lẹ̀',
    'nav.prayer_wall'    => 'Àdúrà',
    'nav.about'          => 'Nípa Wa',
    'nav.sign_in'        => 'Wọlé',
    'nav.register'       => 'Forúkọsílẹ̀',

    'footer.search'      => 'Wá',

    'lang.label'         => 'Èdè',
];
