<?php
/**
 * Plugin Name: Jacob Frees Evolves Polls
 * Description: Custom polling system with voter tracking, write-in suggestions, and comment support.
 * Version:     1.1.0
 * Author:      Jacob Frees
 */

// Prevent direct access
defined('ABSPATH') || exit;

define('JFP_VERSION', '1.1.1');
define('JFP_TABLE_VOTES', 'jfp_votes');
define('JFP_TABLE_COMMENTS', 'jfp_comments');

// --- Auto-create tables on any plugin load (not just activation) ---
add_action('init', 'jfp_ensure_tables');
function jfp_ensure_tables() {
    global $wpdb;
    $table_votes = $wpdb->prefix . JFP_TABLE_VOTES;
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_votes'") !== $table_votes) {
        jfp_create_tables();
    }
    // Auto-seed default polls if none exist
    jfp_auto_seed();
}

function jfp_auto_seed() {
    // Only check once — use a transient to avoid querying on every page load
    if (get_transient('jfp_seeded')) {
        return;
    }
    $existing = get_posts([
        'post_type'      => 'jf_poll',
        'posts_per_page' => 1,
        'post_status'    => 'any',
    ]);
    if (empty($existing)) {
        jfp_seed_default_polls();
    }
    set_transient('jfp_seeded', 1, DAY_IN_SECONDS);
}

function jfp_create_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $table_votes = $wpdb->prefix . JFP_TABLE_VOTES;
    $sql_votes = "CREATE TABLE IF NOT EXISTS $table_votes (
        id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        poll_id     BIGINT UNSIGNED NOT NULL,
        choice_index INT NOT NULL DEFAULT 0,
        user_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
        voter_ip    VARCHAR(45) NOT NULL DEFAULT '',
        voter_token VARCHAR(64) NOT NULL DEFAULT '',
        suggestion  TEXT,
        voted_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_poll (poll_id),
        INDEX idx_user (poll_id, user_id),
        INDEX idx_ip (poll_id, voter_ip),
        INDEX idx_token (poll_id, voter_token)
    ) $charset;";

    $table_comments = $wpdb->prefix . JFP_TABLE_COMMENTS;
    $sql_comments = "CREATE TABLE IF NOT EXISTS $table_comments (
        id        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        poll_id   BIGINT UNSIGNED NOT NULL,
        author    VARCHAR(100) NOT NULL DEFAULT 'Anonymous',
        content   TEXT NOT NULL,
        website   VARCHAR(255) DEFAULT '',
        approved  TINYINT(1) DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_poll (poll_id)
    ) $charset;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_votes);
    dbDelta($sql_comments);
    update_option('jfp_db_version', JFP_VERSION);
}

// --- Activation: Create/Upgrade DB tables ---
register_activation_hook(__FILE__, 'jfp_activate');
function jfp_activate() {
    jfp_create_tables();
}

// --- Helper: Has current visitor voted on a poll? ---
function jfp_has_voted($poll_id) {
    global $wpdb;
    $table = $wpdb->prefix . JFP_TABLE_VOTES;
    $user_id = get_current_user_id();

    if ($user_id > 0) {
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE poll_id = %d AND user_id = %d",
            $poll_id, $user_id
        ));
        return $count > 0;
    }

    // Anonymous: check IP
    $ip = jfp_get_client_ip();
    $count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE poll_id = %d AND voter_ip = %s AND user_id = 0",
        $poll_id, $ip
    ));
    if ($count > 0) return true;

    // Also check cookie token
    $token = jfp_get_voter_token();
    if ($token) {
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE poll_id = %d AND voter_token = %s AND user_id = 0",
            $poll_id, $token
        ));
        return $count > 0;
    }

    return false;
}

function jfp_get_client_ip() {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        $ip = $_SERVER['REMOTE_ADDR'];
    } else {
        $ip = '0.0.0.0';
    }
    return sanitize_text_field(trim($ip));
}

function jfp_get_voter_token() {
    return isset($_COOKIE['jfp_voter_token']) ? sanitize_text_field($_COOKIE['jfp_voter_token']) : '';
}

