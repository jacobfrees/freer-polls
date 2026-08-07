<?php
if (!defined('ABSPATH')) exit;

/**
 * Obsidian Bridge — exposes poll data in WP REST API as custom fields
 * so Obsidian (via Hermes) can sync poll state bidirectionally.
 *
 * The bridge works by:
 * 1. Exposing poll options/status/settings as REST fields on the freer_poll CPT
 * 2. The /freer-polls/v1/sync endpoint accepts batch creates/updates (see Freer_Poll_REST)
 * 3. Hermes reads poll results via /freer-polls/v1/polls/{id}/results
 * 4. Hermes can write poll definitions from Obsidian frontmatter via /freer-polls/v1/sync
 */
class Freer_Poll_Bridge {

    public static function register_fields() {
        // Expose poll meta in REST for Obsidian read access
        register_rest_field('freer_poll', 'freer_polls_options', array(
            'get_callback' => array(self::class, 'get_options_field'),
            'update_callback' => array(self::class, 'update_options_field'),
            'schema' => array(
                'type' => 'array',
                'items' => array('type' => 'string'),
                'description' => 'Poll answer options',
            ),
        ));

        register_rest_field('freer_poll', 'freer_polls_status', array(
            'get_callback' => array(self::class, 'get_status_field'),
            'update_callback' => array(self::class, 'update_status_field'),
            'schema' => array(
                'type' => 'string',
                'enum' => array('open', 'closed'),
                'description' => 'Poll status',
            ),
        ));

        register_rest_field('freer_poll', 'freer_polls_results', array(
            'get_callback' => array(self::class, 'get_results_field'),
            'schema' => array(
                'type' => 'object',
                'description' => 'Current vote results',
            ),
        ));

        register_rest_field('freer_poll', 'freer_polls_settings', array(
            'get_callback' => array(self::class, 'get_settings_field'),
            'update_callback' => array(self::class, 'update_settings_field'),
            'schema' => array(
                'type' => 'object',
                'description' => 'Poll display settings',
            ),
        ));

        register_rest_field('freer_poll', 'freer_polls_shortcode', array(
            'get_callback' => array(self::class, 'get_shortcode_field'),
            'schema' => array(
                'type' => 'string',
                'description' => 'Shortcode for embedding this poll',
            ),
        ));
    }

    // Options
    public static function get_options_field($post) {
        return Freer_Poll_Meta::get_options($post['id']);
    }

    public static function update_options_field($value, $post) {
        if (is_array($value)) {
            $options = array_slice(array_map('sanitize_text_field', $value), 0, 20);
            update_post_meta($post->ID, '_freer_polls_options', $options);
        }
    }

    // Status
    public static function get_status_field($post) {
        return Freer_Poll_Meta::get_status($post['id']);
    }

    public static function update_status_field($value, $post) {
        update_post_meta($post->ID, '_freer_polls_status', sanitize_key($value));
    }

    // Results (read-only)
    public static function get_results_field($post) {
        return Freer_Poll_DB::get_results($post['id']);
    }

    // Settings
    public static function get_settings_field($post) {
        return Freer_Poll_Meta::get_display_settings($post['id']);
    }

    public static function update_settings_field($value, $post) {
        if (!is_array($value)) return;

        if (isset($value['show_results'])) {
            update_post_meta($post->ID, '_freer_polls_show_results', sanitize_key($value['show_results']));
        }
        if (isset($value['vote_type'])) {
            update_post_meta($post->ID, '_freer_polls_vote_type', sanitize_key($value['vote_type']));
        }
        if (isset($value['allow_comments'])) {
            update_post_meta($post->ID, '_freer_polls_allow_comments', (int) $value['allow_comments']);
        }
    }

    // Shortcode (read-only convenience)
    public static function get_shortcode_field($post) {
        return '[freer_poll id="' . $post['id'] . '"]';
    }
}