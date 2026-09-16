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
    'nav.news'               => 'News',
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
    // Two different registrations, and the labels have to say which is which. /register creates
    // a church's own admin account; /member/register is a plain reader account on this site.
    'nav.register'           => 'Register Church',
    'nav.member_register'    => 'Member Register',
    'nav.sign_in'            => 'Member Signin',
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

    // News & blog — /news, /news/category/{slug} and /news/{slug}.
    //
    // `news.intro` takes the church name as a placeholder rather than being concatenated in the code,
    // because "Announcements, stories and updates from X" is a different word order in most languages.
    // `news.min_read` is one string rather than "min" and "read" with a number between them: the number's
    // position moves, and a language may not use a plural form at all.
    'news.eyebrow'           => 'News & Blog',
    'news.title'             => 'News & Updates',
    'news.intro'             => 'Announcements, stories and updates from :church.',
    'news.all_categories'    => 'All',
    'news.search_label'      => 'Search news',
    'news.search_placeholder' => 'Search stories…',
    'news.search_button'     => 'Search',
    'news.lead_badge'        => 'Top story',
    'news.read_more'         => 'Read story',
    'news.min_read'          => ':count min read',
    'news.by'                => 'By :name',
    'news.updated'           => 'Updated :date',
    'news.share'             => 'Share',
    'news.share_url'         => 'Link to this story',
    'news.related'           => 'More reading',
    'news.older'             => 'Older',
    'news.newer'             => 'Newer',
    'news.breadcrumb'        => 'Breadcrumb',
    'news.pagination'        => 'News pages',
    'news.empty'             => 'Nothing has been published yet. Please check back soon.',
    'news.no_results'        => 'No stories match “:q”.',

    // The pages a visitor sees when something has gone wrong — a dead link, too many requests, no
    // connection. They matter more than most: this is where somebody decides whether the site is broken
    // or whether they are.
    'error.404.title'        => "We couldn't find that page",
    'error.404.body'         => "The page you're looking for may have moved or no longer exists.",
    'error.429.title'        => 'Please slow down',
    'error.429.body'         => 'You have sent a lot of requests in a short time. Please wait a minute and try again — nothing you entered has been lost.',
    // The same message again, in one sentence, for `RateLimiter`'s dependency-free fallback — the branch
    // that runs when the layout itself cannot be rendered. See the note in core/RateLimiter.php.
    'error.429.body_plain'   => 'You have made a lot of requests in a short time. Please wait a minute and try again.',
    'error.offline.eyebrow'  => 'Offline',
    'error.offline.title'    => 'You are offline',
    // Two whole sentences rather than one sentence with the church name swapped in, because a translator
    // needs a complete sentence: word order, and whether the name takes a particle, differs by language.
    'error.offline.body'     => 'This device cannot reach :church at the moment. Pages you have already opened will still work — the app keeps a copy of them on the device. The live stream, giving, and anything else that needs the server will work again once you are back on a connection.',
    'error.offline.body_no_church' => 'This device cannot reach the site at the moment. Pages you have already opened will still work — the app keeps a copy of them on the device. The live stream, giving, and anything else that needs the server will work again once you are back on a connection.',
    'error.offline.retry'    => 'Try the home page again',

    // Shared by the error pages and the recovery pages below.
    'common.back_home'       => 'Back Home',
    'common.browse_feed'     => 'Browse the Feed',
    'common.try_again'       => 'Try Again',

    // Recovering access: /forgot-password and /unblock. Read by an admin who has been locked out, which is
    // the worst moment to hand somebody a sentence in a language they did not choose.
    'auth.field.account'     => 'Username or Email',
    'auth.field.account_hint' => 'your.username or email',
    'auth.field.password'    => 'Password',
    'auth.field.pin'         => 'Security Unblock PIN (4 to 6 digits)',
    'auth.field.pin_hint'    => 'Your secret Security PIN',
    'auth.field.new_password' => 'New Password (10+ chars)',
    'auth.field.new_password_hint' => 'New password',
    'auth.field.confirm_password' => 'Confirm New Password',
    'auth.field.confirm_hint' => 'Repeat new password',
    'auth.field.otp'         => '6-Digit OTP Code',
    'auth.field.otp_hint'    => 'e.g. 123456',

    'auth.forgot.page_title' => 'Forgot Password',
    'auth.forgot.title'      => 'Reset Admin Password',
    'auth.forgot.sub_request' => 'Enter your username or email address. We will send a 6-digit OTP code to your primary and backup email address.',
    'auth.forgot.sub_pin'    => 'Enter your username or email and your secret Security Unblock PIN to reset your password without email.',
    'auth.forgot.sub_otp'    => 'Enter the 6-digit OTP sent to your email, then choose a new password.',
    'auth.forgot.send_otp'   => 'Send OTP Code',
    'auth.forgot.use_pin'    => 'Reset Password using Security PIN',
    'auth.forgot.reset_restore' => 'Reset Password & Restore Account',
    'auth.forgot.or_pin'     => '🔒 Or Reset Password using Security Unblock PIN →',
    'auth.forgot.no_email'   => "🔒 Didn't receive email? Reset using Security PIN →",

    'auth.unblock.title'     => 'Unblock Security Access',
    'auth.unblock.sub'       => 'Blocked by security or account suspended? Enter your credentials and your secret Unblock PIN to restore access immediately.',
    'auth.unblock.submit'    => 'Restore Access & Unblock IP',
    'auth.unblock.proceed'   => 'Proceed to Admin Login →',
    'auth.unblock.done'      => '✅ Account and IP successfully unblocked! You can now log in.',
    'auth.back_to_login'     => '← Back to Admin Login',
    'auth.return_to_login'   => '← Return to Login',

    // What the handlers above say when something is wrong. Every one of these is shown to somebody who is
    // already stuck, so they are translated with the screens rather than left as loose English.
    'auth.err.account_required' => 'Please enter your username or email address.',
    'auth.err.account_not_found' => 'No admin account found with that username or email.',
    'auth.err.fields_required' => 'Username, Security Unblock PIN, and New Password are required.',
    'auth.err.unblock_fields_required' => 'Username, password, and Security Unblock PIN are all required.',
    'auth.err.password_short' => 'Password must be at least 10 characters long.',
    'auth.err.password_mismatch' => 'Passwords do not match.',
    'auth.err.account_missing' => 'Account not found.',
    'auth.err.pin_wrong'     => 'Incorrect Security Unblock PIN.',
    'auth.err.credentials_invalid' => 'Invalid username or password.',
    'auth.err.session_expired' => 'Session expired — please request a new OTP.',
    'auth.err.otp_invalid'   => 'Invalid OTP code.',
    'auth.err.otp_expired'   => 'OTP code has expired — please request a new one.',

    'auth.flash.smtp_missing' => 'Note: Email SMTP is currently not configured on this server. If you do not receive the email, you can use your secret Security Unblock PIN below to reset your password instantly!',
    'auth.flash.otp_sent'    => 'A 6-digit OTP code has been sent to your primary and backup email address.',
    'auth.flash.reset_by_pin' => 'Your password has been reset using your Security PIN! Please sign in.',
    'auth.flash.reset_done'  => 'Your password has been reset and your account restored! Please sign in.',
    'auth.flash.unblock_done' => 'Security unblock successful! Your IP and account have been restored. You may now log in.',

    // The language switcher itself.
    'lang.label'             => 'Language',
];
