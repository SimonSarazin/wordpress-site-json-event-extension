<?php
/**
 * Plugin Name: Événements Tiers-Lieux.org
 * Description: Affiche les événements saisis sur tiers-lieux.org pour un tiers-lieu, via le shortcode [tiers_lieux_events].
 * Version: 1.12.0
 * Author: La Plume à Loup
 * License: GPL-2.0-or-later
 * Text Domain: tiers-lieux-events
 */

if (!defined('ABSPATH')) {
    exit;
}

const TLE_API_BASE = 'https://www.communecter.org';
const TLE_SITE_BASE = 'https://www.tiers-lieux.org';

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/openagenda.php';

/**
 * Tiers-lieu choisi dans Réglages → Événements Tiers-Lieux.
 *
 * @return array{id:string,name:string,slug:string,place:string}
 */
function tle_org()
{
    $saved = get_option('tle_org');
    if (is_array($saved) && !empty($saved['id'])) {
        return $saved + ['name' => '', 'slug' => '', 'place' => ''];
    }
    return ['id' => '', 'name' => '', 'slug' => '', 'place' => ''];
}

/**
 * Autres tiers-lieux affichés avec le lieu principal (Réglages → Événements Tiers-Lieux).
 *
 * @return array<int,array{id:string,name:string,slug:string,place:string}>
 */
function tle_extra_orgs()
{
    $saved = get_option('tle_extra_orgs');
    $out = [];
    foreach (is_array($saved) ? $saved : [] as $o) {
        if (is_array($o) && !empty($o['id'])) {
            $out[] = $o + ['name' => '', 'slug' => '', 'place' => ''];
        }
    }
    return $out;
}

/**
 * Tous les lieux regroupés : le principal d'abord, puis les autres (sans doublon).
 */
function tle_orgs()
{
    $main = tle_org();
    $list = $main['id'] ? [$main] : [];
    foreach (tle_extra_orgs() as $o) {
        if ($o['id'] !== $main['id']) {
            $list[] = $o;
        }
    }
    return $list;
}

/**
 * Cherche un lieu connu par son identifiant ; sinon le décrit seulement par son identifiant.
 */
function tle_org_by_id($id)
{
    foreach (tle_orgs() as $o) {
        if ($o['id'] === $id) {
            return $o;
        }
    }
    return ['id' => $id, 'name' => '', 'slug' => '', 'place' => ''];
}

function tle_org_url($o)
{
    return $o['slug'] ? TLE_SITE_BASE . '/profil/' . rawurlencode($o['slug']) : TLE_SITE_BASE;
}

/**
 * Ligne « Ajouter un événement » avec sa fenêtre explicative.
 */
function tle_add_event_row($org_url, $org_name, $has_slug)
{
    if (!$has_slug) {
        return '';
    }
    wp_enqueue_script('tle-script', plugins_url('tle.js', __FILE__), [], '1.12.0', true);
    $name = esc_html($org_name ?: 'ce lieu');
    $add_url = add_query_arg('ajouter', 'event', $org_url . '/events');
    $row = '<details class="tle-pop tle-add"><summary>+ Ajouter un événement à ' . $name . '</summary><div class="tle-panel tle-note">'
        . '<p><strong>Chacun peut proposer des événements à ' . $name . '.</strong></p>'
        . '<p>Il faut un compte sur tiers-lieux.org (gratuit) et s’y connecter. Dans la page qui s’ouvre, cliquez en haut à droite sur « Créer », puis sur « Événement ». L’événement peut être validé avant d’apparaître ici.</p>'
        . '<a href="' . esc_url($add_url) . '" target="_blank" rel="noopener">Continuer vers tiers-lieux.org →</a>'
        . '</div></details>';
    return '<div class="tle-item tle-add-row"><div class="tle-side"></div><div class="tle-body"><div class="tle-cal tle-actions">' . $row . '</div></div></div>';
}

/**
 * Récupère les événements d'une organisation (mis en cache).
 *
 * @return array|WP_Error
 */
