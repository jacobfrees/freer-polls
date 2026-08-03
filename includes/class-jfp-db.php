<?php
if (!defined('ABSPATH')) exit;

class JFP_DB {

    private static $table = null;

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'jf_poll_votes';
    }

    public static function create_tables() {
        global $wpdb;
        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            poll_id bigint(20) unsigned NOT NULL,
            choice_index int(11) NOT NULL,
            user_id bigint(20) unsigned DEFAULT 0,
            anon_id varchar(64) DEFAULT '',
            ip_hash char(64) NOT NULL,
            vote_hmac char(64) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_vote (poll_id, ip_hash, choice_index),
            KEY poll_id (poll_id),
            KEY user_id (user_id),
            KEY anon_id (anon_id),
            KEY created_at (created_at)
        ) $charset;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        update_option('jfp_db_version', JFP_DB_VERSION);
    }

    private static function get_ip_hash() {
        $ip = '';
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        // Hash with site salt so raw IPs are never stored
        $salt = wp_salt();
        return hash_hmac('sha256', trim($ip), $salt);
    }

    private static function get_anon_id() {
        if (is_user_logged_in()) return '';
        // Cookie-based anon ID
        $anon_id = isset($_COOKIE['jfp_anon_id']) ? $_COOKIE['jfp_anon_id'] : '';
        if (empty($anon_id)) {
            $anon_id = 'anon_' . wp_generate_password(32, false);
            setcookie('jfp_anon_id', $anon_id, time() + YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN);
        }
        return sanitize_text_field($anon_id);
    }

    private static function generate_hmac($poll_id, $choice_index, $ip_hash) {
        $secret = get_option('jfp_settings', array());
        $hmac_secret = isset($secret['hmac_secret']) ? $secret['hmac_secret'] : wp_salt();
        return hash_hmac('sha256', $poll_id . '|' . $choice_index . '|' . $ip_hash . '|' . time(), $hmac_secret);
    }

    /**
     * Cast a vote. Returns array with success/error + results.
     */
    public static function cast_vote($poll_id, $choices, $options = array()) {
        global $wpdb;
        $table = self::table_name();

        // Validate poll is open
        $status = JFP_Meta::get_status($poll_id);
        if ($status !== 'open') {
            return array('success' => false, 'error' => 'poll_closed', 'message' => 'This poll is closed.');
        }

        // Get poll options
        $poll_options = JFP_Meta::get_options($poll_id);
        if (empty($poll_options)) {
            return array('success' => false, 'error' => 'no_options', 'message' => 'This poll has no options.');
        }

        // Validate choices
        if (!is_array($choices)) $choices = array($choices);
        $choices = array_map('intval', $choices);

        $vote_type = JFP_Meta::get_display_settings($poll_id)['vote_type'];
        if ($vote_type === 'single' && count($choices) > 1) {
            return array('success' => false, 'error' => 'single_only', 'message' => 'This poll allows only one choice.');
        }

        // Validate each choice index
        foreach ($choices as $idx) {
            if ($idx < 0 || $idx >= count($poll_options)) {
                return array('success' => false, 'error' => 'invalid_choice', 'message' => 'Invalid choice selected.');
            }
        }

        $ip_hash = self::get_ip_hash();
        $user_id = is_user_logged_in() ? get_current_user_id() : 0;
        $anon_id = $user_id ? '' : self::get_anon_id();

        // Rate limiting
        $rate_limit = get_option('jfp_settings', array());
        $limit = isset($rate_limit['rate_limit_per_hour']) ? intval($rate_limit['rate_limit_per_hour']) : 5;
        $recent = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE poll_id = %d AND ip_hash = %s AND created_at > %s",
            $poll_id, $ip_hash, gmdate('Y-m-d H:i:s', current_time('timestamp') - 3600)
        ));
        if ($recent >= $limit) {
            return array('success' => false, 'error' => 'rate_limited', 'message' => 'Too many votes. Try again later.');
        }

        // Check for existing votes (single-vote enforcement per poll)
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE poll_id = %d AND ip_hash = %s",
            $poll_id, $ip_hash
        ));
        if ($existing > 0) {
            // Allow re-vote in multi mode? No — one vote per person per poll.
            return array('success' => false, 'error' => 'already_voted', 'message' => 'You have already voted on this poll.');
        }

        // Insert votes
        $now = gmdate('Y-m-d H:i:s', current_time('timestamp'));
        foreach ($choices as $idx) {
            $hmac = self::generate_hmac($poll_id, $idx, $ip_hash);
            $wpdb->insert(
                $table,
                array(
                    'poll_id' => $poll_id,
                    'choice_index' => $idx,
                    'user_id' => $user_id,
                    'anon_id' => $anon_id,
                    'ip_hash' => $ip_hash,
                    'vote_hmac' => $hmac,
                    'created_at' => $now,
                ),
                array('%d', '%d', '%d', '%s', '%s', '%s', '%s')
            );
        }

        return array(
            'success' => true,
            'message' => 'Vote recorded.',
            'results' => self::get_results($poll_id),
        );
    }

    /**
     * Get vote results for a poll.
     */
    public static function get_results($poll_id) {
        global $wpdb;
        $table = self::table_name();

        $options = JFP_Meta::get_options($poll_id);
        if (empty($options)) return array();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT choice_index, COUNT(*) as count FROM $table WHERE poll_id = %d GROUP BY choice_index ORDER BY choice_index ASC",
            $poll_id
        ));

        $counts = array();
        foreach ($options as $i => $label) {
            $counts[$i] = array('label' => $label, 'votes' => 0);
        }
        $total = 0;
        foreach ($rows as $row) {
            if (isset($counts[$row->choice_index])) {
                $counts[$row->choice_index]['votes'] = (int) $row->count;
                $total += (int) $row->count;
            }
        }

        // Add percentages
        foreach ($counts as $i => $data) {
            $counts[$i]['percentage'] = $total > 0 ? round(($data['votes'] / $total) * 100, 1) : 0;
        }

        return array(
            'total' => $total,
            'options' => array_values($counts),
        );
    }

    public static function get_total_votes($poll_id) {
        global $wpdb;
        $table = self::table_name();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE poll_id = %d",
            $poll_id
        ));
    }

    public static function has_voted($poll_id) {
        global $wpdb;
        $table = self::table_name();
        $ip_hash = self::get_ip_hash();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE poll_id = %d AND ip_hash = %s",
            $poll_id, $ip_hash
        )) > 0;
    }
}