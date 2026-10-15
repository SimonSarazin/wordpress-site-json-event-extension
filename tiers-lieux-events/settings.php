<?php
/**
 * Page de réglages : choix du tiers-lieu par recherche dans l'API de tiers-lieux.org.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', function () {
    add_options_page('Événements Tiers-Lieux', 'Événements Tiers-Lieux', 'manage_options', 'tle-settings', 'tle_settings_page');
});

add_filter('plugin_action_links_' . plugin_basename(dirname(__FILE__) . '/tiers-lieux-events.php'), function ($links) {
    array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=tle-settings')) . '">Choisir mon tiers-lieu</a>');
    return $links;
});

add_action('admin_post_tle_save', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Accès refusé');
    }
    check_admin_referer('tle_save');
    $id = isset($_POST['tle_id']) ? sanitize_text_field(wp_unslash($_POST['tle_id'])) : '';
    if (!preg_match('/^[a-f0-9]{24}$/', $id)) {
        wp_safe_redirect(add_query_arg('tle_error', 1, admin_url('options-general.php?page=tle-settings')));
        exit;
    }
    update_option('tle_org', [
        'id'    => $id,
        'name'  => sanitize_text_field(wp_unslash($_POST['tle_name'] ?? '')),
        'slug'  => sanitize_text_field(wp_unslash($_POST['tle_slug'] ?? '')),
        'place' => sanitize_text_field(wp_unslash($_POST['tle_place'] ?? '')),
    ]);
    delete_transient('tle_events_' . md5($id));
    wp_safe_redirect(add_query_arg('tle_saved', 1, admin_url('options-general.php?page=tle-settings')));
    exit;
});

add_action('admin_post_tle_save_extra', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Accès refusé');
    }
    check_admin_referer('tle_save_extra');
    $raw = json_decode(wp_unslash($_POST['tle_extra'] ?? '[]'), true);
    $main = tle_org()['id'];
    $list = [];
    foreach (is_array($raw) ? $raw : [] as $o) {
        $id = is_array($o) && isset($o['id']) ? sanitize_text_field($o['id']) : '';
        if (!preg_match('/^[a-f0-9]{24}$/', $id) || $id === $main || isset($list[$id]) || count($list) >= 20) {
            continue;
        }
        $list[$id] = [
            'id'    => $id,
            'name'  => sanitize_text_field($o['name'] ?? ''),
            'slug'  => sanitize_text_field($o['slug'] ?? ''),
            'place' => sanitize_text_field($o['place'] ?? ''),
        ];
        delete_transient('tle_events_' . md5($id));
    }
    update_option('tle_extra_orgs', array_values($list));
    wp_safe_redirect(add_query_arg('tle_extra_saved', 1, admin_url('options-general.php?page=tle-settings')));
    exit;
});

// Recherche de tiers-lieux : relais serveur vers l'API publique, pour éviter tout souci de CORS.
add_action('wp_ajax_tle_search', function () {
    check_ajax_referer('tle_search');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Accès refusé', 403);
    }
    $q = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
    if (mb_strlen($q) < 3) {
        wp_send_json_success([]);
    }
    $response = wp_remote_post(TLE_API_BASE . '/co2/search/globalautocomplete', [
        'timeout' => 15,
        'body'    => ['name' => $q, 'searchType' => ['organizations'], 'indexMin' => 0, 'indexStep' => 10],
    ]);
    if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
        wp_send_json_error('Recherche indisponible', 502);
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    $out = [];
    foreach ((is_array($data['results'] ?? null) ? $data['results'] : []) as $id => $o) {
        if (!is_array($o) || empty($o['name'])) {
            continue;
        }
        $a = $o['address'] ?? [];
        $street = trim(($a['streetAddress'] ?? ''));
        $city = trim(($a['postalCode'] ?? '') . ' ' . ucwords(strtolower($a['addressLocality'] ?? '')));
        $out[] = [
            'id'    => (string) $id,
            'name'  => $o['name'],
            'slug'  => $o['slug'] ?? '',
            'city'  => $city,
            'place' => implode(', ', array_filter([$o['name'], $street, $city])),
        ];
    }
    wp_send_json_success($out);
});

function tle_settings_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $org = tle_org();
    $nonce = wp_create_nonce('tle_search');
    ?>
    <div class="wrap">
        <h1>Événements Tiers-Lieux</h1>
        <?php if (!$org['id']) : ?>
            <div class="notice notice-warning"><p>Aucun tiers-lieu n'est configuré : le code court n'affichera rien aux visiteurs tant que vous n'en aurez pas choisi un.</p></div>
        <?php endif; ?>
        <?php if (isset($_GET['tle_saved'])) : ?>
            <div class="notice notice-success is-dismissible"><p>Tiers-lieu enregistré.</p></div>
        <?php elseif (isset($_GET['tle_error'])) : ?>
            <div class="notice notice-error"><p>Choisissez d'abord un tiers-lieu dans la liste de recherche.</p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="tle_save">
            <?php wp_nonce_field('tle_save'); ?>
            <input type="hidden" name="tle_id" id="tle-id" value="<?php echo esc_attr($org['id']); ?>">
            <input type="hidden" name="tle_name" id="tle-name" value="<?php echo esc_attr($org['name']); ?>">
            <input type="hidden" name="tle_slug" id="tle-slug" value="<?php echo esc_attr($org['slug']); ?>">

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Tiers-lieu actuel</th>
                    <td><strong id="tle-current"><?php echo $org['name'] ? esc_html($org['name']) : 'Aucun — à choisir ci-dessous'; ?></strong></td>
                </tr>
                <tr>
                    <th scope="row"><label for="tle-q">Rechercher mon tiers-lieu</label></th>
                    <td>
                        <input type="search" id="tle-q" class="regular-text" placeholder="Tapez le nom de votre lieu…" autocomplete="off">
                        <p class="description">La recherche porte sur les structures référencées sur tiers-lieux.org.</p>
                        <ul id="tle-results" style="margin:.5em 0 0;max-width:420px"></ul>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tle-place">Adresse des événements</label></th>
                    <td>
                        <input type="text" name="tle_place" id="tle-place" class="large-text" value="<?php echo esc_attr($org['place']); ?>">
                        <p class="description">Utilisée dans « Ajouter à mon agenda » et pour les moteurs de recherche. Remplie depuis la fiche du lieu : corrigez-la si besoin.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Enregistrer'); ?>
        </form>

        <h2>Autres tiers-lieux (optionnel)</h2>
        <?php if (isset($_GET['tle_extra_saved'])) : ?>
            <div class="notice notice-success is-dismissible"><p>Autres lieux enregistrés.</p></div>
        <?php endif; ?>
        <p>Le code court <code>[tiers_lieux_events]</code> affiche ensemble, par ordre de date, les événements du lieu principal et de ces lieux. Chaque événement indique alors son lieu.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="tle_save_extra">
            <?php wp_nonce_field('tle_save_extra'); ?>
            <input type="hidden" name="tle_extra" id="tle-extra" value="">
            <ul id="tle-extra-list" style="max-width:520px"></ul>
            <p>
                <label for="tle-q2"><strong>Ajouter un tiers-lieu</strong></label><br>
                <input type="search" id="tle-q2" class="regular-text" placeholder="Tapez le nom du lieu…" autocomplete="off">
            </p>
            <ul id="tle-results2" style="margin:.5em 0 0;max-width:420px"></ul>
            <?php submit_button('Enregistrer les autres lieux'); ?>
        </form>

        <h2>OpenAgenda (optionnel)</h2>
        <?php $oa = tle_oa_settings(); ?>
        <?php if (isset($_GET['tle_oa_saved'])) : ?>
            <div class="notice notice-success is-dismissible"><p>Réglages OpenAgenda enregistrés.</p></div>
        <?php endif; ?>
        <p>Permet aux administrateurs d'envoyer un événement vers un agenda OpenAgenda depuis « Partager ». Renvoyer le même événement le met à jour sans le dupliquer.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="tle_save_oa">
            <?php wp_nonce_field('tle_save_oa'); ?>
            <table class="form-table" role="presentation">
                <tr><th scope="row"><label for="tle-oa-agenda">Identifiant de l'agenda</label></th>
                    <td><input type="text" name="tle_oa_agenda" id="tle-oa-agenda" class="regular-text" value="<?php echo esc_attr($oa['agenda']); ?>">
                    <p class="description">Le nombre (UID) de votre agenda OpenAgenda.</p></td></tr>
                <tr><th scope="row"><label for="tle-oa-location">Identifiant du lieu</label></th>
                    <td><input type="text" name="tle_oa_location" id="tle-oa-location" class="regular-text" value="<?php echo esc_attr($oa['location']); ?>">
                    <p class="description">L'UID du lieu déjà créé dans cet agenda (obligatoire pour un événement en présentiel).</p></td></tr>
                <tr><th scope="row"><label for="tle-oa-key">Clé API secrète</label></th>
                    <td><input type="password" name="tle_oa_key" id="tle-oa-key" class="regular-text" autocomplete="new-password" placeholder="<?php echo $oa['key'] ? '•••••• (enregistrée, laisser vide pour la garder)' : 'oa_sk_…'; ?>">
                    <p class="description">À créer dans <a href="https://openagenda.com/settings/apiKey" target="_blank" rel="noopener">vos paramètres OpenAgenda</a>. Elle reste côté serveur et n'est jamais affichée.</p></td></tr>
            </table>
            <?php submit_button('Enregistrer OpenAgenda'); ?>
        </form>

        <h2>Utilisation</h2>
        <p>Placez le code court <code>[tiers_lieux_events]</code> dans un bloc « Code court » d'une page. Options : <code>show="upcoming|past|all"</code>, <code>limit="10"</code>, <code>images="no"</code>, <code>id="…"</code> (un seul lieu précis), <code>ids="…,…"</code> (une sélection de lieux).</p>
    </div>
    <script>
    (function () {
        var nonce = '<?php echo esc_js($nonce); ?>';
        function esc(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
        function bindSearch(q, list, pick) {
            var timer;
            q.addEventListener('input', function () {
                clearTimeout(timer);
                var v = q.value.trim();
                if (v.length < 3) { list.innerHTML = ''; return; }
                timer = setTimeout(function () {
                    list.innerHTML = '<li><em>Recherche…</em></li>';
                    fetch(ajaxurl + '?action=tle_search&_wpnonce=' + nonce + '&q=' + encodeURIComponent(v))
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            var items = (res && res.success) ? res.data : null;
                            if (!items) { list.innerHTML = '<li>Recherche indisponible, réessayez.</li>'; return; }
                            if (!items.length) { list.innerHTML = '<li>Aucun résultat.</li>'; return; }
                            list.innerHTML = items.map(function (o, i) {
                                return '<li><button type="button" class="button" style="width:100%;text-align:left;margin-bottom:4px" data-i="' + i + '"><strong>' + esc(o.name) + '</strong><br><small>' + esc(o.city) + '</small></button></li>';
                            }).join('');
                            list.onclick = function (e) {
                                var b = e.target.closest('button[data-i]');
                                if (!b) { return; }
                                pick(items[b.getAttribute('data-i')]);
                                list.innerHTML = '';
                                q.value = '';
                            };
                        })
                        .catch(function () { list.innerHTML = '<li>Recherche indisponible, réessayez.</li>'; });
                }, 350);
            });
        }

        bindSearch(document.getElementById('tle-q'), document.getElementById('tle-results'), function (o) {
            document.getElementById('tle-id').value = o.id;
            document.getElementById('tle-name').value = o.name;
            document.getElementById('tle-slug').value = o.slug;
            document.getElementById('tle-place').value = o.place;
            document.getElementById('tle-current').textContent = o.name + ' (à enregistrer)';
        });

        var extras = <?php echo wp_json_encode(tle_extra_orgs(), JSON_HEX_TAG | JSON_HEX_AMP); ?>;
        var field = document.getElementById('tle-extra'), box = document.getElementById('tle-extra-list');
        function sync() { field.value = JSON.stringify(extras); }
        function render() {
            sync();
            box.innerHTML = extras.length ? '' : '<li><em>Aucun autre lieu pour l’instant.</em></li>';
            extras.forEach(function (o, i) {
                var li = document.createElement('li'), name = document.createElement('strong'),
                    rm = document.createElement('button'), inp = document.createElement('input');
                li.style.marginBottom = '10px';
                name.textContent = o.name;
                rm.type = 'button'; rm.className = 'button-link button-link-delete'; rm.textContent = 'Retirer';
                rm.style.marginLeft = '10px';
                rm.onclick = function () { extras.splice(i, 1); render(); };
                inp.type = 'text'; inp.className = 'large-text'; inp.value = o.place || '';
                inp.placeholder = 'Adresse des événements';
                inp.oninput = function () { o.place = inp.value; sync(); };
                li.appendChild(name); li.appendChild(rm); li.appendChild(document.createElement('br')); li.appendChild(inp);
                box.appendChild(li);
            });
        }
        bindSearch(document.getElementById('tle-q2'), document.getElementById('tle-results2'), function (o) {
            var known = extras.some(function (x) { return x.id === o.id; }) || o.id === document.getElementById('tle-id').value;
            if (!known) { extras.push(o); render(); }
        });
        render();
    })();
    </script>
    <?php
}
