<?php
if (!defined('ABSPATH')) exit;

/**
 * Comments support for freer_poll post type.
 * Uses native WP comments but restricts to poll CPT only.
 */
class Freer_Poll_Comments {

    public static function register() {
        // Enable comments support on the CPT (already added via 'supports' => array('comments'))
        // But we need to override the default comment status
        add_filter('comments_open', array(self::class, 'comments_open'), 10, 2);
        add_filter('pings_open', '__return_false');

        // AJAX handlers for frontend comment submission
        add_action('wp_ajax_nopriv_freer_polls_post_comment', array(self::class, 'ajax_post_comment'));
        add_action('wp_ajax_freer_polls_post_comment', array(self::class, 'ajax_post_comment'));
    }

    public static function comments_open($open, $post_id) {
        $post = get_post($post_id);
        if ($post && $post->post_type === 'freer_poll') {
            $settings = Freer_Poll_Meta::get_display_settings($post_id);
            return (bool) $settings['allow_comments'];
        }
        return $open;
    }

    public static function ajax_post_comment() {
        check_ajax_referer('freer_polls_rest', 'nonce');

        $poll_id = intval($_POST['poll_id']);
        $content = isset($_POST['content']) ? trim(wp_unslash($_POST['content'])) : '';
        $author = isset($_POST['author']) ? sanitize_text_field(wp_unslash($_POST['author'])) : 'Anonymous';
        $honeypot = isset($_POST['website']) ? trim(wp_unslash($_POST['website'])) : '';

        // Honeypot
        if (!empty($honeypot)) {
            wp_send_json_error(array('message' => 'Spam detected.'), 403);
        }

        if (empty($content)) {
            wp_send_json_error(array('message' => 'Comment cannot be empty.'), 400);
        }

        $poll = get_post($poll_id);
        if (!$poll || $poll->post_type !== 'freer_poll') {
            wp_send_json_error(array('message' => 'Poll not found.'), 404);
        }

        $settings = Freer_Poll_Meta::get_display_settings($poll_id);
        if (!$settings['allow_comments']) {
            wp_send_json_error(array('message' => 'Comments are disabled for this poll.'), 403);
        }

        $comment_data = array(
            'comment_post_ID' => $poll_id,
            'comment_author' => $author,
            'comment_content' => sanitize_textarea_field($content),
            'comment_approved' => 1,
            'comment_type' => 'comment',
        );

        $comment_id = wp_insert_comment($comment_data);
        if (!$comment_id) {
            wp_send_json_error(array('message' => 'Failed to post comment.'), 500);
        }

        wp_send_json_success(array(
            'comment_id' => $comment_id,
            'author' => $author,
            'content' => sanitize_textarea_field($content),
            'message' => 'Comment posted.',
        ));
    }
}