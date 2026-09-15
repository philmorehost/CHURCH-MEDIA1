<?php
declare(strict_types=1);

/**
 * English — the source catalogue, and the one every other catalogue falls back to.
 *
 * Rules for this directory, all of them load-bearing:
 *
 * 1. **`__name` is the language's name in its own language.** A menu that says "Yoruba" to a Yoruba
 *    speaker is a menu they have to decode; "Yorùbá" is one they can read. So the value here is
 *    "English" and the Yoruba file says "Yorùbá", not "Yoruba".
 *
 * 2. **Every key here must exist here.** If a key only exists in another catalogue it renders as a
 *    dotted key for everyone reading English — `cli/lang_check.php` fails on that, because it is a
 *    mistake rather than a gap. A key that is in English and missing elsewhere is fine: the missing
 *    translation falls back to this line.
 *
 * 3. **Keys are the identity, values are disposable.** Renaming a key means renaming it in every file;
 *    changing a value means nothing needs to move. So the keys are dot-namespaced by where they appear
 *    (`nav.`, `footer.`) rather than numbered, and the value is free to become "Watch Live" or
 *    "Streaming now" without touching any code.
 *
 * 4. **Only strings that are actually wired up live here.** A catalogue full of keys no screen asks for
 *    looks like more coverage than exists, which is worse than a short file.
 *
 * Deliberately NOT translated:
 *
 *  - Church data — the site name, the tagline, service times, unit names, CMS page titles. A language
 *    file is for the words *this application* says, not the words a church types in.
 *  - `Unit::pluralFor(Unit::leafType())` in the nav. That is the church's own vocabulary for its
 *    structure (Parishes, Zones, …) and it comes from the database, so translating it here would be the
 *    application overruling the church.
 */
return [
    '__name' => 'English',

    // The header navigation, grouped into dropdowns — see the $navTree comment in
    // views/partials/layout-open.php for why.
    'nav.home'               => 'Home',
    'nav.media'              => 'Media',
    'nav.video_feed'         => 'Video Feed',
    'nav.media_gallery'      => 'Media Gallery',
    'nav.watch_live'         => 'Watch Live',
    'nav.word'               => 'The Word',
    'nav.sermons'            => 'Sermons',
    'nav.bible'              => 'Holy Bible',
    'nav.devotional'         => 'Daily Devotional',
    'nav.community'          => 'Community',
    'nav.events'             => 'Events',
    'nav.testimonies'        => 'Testimonies',
    'nav.prayer_wall'        => 'Prayer Wall',
    'nav.connect'            => 'Connect',
    'nav.about'              => 'About Us',
    'nav.contact'            => 'Contact',
    'nav.advertise'          => 'Advertise With Us',
    'nav.register'           => 'Register',
    'nav.sign_in'            => 'Sign In',
    'nav.live'               => 'LIVE',
    'nav.menu'               => 'Menu',
    'nav.show_menu'          => 'Show :label menu',

    // The footer.
    'footer.explore'         => 'Explore',
    'footer.media_feed'      => 'Media Feed',
    'footer.events'          => 'Events',
    'footer.sermons'         => 'Sermons',
    'footer.watch_live'      => 'Watch Live',
    'footer.prayer_wall'     => 'Prayer Wall',
    'footer.app_features'    => 'App Features',
    'footer.connect'         => 'Connect',
    'footer.about'           => 'About Us',
    'footer.contact'         => 'Contact',
    'footer.give'            => 'Give',
    'footer.advertise'       => 'Advertise with Us',
    'footer.publisher_portal' => 'Publisher Portal',
    'footer.service_times'   => 'Service Times',
    'footer.no_service_times' => 'Check back soon for our schedule.',
    'footer.get_updates'     => 'Get updates by email',
    'footer.join'            => 'Join',
    'footer.rights'          => '© :year :church. All rights reserved.',
    'footer.search'          => 'Search',
    'footer.privacy'         => 'Privacy Policy',
    'footer.admin'           => 'Admin',

    // The language switcher itself.
    'lang.label'             => 'Language',
];