function tle_fetch_events($org_id, $cache_minutes)
{
    $key = 'tle_events_' . md5($org_id);
    $cached = get_transient($key);
    if (is_array($cached)) {
        return $cached;
    }

    $url = sprintf(
        '%s/co2/element/getdatadetail/type/organizations/id/%s/dataName/events',
        TLE_API_BASE,
        rawurlencode($org_id)
    );
    $response = wp_remote_get($url, ['timeout' => 15]);
    if (is_wp_error($response)) {
        return $response;
    }
    if (200 !== wp_remote_retrieve_response_code($response)) {
        return new WP_Error('tle_http', 'Réponse inattendue de tiers-lieux.org');
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($data)) {
        return new WP_Error('tle_json', 'Données invalides reçues de tiers-lieux.org');
    }

    $events = [];
    foreach ($data as $e) {
        if (is_array($e) && !empty($e['name']) && !empty($e['startDateSec'])) {
            $events[] = $e;
        }
    }
    set_transient($key, $events, max(1, (int) $cache_minutes) * MINUTE_IN_SECONDS);
    return $events;
}

function tle_image_url($path)
{
    if (!$path) {
        return '';
    }
    return 0 === strpos($path, 'http') ? $path : TLE_API_BASE . $path;
}

// Les heures de l'API sont en UTC ; on affiche toujours l'heure de Paris.
function tle_date($format, $ts)
{
    return wp_date($format, $ts, new DateTimeZone('Europe/Paris'));
}

function tle_format_when($start, $end)
{
    $day = 'j F Y';
    $time = 'H\hi';
    if (!$end || $end <= $start) {
        return tle_date("$day, $time", $start);
    }
    if (tle_date('Y-m-d', $start) === tle_date('Y-m-d', $end)) {
        return tle_date($day, $start) . ' · ' . tle_date($time, $start) . ' – ' . tle_date($time, $end);
    }
    return 'Du ' . tle_date($day, $start) . ' au ' . tle_date($day, $end);
}

function tle_format_time($start, $end)
{
    $time = 'H\hi';
    if (!$end || $end <= $start || tle_date('Y-m-d', $start) !== tle_date('Y-m-d', $end)) {
        return tle_date($time, $start);
    }
    return tle_date($time, $start) . ' – ' . tle_date($time, $end);
}

function tle_calendar_links($title, $desc, $place, $url, $start, $end)
{
    if (!$end || $end <= $start) {
        $end = $start + 2 * HOUR_IN_SECONDS;
    }
    $ics_date = function ($ts) {
        return gmdate('Ymd\THis\Z', $ts);
    };
    $details = trim($desc . ($url ? "\n" . $url : ''));

    $google = add_query_arg([
        'action'   => 'TEMPLATE',
        'text'     => $title,
        'dates'    => $ics_date($start) . '/' . $ics_date($end),
        'details'  => $details,
        'location' => $place,
    ], 'https://calendar.google.com/calendar/render');

    $outlook = add_query_arg([
        'path'    => '/calendar/action/compose',
        'rru'     => 'addevent',
        'subject' => $title,
        'startdt' => gmdate('Y-m-d\TH:i:s\Z', $start),
        'enddt'   => gmdate('Y-m-d\TH:i:s\Z', $end),
        'body'    => $details,
        'location' => $place,
    ], 'https://outlook.live.com/calendar/0/deeplink/compose');

    $esc = function ($t) {
        return str_replace(["\\", ';', ',', "\r\n", "\n"], ['\\\\', '\\;', '\\,', '\\n', '\\n'], $t);
    };
    $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Tiers-Lieux Events//FR\r\nBEGIN:VEVENT\r\n"
        . 'UID:' . md5($title . $start) . "@tiers-lieux.org\r\n"
        . 'DTSTAMP:' . $ics_date(time()) . "\r\n"
        . 'DTSTART:' . $ics_date($start) . "\r\n"
        . 'DTEND:' . $ics_date($end) . "\r\n"
        . 'SUMMARY:' . $esc($title) . "\r\n"
        . 'DESCRIPTION:' . $esc($details) . "\r\n"
        . 'LOCATION:' . $esc($place) . "\r\n"
        . "END:VEVENT\r\nEND:VCALENDAR\r\n";

    return [
        'google'  => $google,
        'outlook' => $outlook,
        'ics'     => 'data:text/calendar;charset=utf-8,' . rawurlencode($ics),
    ];
}