// Set voter cookie on first visit
add_action('init', 'jfp_set_voter_cookie');
function jfp_set_voter_cookie() {
    if (!isset($_COOKIE['jfp_voter_token'])) {
        $token = wp_hash('jfp_voter_' . uniqid('', true) . mt_rand());
        setcookie('jfp_voter_token', $token, time() + YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
    }
}

// --- Get poll data (from custom post type) ---
function jfp_get_poll($poll_id) {
    $post = get_post((int)$poll_id);
    if (!$post || $post->post_type !== 'jf_poll' || $post->post_status !== 'publish') {
        return null;
    }

    $options_raw = get_post_meta($poll_id, '_jfp_options', true);
    if (is_string($options_raw)) {
        $options = array_filter(explode("\n", str_replace("\r", "", $options_raw)));
    } else {
        $options = is_array($options_raw) ? $options_raw : [];
    }
    $options = array_values(array_map('trim', $options));

    return [
        'id'             => $poll_id,
        'title'          => $post->post_title,
        'description'    => get_post_meta($poll_id, '_jfp_description', true),
        'slug'           => $post->post_name,
        'status'         => get_post_meta($poll_id, '_jfp_status', true) ?: 'open',
        'options'        => $options,
        'expiry'         => get_post_meta($poll_id, '_jfp_expiry', true),
        'show_results'   => get_post_meta($poll_id, '_jfp_show_results', true) ?: 'after_vote',
        'vote_type'      => get_post_meta($poll_id, '_jfp_vote_type', true) ?: 'single',
        'allow_comments' => (int)get_post_meta($poll_id, '_jfp_allow_comments', true),
        'allow_write_in' => (int)get_post_meta($poll_id, '_jfp_allow_write_in', true) ?: 1,
    ];
}

// --- Get poll results ---
function jfp_get_results($poll_id) {
    global $wpdb;
    $table = $wpdb->prefix . JFP_TABLE_VOTES;
    $poll = jfp_get_poll($poll_id);
    if (!$poll) return null;

    $total = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE poll_id = %d", $poll_id
    ));

    $results = [];
    foreach ($poll['options'] as $i => $label) {
        $votes = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE poll_id = %d AND choice_index = %d AND (suggestion IS NULL OR suggestion = '')",
            $poll_id, $i
        ));
        $results[] = [
            'label'      => $label,
            'votes'      => $votes,
            'percentage' => $total > 0 ? round(($votes / $total) * 100, 1) : 0,
        ];
    }

    // Collect write-in suggestions
    $write_ins = $wpdb->get_results($wpdb->prepare(
        "SELECT suggestion FROM $table WHERE poll_id = %d AND suggestion IS NOT NULL AND suggestion != '' ORDER BY voted_at DESC",
        $poll_id
    ));

    return [
        'total'    => $total,
        'options'  => $results,
        'write_ins' => array_map(function($r) { return $r->suggestion; }, $write_ins),
    ];
}

// --- Get comments ---
function jfp_get_comments($poll_id) {
    global $wpdb;
    $table = $wpdb->prefix . JFP_TABLE_COMMENTS;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT id, author, content, created_at FROM $table WHERE poll_id = %d AND approved = 1 ORDER BY created_at ASC",
        $poll_id
    ));
}

