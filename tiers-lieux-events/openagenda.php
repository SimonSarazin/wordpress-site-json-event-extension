<?php
/**
 * Envoi d'un événement vers OpenAgenda (administrateurs uniquement).
 *
 * La clé secrète reste côté serveur : le navigateur n'envoie que l'identifiant de l'événement,
 * le contenu est relu depuis les données de tiers-lieux.org.
 */
if (!defined('ABSPATH')) {
    exit;
}

const TLE_OA_API = 'https://api.openagenda.com/v2';

function tle_oa_settings()
{
    $s = get_option('tle_oa');
    return (is_array($s) ? $s : []) + ['agenda' => '', 'location' => '', 'key' => ''];
}

function tle_oa_ready()
{
    $s = tle_oa_settings();
    return '' !== $s['agenda'] && '' !== $s['location'] && '' !== $s['key'];
}

/** @return string|WP_Error */
function tle_oa_token($key)
{
    $cache = 'tle_oa_token_' . md5($key);
    $token = get_transient($cache);
    if ($token) {
        return $token;
    }
    $response = wp_remote_post(TLE_OA_API . '/requestAccessToken', [
        'timeout' => 15,
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => wp_json_encode(['code' => $key]),
    ]);
    if (is_wp_error($response)) {
        return $response;
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (200 !== wp_remote_retrieve_response_code($response) || empty($data['access_token'])) {
        return new WP_Error('tle_oa_auth', 'Clé secrète OpenAgenda refusée.');
    }
    set_transient($cache, $data['access_token'], max(60, (int) ($data['expires_in'] ?? 3600) - 60));
    return $data['access_token'];
}

add_action('admin_post_tle_save_oa', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Accès refusé');
    }
    check_admin_referer('tle_save_oa');
    $old = tle_oa_settings();
    $key = sanitize_text_field(wp_unslash($_POST['tle_oa_key'] ?? ''));
    update_option('tle_oa', [
        'agenda'   => sanitize_text_field(wp_unslash($_POST['tle_oa_agenda'] ?? '')),
        'location' => sanitize_text_field(wp_unslash($_POST['tle_oa_location'] ?? '')),
        'key'      => '' !== $key ? $key : $old['key'],
    ], false);
    wp_safe_redirect(add_query_arg('tle_oa_saved', 1, admin_url('options-general.php?page=tle-settings')));
    exit;
});

add_action('wp_ajax_tle_openagenda', function () {
    check_ajax_referer('tle_oa');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Accès refusé', 403);
    }
    if (!tle_oa_ready()) {
        wp_send_json_error('OpenAgenda n’est pas configuré (Réglages → Événements Tiers-Lieux).');
    }
    $slug = isset($_POST['slug']) ? sanitize_text_field(wp_unslash($_POST['slug'])) : '';
    $event = null;
    foreach (tle_orgs() as $org) {
        $events = tle_fetch_events($org['id'], 30);
        foreach (is_wp_error($events) ? [] : $events as $e) {
            if (!empty($e['slug']) && $e['slug'] === $slug) {
                $event = $e;
                break 2;
            }
        }
    }
    if (!$event) {
        wp_send_json_error('Événement introuvable.');
    }

    $s = tle_oa_settings();
    $token = tle_oa_token($s['key']);
    if (is_wp_error($token)) {
        wp_send_json_error($token->get_error_message());
    }

    $start = (int) $event['startDateSec'];
    $end = !empty($event['endDateSec']) ? (int) $event['endDateSec'] : $start + 2 * HOUR_IN_SECONDS;
    $short = wp_strip_all_tags($event['shortDescription'] ?? '');
    $long = wp_strip_all_tags($event['description'] ?? '');
    $data = [
        'title'       => $event['name'],
        'description' => mb_substr('' !== $short ? $short : $event['name'], 0, 200),
        'locationUid' => (int) $s['location'],
        'timings'     => [['begin' => tle_date('Y-m-d\TH:i:sO', $start), 'end' => tle_date('Y-m-d\TH:i:sO', $end)]],
    ];
    $fiche = TLE_SITE_BASE . '/profil/' . rawurlencode($slug);
    $data['longDescription'] = mb_substr('' !== $long ? $long : $short, 0, 9000) . "\n\nFiche de l'événement : " . $fiche;

    // Création ou mise à jour selon l'identifiant externe : renvoyer un événement ne le duplique pas.
    $url = sprintf('%s/agendas/%s/events/ext/tiers-lieux/%s', TLE_OA_API, rawurlencode($s['agenda']), rawurlencode($slug));
    $headers = ['access-token' => $token, 'Content-Type' => 'application/json', 'lang' => 'fr'];
    $body = wp_json_encode(['data' => $data]); // les données de l'événement sont sous la clé « data »

    // Image : OpenAgenda attend un fichier ; on le télécharge puis on l'envoie en multipart.
    $img_url = tle_image_url(!empty($event['profilImageUrl']) ? $event['profilImageUrl'] : ($event['profilMediumImageUrl'] ?? ''));
    if ($img_url) {
        $img = wp_remote_get($img_url, ['timeout' => 20]);
        $bytes = is_wp_error($img) || 200 !== wp_remote_retrieve_response_code($img) ? '' : wp_remote_retrieve_body($img);
        $type = is_wp_error($img) ? '' : strtolower(strtok((string) wp_remote_retrieve_header($img, 'content-type'), ';'));
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$type] ?? '';
        if ('' !== $bytes && '' !== $ext && strlen($bytes) < 9 * MB_IN_BYTES) {
            $boundary = 'tle' . wp_generate_password(24, false);
            $body = "--$boundary\r\nContent-Disposition: form-data; name=\"data\"\r\n\r\n" . wp_json_encode($data) . "\r\n"
                . "--$boundary\r\nContent-Disposition: form-data; name=\"image\"; filename=\"evenement.$ext\"\r\nContent-Type: $type\r\n\r\n"
                . $bytes . "\r\n--$boundary--\r\n";
            $headers['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;
        }
    }

    $response = wp_remote_request($url, [
        'method'  => 'PUT',
        'timeout' => 30,
        'headers' => $headers,
        'body'    => $body,
    ]);
    if (is_wp_error($response)) {
        wp_send_json_error($response->get_error_message());
    }
    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if ($code < 200 || $code >= 300) {
        $msg = is_array($body) ? ($body['message'] ?? $body['error'] ?? '') : '';
        if (is_array($body) && !empty($body['errors'])) {
            $msg .= ' ' . wp_json_encode($body['errors'], JSON_UNESCAPED_UNICODE);
        }
        wp_send_json_error('OpenAgenda a refusé l’envoi (' . $code . ') ' . (is_string($msg) ? $msg : ''));
    }
    wp_send_json_success('Envoyé à OpenAgenda ✓');
});