function tle_shortcode($atts)
{
    $atts = shortcode_atts([
        'id'     => '', // un seul lieu précis ; sinon, le lieu principal des réglages
        'groupe' => 'no', // yes : ajoute les autres lieux des réglages
        'ids'    => '', // plusieurs lieux précis, séparés par des virgules
        'show'   => 'upcoming', // upcoming | past | all
        'limit'  => 10,
        'cache'  => 30, // minutes
        'images' => 'yes',
        'place'  => '', // adresse pour l'agenda et les données structurées ; défaut : celle des réglages
        'empty'  => 'Aucun événement à venir pour le moment.',
    ], $atts, 'tiers_lieux_events');

    $wanted = array_filter(array_map('trim', explode(',', $atts['ids'] ?: $atts['id'])));
    $orgs = $wanted ? array_map('tle_org_by_id', array_values(array_unique($wanted))) : ('yes' === $atts['groupe'] || 'oui' === $atts['groupe'] ? tle_orgs() : array_slice(tle_orgs(), 0, 1));
    if (!$orgs) {
        if (!current_user_can('manage_options')) {
            return '';
        }
        wp_enqueue_style('tle-style', plugins_url('style.css', __FILE__), [], '1.12.0');
        return '<div class="tle-alert" role="alert"><strong>Événements Tiers-Lieux :</strong> aucun tiers-lieu n\'est configuré. '
            . '<a href="' . esc_url(admin_url('options-general.php?page=tle-settings')) . '">Choisir mon tiers-lieu</a>'
            . '<br><small>Ce message n\'est visible que par les administrateurs ; les visiteurs ne voient rien tant que le lieu n\'est pas choisi.</small></div>';
    }
    $first = $orgs[0];
    $org_name = $first['name'];
    $org_url = tle_org_url($first);
    $grouped = count($orgs) > 1;

    $events = [];
    $errors = [];
    foreach ($orgs as $o) {
        $found = tle_fetch_events($o['id'], $atts['cache']);
        if (is_wp_error($found)) {
            $errors[] = $found->get_error_message();
            continue;
        }
        foreach ($found as $e) {
            // Un même événement coorganisé par deux lieux n'apparaît qu'une fois.
            $key = !empty($e['slug']) ? $e['slug'] : $o['id'] . $e['name'] . $e['startDateSec'];
            if (!isset($events[$key])) {
                $e['_org'] = $o;
                $events[$key] = $e;
            }
        }
    }
    if (!$events && $errors) {
        return current_user_can('manage_options')
            ? '<p class="tle-error">' . esc_html($errors[0]) . '</p>'
            : '';
    }
    $events = array_values($events);

    $now = time();
    $show = $atts['show'];
    $events = array_filter($events, function ($e) use ($show, $now) {
        $end = !empty($e['endDateSec']) ? (int) $e['endDateSec'] : (int) $e['startDateSec'];
        if ('upcoming' === $show) {
            return $end >= $now;
        }
        if ('past' === $show) {
            return $end < $now;
        }
        return true;
    });

    $asc = 'upcoming' === $show;
    usort($events, function ($a, $b) use ($asc) {
        return $asc
            ? $a['startDateSec'] <=> $b['startDateSec']
            : $b['startDateSec'] <=> $a['startDateSec'];
    });

    $limit = (int) $atts['limit'];
    if ($limit > 0) {
        $events = array_slice($events, 0, $limit);
    }

    if (!$events) {
        wp_enqueue_style('tle-style', plugins_url('style.css', __FILE__), [], '1.12.0');
        return '<div class="tle-list"><p class="tle-empty">' . esc_html($atts['empty']) . '</p>'
            . tle_add_event_row($org_url, $org_name, (bool) $first['slug']) . '</div>';
    }

    wp_enqueue_style('tle-style', plugins_url('style.css', __FILE__), [], '1.12.0');

    $with_images = 'no' !== $atts['images'];
    $is_admin = current_user_can('manage_options');
    $out = '<div class="tle-list">';
    $jsonld = [];
    foreach ($events as $e) {
        $start = (int) $e['startDateSec'];
        $end = !empty($e['endDateSec']) ? (int) $e['endDateSec'] : 0;
        $link = !empty($e['slug']) ? TLE_SITE_BASE . '/profil/' . rawurlencode($e['slug']) : '';
        $img = $with_images
            ? tle_image_url(!empty($e['profilMediumImageUrl']) ? $e['profilMediumImageUrl'] : ($e['profilImageUrl'] ?? ''))
            : '';
        $city = $e['address']['addressLocality'] ?? '';
        if (false !== stripos($city, 'non renseign')) {
            $city = '';
        }
        $desc = !empty($e['shortDescription']) ? wp_trim_words(wp_strip_all_tags($e['shortDescription']), 35) : '';

        $title = esc_html($e['name']);
        if ($link) {
            $title = '<a href="' . esc_url($link) . '" target="_blank" rel="noopener">' . $title . '</a>';
        }

        $multi = $end && tle_date('Y-m-d', $start) !== tle_date('Y-m-d', $end);
        $day_label = $multi
            ? tle_format_when($start, $end)
            : ucfirst(rtrim(tle_date('D', $start), '.')) . '. ' . tle_date('j M', $start);
        $eo = $e['_org'];
        $place = '' !== $atts['place'] ? $atts['place'] : $eo['place'];
        $full_desc = !empty($e['shortDescription']) ? wp_strip_all_tags($e['shortDescription']) : '';
        $cal = tle_calendar_links($e['name'], $full_desc, $place, $link, $start, $end);
        $ld = [
            '@type'               => 'Event',
            'name'                => $e['name'],
            'startDate'           => tle_date('c', $start),
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
        ];
        if ($place) {
            $ld['location'] = ['@type' => 'Place', 'name' => $place];
        }
        if ($eo['name']) {
            $ld['organizer'] = ['@type' => 'Organization', 'name' => $eo['name'], 'url' => tle_org_url($eo)];
        }
        if ($end) {
            $ld['endDate'] = tle_date('c', $end);
        }
        if ($full_desc) {
            $ld['description'] = $full_desc;
        }
        if ($link) {
            $ld['url'] = $link;
        }
        if ($img) {
            $ld['image'] = $img;
        }
        $jsonld[] = $ld;

        $out .= '<article class="tle-item">';
        $out .= '<div class="tle-side">';
        $out .= '<time class="tle-date" datetime="' . esc_attr(tle_date('c', $start)) . '"><span>' . esc_html($day_label) . '</span>';
        if (!$multi) {
            $out .= '<span>' . esc_html(tle_format_time($start, $end)) . '</span>';
        }
        $out .= '</time>';
        if ($img) {
            $out .= '<img class="tle-img" loading="lazy" alt="" src="' . esc_url($img) . '">';
        }
        $out .= '</div>';
        $out .= '<div class="tle-body">';
        if ($grouped && $eo['name']) {
            $out .= '<div class="tle-org">' . esc_html($eo['name']) . '</div>';
        }
        $out .= '<div class="tle-title">' . $title . '</div>';
        if ($city) {
            $out .= '<div class="tle-place">' . esc_html($city) . '</div>';
        }
        if ($desc) {
            $out .= '<div class="tle-desc">' . esc_html($desc) . '</div>';
        }
        $long = !empty($e['description']) ? trim(wp_strip_all_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $e['description']))) : '';
        if ('' !== $long && $long !== trim($full_desc)) {
            $out .= '<details class="tle-more"><summary>Voir la description complète</summary><div class="tle-long">'
                . wpautop(esc_html($long)) . '</div></details>';
        }
        $share_url = $link ?: (is_singular() ? get_permalink() : home_url('/'));
        $share_text = $e['name'] . ' - ' . tle_date('j F Y', $start);
        $u = rawurlencode($share_url);
        $t = rawurlencode($share_text);
        $networks = [
            'Facebook' => 'https://www.facebook.com/sharer/sharer.php?u=' . $u,
            'WhatsApp' => 'https://wa.me/?text=' . rawurlencode($share_text . ' ' . $share_url),
            'X'        => 'https://twitter.com/intent/tweet?text=' . $t . '&url=' . $u,
            'LinkedIn' => 'https://www.linkedin.com/sharing/share-offsite/?url=' . $u,
            'Mastodon' => 'https://mastodon.social/share?text=' . rawurlencode($share_text . ' ' . $share_url),
            'Bluesky'  => 'https://bsky.app/intent/compose?text=' . rawurlencode($share_text . ' ' . $share_url),
            'E-mail'   => 'mailto:?subject=' . $t . '&body=' . rawurlencode($share_url),
        ];
        $pop = function ($label, $inner) {
            return '<details class="tle-pop"><summary>' . esc_html($label) . '</summary><div class="tle-panel">' . $inner . '</div></details>';
        };
        $share = '';
        foreach ($networks as $label => $href) {
            $blank = 0 === strpos($href, 'http') ? ' target="_blank" rel="noopener"' : '';
            $share .= '<a href="' . esc_url($href, ['http', 'https', 'mailto']) . '"' . $blank . '>' . esc_html($label) . '</a>';
        }
        if ($is_admin && $link) {
            $share .= tle_oa_ready()
                ? '<a href="#" class="tle-oa" data-slug="' . esc_attr($e['slug']) . '" data-url="' . esc_url(admin_url('admin-ajax.php')) . '" data-nonce="' . esc_attr(wp_create_nonce('tle_oa')) . '">OpenAgenda (admin)</a><em class="tle-copied tle-oa-msg" hidden></em>'
                : '<a href="' . esc_url(admin_url('options-general.php?page=tle-settings')) . '">OpenAgenda : à configurer (admin)</a>';
        }
        $agenda = '<a href="' . esc_url($cal['google']) . '" target="_blank" rel="noopener">Google</a>'
            . '<a href="' . esc_url($cal['outlook']) . '" target="_blank" rel="noopener">Outlook</a>'
            . '<a href="' . esc_attr($cal['ics']) . '" download="evenement.ics">Apple / autre (.ics)</a>';
        $out .= '<div class="tle-cal tle-actions">'
            . ($link ? '<details class="tle-pop tle-join"><summary>Je participe</summary><div class="tle-panel tle-note">'
                . '<p>Pour indiquer votre participation, s’il est affiché, envoyez un sms au numéro qui apparaît ou un mail au contact.</p>'
                . '<p>Vous pouvez aussi indiquer, en cliquant sur l’événement, que vous souhaitez y participer. Pour cela, il faut avoir un compte sur tiers-lieux.org et s’y connecter (ou en créer un, c’est gratuit). Une fois connecté·e, cliquez sur « Participer » dans la fiche de l’événement.</p>'
                . '<a href="' . esc_url(add_query_arg('participer', '1', $link)) . '" target="_blank" rel="noopener">Continuer vers tiers-lieux.org →</a>'
                . '</div></details>' : '')
            . $pop('Partager', $share) . $pop('Ajouter à mon agenda', $agenda);
        if ($is_admin) {
            $fb_date = $multi ? tle_format_when($start, $end) : tle_date('d/m/Y', $start) . ' ' . tle_format_time($start, $end);
            $fb_desc = $full_desc . ($place ? "\n\n" . $place : '') . ($link ? "\n" . $link : '');
            $copy = function ($label, $text) {
                return '<a href="#" data-tle-copy="' . esc_attr($text) . '">' . esc_html($label) . '</a>';
            };
            $out .= $pop('Facebook (admin)',
                '<a href="https://www.facebook.com/events/create/" target="_blank" rel="noopener">1. Ouvrir le formulaire</a>'
                . $copy('2. Copier le titre', $e['name'])
                . $copy('3. Copier la date', $fb_date)
                . $copy('4. Copier la description', $fb_desc)
                . '<em class="tle-copied" hidden>Copié ✓</em>');
        }
        $out .= '</div>';
        $out .= '</div>';
        $out .= '</article>';
    }
    $out .= tle_add_event_row($org_url, $org_name, (bool) $first['slug']);
    $out .= '</div>';

    wp_enqueue_script('tle-script', plugins_url('tle.js', __FILE__), [], '1.12.0', true);

    // Données structurées schema.org pour les moteurs de recherche et les IA.
    $out .= '<script type="application/ld+json">'
        . wp_json_encode(['@context' => 'https://schema.org', '@graph' => $jsonld], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG)
        . '</script>';
    $sources = [];
    foreach ($orgs as $o) {
        $api = TLE_API_BASE . '/co2/element/getdatadetail/type/organizations/id/' . rawurlencode($o['id']) . '/dataName/events';
        $sources[] = '<a href="' . esc_url(tle_org_url($o) . ($o['slug'] ? '/events' : '')) . '" target="_blank" rel="noopener">'
            . esc_html($o['name'] ?: 'tiers-lieux.org') . '</a>'
            . ' (<a href="' . esc_url($api) . '" target="_blank" rel="noopener">données JSON</a>)';
    }
    $out .= '<p class="tle-source">' . ($grouped ? 'Sources : ' : 'Source : ') . implode(' · ', $sources) . '</p>';
    return $out;
}
add_shortcode('tiers_lieux_events', 'tle_shortcode');

// Lien « Vider le cache » dans la liste des extensions.
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $url = wp_nonce_url(admin_url('plugins.php?tle_purge=1'), 'tle_purge');
    array_unshift($links, '<a href="' . esc_url($url) . '">Vider le cache</a>');
    return $links;
});
add_action('admin_init', function () {
    if (isset($_GET['tle_purge']) && current_user_can('manage_options') && check_admin_referer('tle_purge')) {
        foreach (tle_orgs() as $o) {
            delete_transient('tle_events_' . md5($o['id']));
        }
        wp_safe_redirect(admin_url('plugins.php'));
        exit;
    }
});