// --- SHORTCODE: [jf_poll id="X"] ---
add_shortcode('jf_poll', 'jfp_shortcode');
function jfp_shortcode($atts) {
    $atts = shortcode_atts(['id' => 0], $atts);
    $poll = jfp_get_poll($atts['id']);
    if (!$poll) return '<p class="jfp-error">Poll not found.</p>';

    $poll_id = $poll['id'];
    $has_voted = jfp_has_voted($poll_id);
    $results = jfp_get_results($poll_id);
    $show_results_now = ($has_voted && $poll['show_results'] === 'after_vote') || $poll['show_results'] === 'always';
    $is_expired = $poll['expiry'] && strtotime($poll['expiry']) < time();
    $can_vote = !$has_voted && !$is_expired && $poll['status'] === 'open';

    // Nonce for AJAX
    $nonce = wp_create_nonce('jfp_poll_' . $poll_id);
    $rest_nonce = wp_create_nonce('wp_rest');

    ob_start();
    ?>
    <div class="jfp-poll" data-poll-id="<?php echo esc_attr($poll_id); ?>" data-vote-type="<?php echo esc_attr($poll['vote_type']); ?>">
        <h3 class="jfp-poll-question"><?php echo esc_html($poll['title']); ?></h3>
        <?php if ($poll['description']) : ?>
            <p class="jfp-poll-desc"><?php echo esc_html($poll['description']); ?></p>
        <?php endif; ?>

        <?php if ($is_expired) : ?>
            <p class="jfp-poll-closed">This poll has closed.</p>
        <?php elseif ($has_voted || $show_results_now) : ?>
            <p class="jfp-poll-voted-msg">✓ You voted. Here are the current results.</p>
        <?php endif; ?>

        <?php if ($can_vote) : ?>
            <div class="jfp-poll-options" data-nonce="<?php echo esc_attr($nonce); ?>">
                <?php foreach ($poll['options'] as $i => $option) : ?>
                    <div class="jfp-poll-option" data-choice="<?php echo esc_attr($i); ?>">
                        <span class="jfp-poll-option-check">○</span>
                        <span class="jfp-poll-option-label"><?php echo esc_html($option); ?></span>
                    </div>
                <?php endforeach; ?>

                <?php if ($poll['allow_write_in']) : ?>
                    <div class="jfp-poll-write-in">
                        <span class="jfp-poll-option-check">○</span>
                        <input type="text" class="jfp-write-in-input" placeholder="Type a suggestion..." maxlength="200" />
                    </div>
                <?php endif; ?>

                <button type="button" class="jfp-poll-submit" data-poll="<?php echo esc_attr($poll_id); ?>">Vote</button>
            </div>
        <?php endif; ?>

        <?php if ($show_results_now && $results) : ?>
            <div class="jfp-poll-results">
                <div class="jfp-poll-total"><?php echo (int)$results['total']; ?> vote<?php echo $results['total'] !== 1 ? 's' : ''; ?></div>
                <?php
                $max_votes = max(array_column($results['options'], 'votes'));
                foreach ($results['options'] as $opt) :
                    $bar_width = $max_votes > 0 ? round(($opt['votes'] / $max_votes) * 100, 1) : 0;
                    $is_leader = $opt['votes'] === $max_votes && $opt['votes'] > 0;
                ?>
                    <div class="jfp-result-row<?php echo $is_leader ? ' jfp-result-leader' : ''; ?>">
                        <div class="jfp-result-label"><?php echo esc_html($opt['label']); ?></div>
                        <div class="jfp-result-bar-wrap">
                            <div class="jfp-result-bar" style="width:<?php echo esc_attr($bar_width); ?>%"></div>
                            <span class="jfp-result-pct"><?php echo esc_html($opt['percentage']); ?>%</span>
                            <span class="jfp-result-count">(<?php echo (int)$opt['votes']; ?>)</span>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if (!empty($results['write_ins'])) : ?>
                    <div class="jfp-write-in-results">
                        <h4 class="jfp-write-in-heading">Suggestions</h4>
                        <?php foreach ($results['write_ins'] as $suggestion) : ?>
                            <div class="jfp-write-in-item"><?php echo esc_html($suggestion); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($poll['allow_comments']) : ?>
            <div class="jfp-poll-comments" data-poll-id="<?php echo esc_attr($poll_id); ?>">
                <h4 class="jfp-comments-title">Discussion <span class="jfp-comments-count"><?php echo count(jfp_get_comments($poll_id)); ?></span></h4>
                <div class="jfp-comments-list">
                    <?php $comments = jfp_get_comments($poll_id); ?>
                    <?php if (empty($comments)) : ?>
                        <p class="jfp-no-comments">No comments yet. Start the conversation.</p>
                    <?php else : ?>
                        <?php foreach ($comments as $c) : ?>
                            <div class="jfp-comment">
                                <div class="jfp-comment-author"><?php echo esc_html($c->author); ?></div>
                                <div class="jfp-comment-content"><?php echo esc_html($c->content); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="jfp-comment-form">
                    <textarea class="jfp-comment-input" placeholder="Share your thoughts..." rows="3"></textarea>
                    <div class="jfp-comment-form-row">
                        <input type="text" class="jfp-comment-name" placeholder="Your name (optional)" />
                        <button type="button" class="jfp-comment-submit jfp-btn-ghost" data-poll="<?php echo esc_attr($poll_id); ?>">Post Comment</button>
                    </div>
                    <p class="jfp-comment-status"></p>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

// --- REST API ---
add_action('rest_api_init', 'jfp_register_routes');
function jfp_register_routes() {
    register_rest_route('jf-polls/v1', '/polls', [
        [
            'methods'             => 'GET',
            'callback'            => 'jfp_rest_get_polls',
            'permission_callback' => '__return_true',
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'jfp_rest_create_poll',
            'permission_callback' => 'current_user_can',
            'args'                => ['edit_posts'],
        ],
    ]);

    register_rest_route('jf-polls/v1', '/polls/(?P<id>\d+)', [
        [
            'methods'             => 'GET',
            'callback'            => 'jfp_rest_get_poll',
            'permission_callback' => '__return_true',
        ],
        [
            'methods'             => 'PUT',
            'callback'            => 'jfp_rest_update_poll',
            'permission_callback' => 'current_user_can',
            'args'                => ['edit_posts'],
        ],
        [
            'methods'             => 'DELETE',
            'callback'            => 'jfp_rest_delete_poll',
            'permission_callback' => 'current_user_can',
            'args'                => ['edit_posts'],
        ],
    ]);

    register_rest_route('jf-polls/v1', '/polls/(?P<id>\d+)/vote', [
        'methods'             => 'POST',
        'callback'            => 'jfp_rest_vote',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('jf-polls/v1', '/polls/(?P<id>\d+)/results', [
        'methods'             => 'GET',
        'callback'            => 'jfp_rest_results',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('jf-polls/v1', '/polls/(?P<id>\d+)/comments', [
        [
            'methods'             => 'GET',
            'callback'            => 'jfp_rest_get_comments',
            'permission_callback' => '__return_true',
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'jfp_rest_post_comment',
            'permission_callback' => '__return_true',
        ],
    ]);

    register_rest_route('jf-polls/v1', '/sync', [
        'methods'             => 'POST',
        'callback'            => 'jfp_rest_sync',
        'permission_callback' => 'current_user_can',
        'args'                => ['edit_posts'],
    ]);
}

function jfp_rest_get_polls() {
    $polls = get_posts([
        'post_type'      => 'jf_poll',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
    ]);
    $data = [];
    foreach ($polls as $p) {
        $poll = jfp_get_poll($p->ID);
        if (!$poll) continue;
        $results = jfp_get_results($p->ID);
        $poll['total_votes'] = $results ? $results['total'] : 0;
        $poll['results'] = $results;
        $poll['shortcode'] = '[jf_poll id="' . $p->ID . '"]';
        $poll['date'] = $p->post_date;
        $data[] = $poll;
    }
    return rest_ensure_response($data);
}

function jfp_rest_get_poll($request) {
    $poll = jfp_get_poll($request['id']);
    if (!$poll) {
        return new WP_Error('not_found', 'Poll not found', ['status' => 404]);
    }
    $results = jfp_get_results($request['id']);
    $poll['total_votes'] = $results ? $results['total'] : 0;
    $poll['results'] = $results;
    $poll['shortcode'] = '[jf_poll id="' . $request['id'] . '"]';
    $post = get_post($request['id']);
    $poll['date'] = $post ? $post->post_date : '';
    return rest_ensure_response($poll);
}

function jfp_rest_vote($request) {
    $poll_id = (int)$request['id'];
    $poll = jfp_get_poll($poll_id);
    if (!$poll) {
        return new WP_Error('not_found', 'Poll not found', ['status' => 404]);
    }
    if ($poll['status'] !== 'open') {
        return new WP_Error('poll_closed', 'This poll is closed.', ['status' => 403]);
    }
    if ($poll['expiry'] && strtotime($poll['expiry']) < time()) {
        return new WP_Error('poll_expired', 'This poll has expired.', ['status' => 403]);
    }
    if (jfp_has_voted($poll_id)) {
        return new WP_Error('already_voted', 'You have already voted on this poll.', ['status' => 403]);
    }

    $body = json_decode($request->get_body(), true);
    if (!$body || empty($body['choices'])) {
        return new WP_Error('missing_data', 'No choices provided.', ['status' => 400]);
    }

    $choices = $body['choices'];
    $suggestion = isset($body['suggestion']) ? sanitize_text_field($body['suggestion']) : '';

    // If it's a write-in vote
    if ($suggestion) {
        $choices = [-1];  // marker for write-in
    }

    global $wpdb;
    $table = $wpdb->prefix . JFP_TABLE_VOTES;

    $user_id = get_current_user_id();
    $ip = jfp_get_client_ip();
    $token = jfp_get_voter_token();

    foreach ((array)$choices as $choice_index) {
        $choice_index = (int)$choice_index;

        // Validate choice index for non-write-in votes
        if (!$suggestion && ($choice_index < 0 || $choice_index >= count($poll['options']))) {
            continue;
        }

        $wpdb->insert($table, [
            'poll_id'      => $poll_id,
            'choice_index' => $choice_index,
            'user_id'      => $user_id,
            'voter_ip'     => $ip,
            'voter_token'  => $token,
            'suggestion'   => $suggestion ?: null,
            'voted_at'     => current_time('mysql'),
        ]);
    }

    $results = jfp_get_results($poll_id);

    return rest_ensure_response([
        'success' => true,
        'message' => 'Vote recorded.',
        'results' => $results,
    ]);
}

function jfp_rest_results($request) {
    $results = jfp_get_results($request['id']);
    if (!$results) {
        return new WP_Error('not_found', 'Poll not found', ['status' => 404]);
    }
    return rest_ensure_response($results);
}

function jfp_rest_get_comments($request) {
    $comments = jfp_get_comments($request['id']);
    return rest_ensure_response($comments);
}

function jfp_rest_post_comment($request) {
    $poll_id = (int)$request['id'];
    $body = json_decode($request->get_body(), true);

    $content = isset($body['content']) ? sanitize_textarea_field($body['content']) : '';
    $author = isset($body['author']) ? sanitize_text_field($body['author']) : 'Anonymous';
    $website = isset($body['website']) ? sanitize_text_field($body['website']) : '';

    if (empty($content)) {
        return new WP_Error('empty_comment', 'Comment cannot be empty.', ['status' => 400]);
    }

    // Honeypot check
    if (!empty($website)) {
        return rest_ensure_response(['success' => true]); // Pretend success, silently ignore
    }

    global $wpdb;
    $table = $wpdb->prefix . JFP_TABLE_COMMENTS;
    $wpdb->insert($table, [
        'poll_id' => $poll_id,
        'author'  => $author,
        'content' => $content,
        'website' => '',
        'created_at' => current_time('mysql'),
    ]);

    return rest_ensure_response([
        'success' => true,
        'data'    => [
            'author'  => $author,
            'content' => $content,
        ],
    ]);
}

function jfp_rest_create_poll($request) {
    $body = json_decode($request->get_body(), true);
    if (!$body || empty($body['title'])) {
        return new WP_Error('missing_title', 'Poll title is required.', ['status' => 400]);
    }

    $post_id = wp_insert_post([
        'post_title'  => sanitize_text_field($body['title']),
        'post_type'   => 'jf_poll',
        'post_status' => 'publish',
    ]);

    if (is_wp_error($post_id)) {
        return $post_id;
    }

    if (!empty($body['description'])) update_post_meta($post_id, '_jfp_description', sanitize_textarea_field($body['description']));
    if (!empty($body['options'])) update_post_meta($post_id, '_jfp_options', implode("\n", array_map('sanitize_text_field', $body['options'])));
    if (!empty($body['vote_type'])) update_post_meta($post_id, '_jfp_vote_type', $body['vote_type'] === 'multi' ? 'multi' : 'single');
    if (!empty($body['show_results'])) update_post_meta($post_id, '_jfp_show_results', $body['show_results']);
    if (!empty($body['expiry'])) update_post_meta($post_id, '_jfp_expiry', $body['expiry']);
    update_post_meta($post_id, '_jfp_status', 'open');
    update_post_meta($post_id, '_jfp_allow_comments', isset($body['allow_comments']) ? (int)$body['allow_comments'] : 1);
    update_post_meta($post_id, '_jfp_allow_write_in', isset($body['allow_write_in']) ? (int)$body['allow_write_in'] : 1);

    return rest_ensure_response(jfp_get_poll($post_id));
}

function jfp_rest_update_poll($request) {
    $poll = jfp_get_poll($request['id']);
    if (!$poll) {
        return new WP_Error('not_found', 'Poll not found', ['status' => 404]);
    }

    $body = json_decode($request->get_body(), true);
    if (!$body) {
        return new WP_Error('bad_request', 'Invalid data.', ['status' => 400]);
    }

    $post_id = $request['id'];
    $post_data = ['ID' => $post_id];
    if (!empty($body['title'])) $post_data['post_title'] = sanitize_text_field($body['title']);
    if (!empty($body['slug'])) $post_data['post_name'] = sanitize_title($body['slug']);
    wp_update_post($post_data);

    if (isset($body['description'])) update_post_meta($post_id, '_jfp_description', sanitize_textarea_field($body['description']));
    if (isset($body['options'])) update_post_meta($post_id, '_jfp_options', implode("\n", array_map('sanitize_text_field', $body['options'])));
    if (isset($body['status'])) update_post_meta($post_id, '_jfp_status', in_array($body['status'], ['open', 'closed']) ? $body['status'] : 'open');
    if (isset($body['vote_type'])) update_post_meta($post_id, '_jfp_vote_type', $body['vote_type'] === 'multi' ? 'multi' : 'single');
    if (isset($body['show_results'])) update_post_meta($post_id, '_jfp_show_results', $body['show_results']);
    if (isset($body['expiry'])) update_post_meta($post_id, '_jfp_expiry', $body['expiry']);
    if (isset($body['allow_comments'])) update_post_meta($post_id, '_jfp_allow_comments', (int)$body['allow_comments']);
    if (isset($body['allow_write_in'])) update_post_meta($post_id, '_jfp_allow_write_in', (int)$body['allow_write_in']);

    return rest_ensure_response(jfp_get_poll($post_id));
}

function jfp_rest_delete_poll($request) {
    wp_delete_post($request['id'], true);
    return rest_ensure_response(['success' => true]);
}

function jfp_rest_sync() {
    // Triggered via Hermes Agent sync — re-create default polls if missing
    $existing = get_posts([
        'post_type'      => 'jf_poll',
        'posts_per_page' => 1,
        'post_status'    => 'any',
    ]);
    if (empty($existing)) {
        jfp_seed_default_polls();
    }
    return rest_ensure_response(['success' => true, 'message' => 'Poll sync complete.']);
}

// --- Seed default polls (for first-time setup) ---
function jfp_seed_default_polls() {
    $polls = [
        [
            'title'       => 'What should Jacob build next?',
            'description' => "Vote on what you want to see built first. Community signal matters -- it tells you what's landing and what's not.",
            'options'     => ["Weekly Community Calls", "More In-Depth Courses", "Digital Products & Tools", "Live Workshops"],
            'slug'        => 'what-should-jacob-build-next',
        ],
        [
            'title'       => 'Which program format interests you?',
            'description' => 'How do you want to learn and engage? Pick what fits your life.',
            'options'     => ["Self-paced online courses", "Live group cohorts", "One-on-one mentorship", "Community-driven projects"],
            'slug'        => 'which-program-format-interests-you',
        ],
        [
            'title'       => 'What content do you want more of?',
            'description' => 'What shows up in your world and makes you lean in? Vote for what you want more of.',
            'options'     => ["Consciousness & dream exploration", "Music & Sonic Seeds", "Practical tech & AI workflows", "Off-grid & nomadic living", "All of it — keep it coming"],
            'slug'        => 'what-content-do-you-want-more-of',
        ],
    ];

    foreach ($polls as $p) {
        $id = wp_insert_post([
            'post_title'   => $p['title'],
            'post_name'    => $p['slug'],
            'post_type'    => 'jf_poll',
            'post_status'  => 'publish',
            'post_content' => '',
        ]);
        if ($id && !is_wp_error($id)) {
            update_post_meta($id, '_jfp_description', $p['description']);
            update_post_meta($id, '_jfp_options', implode("\n", $p['options']));
            update_post_meta($id, '_jfp_status', 'open');
            update_post_meta($id, '_jfp_vote_type', 'single');
            update_post_meta($id, '_jfp_show_results', 'after_vote');
            update_post_meta($id, '_jfp_allow_comments', 1);
            update_post_meta($id, '_jfp_allow_write_in', 1);
        }
    }
}

// --- Enqueue frontend assets ---
add_action('wp_enqueue_scripts', 'jfp_enqueue_assets');
function jfp_enqueue_assets() {
    global $post;
    // Only enqueue if poll shortcode is present on this page
    if (!is_admin() && (!$post || !has_shortcode($post->post_content, 'jf_poll'))) {
        return;
    }

    wp_enqueue_style('jfp-polls', plugin_dir_url(__FILE__) . 'assets/jf-polls.css', [], JFP_VERSION);
    wp_enqueue_script('jfp-polls', plugin_dir_url(__FILE__) . 'assets/jf-polls.js', ['jquery'], JFP_VERSION, true);
    wp_localize_script('jfp-polls', 'jfp_ajax', [
        'rest_url'  => get_rest_url(null, 'jf-polls/v1'),
        'nonce'     => wp_create_nonce('wp_rest'),
        'rest_nonce' => wp_create_nonce('jfp_comment_nonce'),
    ]);
}

// --- Register Custom Post Type ---
add_action('init', 'jfp_register_post_type');
function jfp_register_post_type() {
    register_post_type('jf_poll', [
        'labels' => [
            'name'          => 'Polls',
            'singular_name' => 'Poll',
            'add_new_item'  => 'Add New Poll',
            'edit_item'     => 'Edit Poll',
        ],
        'public'       => false,
        'show_ui'      => true,
        'show_in_menu' => true,
        'menu_icon'    => 'dashicons-chart-bar',
        'supports'     => ['title', 'editor'],
        'show_in_rest' => true,
        'rest_base'    => 'jf_poll',
    ]);
}

// --- Meta boxes for poll settings ---
add_action('add_meta_boxes', 'jfp_add_meta_boxes');
function jfp_add_meta_boxes() {
    add_meta_box('jfp_poll_settings', 'Poll Settings', 'jfp_render_meta_box', 'jf_poll', 'normal', 'high');
}

function jfp_render_meta_box($post) {
    wp_nonce_field('jfp_save_meta', 'jfp_meta_nonce');

    $description = get_post_meta($post->ID, '_jfp_description', true);
    $options_raw = get_post_meta($post->ID, '_jfp_options', true);
    $options_text = is_string($options_raw) ? $options_raw : (is_array($options_raw) ? implode("\n", $options_raw) : '');
    $status = get_post_meta($post->ID, '_jfp_status', true) ?: 'open';
    $vote_type = get_post_meta($post->ID, '_jfp_vote_type', true) ?: 'single';
    $show_results = get_post_meta($post->ID, '_jfp_show_results', true) ?: 'after_vote';
    $expiry = get_post_meta($post->ID, '_jfp_expiry', true);
    $allow_comments = (int)get_post_meta($post->ID, '_jfp_allow_comments', true);
    $allow_write_in = (int)get_post_meta($post->ID, '_jfp_allow_write_in', true);

    // Display vote counts
    $results = jfp_get_results($post->ID);
    $total_votes = $results ? $results['total'] : 0;
    ?>
    <table class="form-table">
        <tr>
            <th><label for="jfp_description">Description</label></th>
            <td><textarea id="jfp_description" name="jfp_description" rows="3" class="large-text"><?php echo esc_textarea($description); ?></textarea></td>
        </tr>
        <tr>
            <th><label for="jfp_options">Options (one per line)</label></th>
            <td><textarea id="jfp_options" name="jfp_options" rows="5" class="large-text"><?php echo esc_textarea($options_text); ?></textarea></td>
        </tr>
        <tr>
            <th>Status</th>
            <td>
                <select name="jfp_status">
                    <option value="open" <?php selected($status, 'open'); ?>>Open</option>
                    <option value="closed" <?php selected($status, 'closed'); ?>>Closed</option>
                </select>
            </td>
        </tr>
        <tr>
            <th>Vote Type</th>
            <td>
                <select name="jfp_vote_type">
                    <option value="single" <?php selected($vote_type, 'single'); ?>>Single Choice</option>
                    <option value="multi" <?php selected($vote_type, 'multi'); ?>>Multi Choice</option>
                </select>
            </td>
        </tr>
        <tr>
            <th>Show Results</th>
            <td>
                <select name="jfp_show_results">
                    <option value="after_vote" <?php selected($show_results, 'after_vote'); ?>>After Voting</option>
                    <option value="always" <?php selected($show_results, 'always'); ?>>Always</option>
                    <option value="never" <?php selected($show_results, 'never'); ?>>Never (admin only)</option>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="jfp_expiry">Expiry (optional)</label></th>
            <td><input type="datetime-local" id="jfp_expiry" name="jfp_expiry" value="<?php echo esc_attr($expiry); ?>" /></td>
        </tr>
        <tr>
            <th>Allow Comments</th>
            <td><input type="checkbox" name="jfp_allow_comments" value="1" <?php checked($allow_comments, 1); ?> /></td>
        </tr>
        <tr>
            <th>Allow Write-In Suggestions</th>
            <td><input type="checkbox" name="jfp_allow_write_in" value="1" <?php checked($allow_write_in, 1); ?> /></td>
        </tr>
        <tr>
            <th>Current Votes</th>
            <td><strong><?php echo $total_votes; ?></strong> total</td>
        </tr>
        <?php if ($total_votes > 0) : ?>
        <tr>
            <th>Reset Poll</th>
            <td>
                <label><input type="checkbox" name="jfp_reset_votes" value="1" /> Delete all votes for this poll and re-open it</label>
            </td>
        </tr>
        <?php endif; ?>
    </table>
    <?php
}

add_action('save_post', 'jfp_save_meta');
function jfp_save_meta($post_id) {
    if (!isset($_POST['jfp_meta_nonce']) || !wp_verify_nonce($_POST['jfp_meta_nonce'], 'jfp_save_meta')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    if (isset($_POST['jfp_description'])) update_post_meta($post_id, '_jfp_description', sanitize_textarea_field($_POST['jfp_description']));
    if (isset($_POST['jfp_options'])) update_post_meta($post_id, '_jfp_options', sanitize_textarea_field($_POST['jfp_options']));
    if (isset($_POST['jfp_status'])) update_post_meta($post_id, '_jfp_status', $_POST['jfp_status'] === 'closed' ? 'closed' : 'open');
    if (isset($_POST['jfp_vote_type'])) update_post_meta($post_id, '_jfp_vote_type', $_POST['jfp_vote_type'] === 'multi' ? 'multi' : 'single');
    if (isset($_POST['jfp_show_results'])) update_post_meta($post_id, '_jfp_show_results', $_POST['jfp_show_results']);
    if (isset($_POST['jfp_expiry']) && $_POST['jfp_expiry']) update_post_meta($post_id, '_jfp_expiry', sanitize_text_field($_POST['jfp_expiry']));
    else delete_post_meta($post_id, '_jfp_expiry');
    update_post_meta($post_id, '_jfp_allow_comments', isset($_POST['jfp_allow_comments']) ? 1 : 0);
    update_post_meta($post_id, '_jfp_allow_write_in', isset($_POST['jfp_allow_write_in']) ? 1 : 0);

    // Handle vote reset
    if (isset($_POST['jfp_reset_votes']) && $_POST['jfp_reset_votes']) {
        global $wpdb;
        $table = $wpdb->prefix . JFP_TABLE_VOTES;
        $wpdb->delete($table, ['poll_id' => $post_id]);
        update_post_meta($post_id, '_jfp_status', 'open');
    }
}
