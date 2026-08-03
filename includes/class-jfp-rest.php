<?php
if (!defined('ABSPATH')) exit;

class JFP_REST {

    const NAMESPACE = 'jf-polls/v1';

    public static function register_routes() {
        // List polls
        register_rest_route(self::NAMESPACE, '/polls', array(
            'methods' => 'GET',
            'callback' => array(self::class, 'get_polls'),
            'permission_callback' => '__return_true',
        ));

        // Get single poll
        register_rest_route(self::NAMESPACE, '/polls/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array(self::class, 'get_poll'),
            'permission_callback' => '__return_true',
        ));

        // Create poll (requires auth)
        register_rest_route(self::NAMESPACE, '/polls', array(
            'methods' => 'POST',
            'callback' => array(self::class, 'create_poll'),
            'permission_callback' => array(self::class, 'check_auth'),
        ));

        // Update poll (requires auth)
        register_rest_route(self::NAMESPACE, '/polls/(?P<id>\d+)', array(
            'methods' => 'PUT',
            'callback' => array(self::class, 'update_poll'),
            'permission_callback' => array(self::class, 'check_auth'),
        ));

        // Delete poll (requires auth)
        register_rest_route(self::NAMESPACE, '/polls/(?P<id>\d+)', array(
            'methods' => 'DELETE',
            'callback' => array(self::class, 'delete_poll'),
            'permission_callback' => array(self::class, 'check_auth'),
        ));

        // Cast vote (open)
        register_rest_route(self::NAMESPACE, '/polls/(?P<id>\d+)/vote', array(
            'methods' => 'POST',
            'callback' => array(self::class, 'cast_vote'),
            'permission_callback' => '__return_true',
        ));

        // Get results (open)
        register_rest_route(self::NAMESPACE, '/polls/(?P<id>\d+)/results', array(
            'methods' => 'GET',
            'callback' => array(self::class, 'get_results'),
            'permission_callback' => '__return_true',
        ));

        // Get comments (open)
        register_rest_route(self::NAMESPACE, '/polls/(?P<id>\d+)/comments', array(
            'methods' => 'GET',
            'callback' => array(self::class, 'get_comments'),
            'permission_callback' => '__return_true',
        ));

        // Post comment (open, with honeypot)
        register_rest_route(self::NAMESPACE, '/polls/(?P<id>\d+)/comments', array(
            'methods' => 'POST',
            'callback' => array(self::class, 'post_comment'),
            'permission_callback' => '__return_true',
        ));

        // Sync from Obsidian (requires auth — batch create/update)
        register_rest_route(self::NAMESPACE, '/sync', array(
            'methods' => 'POST',
            'callback' => array(self::class, 'obsidian_sync'),
            'permission_callback' => array(self::class, 'check_auth'),
        ));
    }

    public static function check_auth($request) {
        return current_user_can('edit_posts');
    }

    public static function get_polls($request) {
        $query = new WP_Query(array(
            'post_type' => 'jf_poll',
            'post_status' => 'publish',
            'posts_per_page' => 50,
            'orderby' => 'date',
            'order' => 'DESC',
        ));

        $polls = array();
        foreach ($query->posts as $poll) {
            $polls[] = self::poll_to_array($poll);
        }

        return rest_ensure_response($polls);
    }

    public static function get_poll($request) {
        $id = intval($request['id']);
        $poll = get_post($id);
        if (!$poll || $poll->post_type !== 'jf_poll') {
            return new WP_Error('not_found', 'Poll not found', array('status' => 404));
        }
        return rest_ensure_response(self::poll_to_array($poll));
    }

    public static function create_poll($request) {
        $params = $request->get_json_params();

        if (empty($params['title'])) {
            return new WP_Error('missing_title', 'Title is required', array('status' => 400));
        }

        $poll_id = wp_insert_post(array(
            'post_title' => sanitize_text_field($params['title']),
            'post_excerpt' => isset($params['description']) ? sanitize_textarea_field($params['description']) : '',
            'post_status' => 'publish',
            'post_type' => 'jf_poll',
        ));

        if (is_wp_error($poll_id)) {
            return $poll_id;
        }

        self::apply_poll_meta($poll_id, $params);

        return rest_ensure_response(self::poll_to_array(get_post($poll_id)));
    }

    public static function update_poll($request) {
        $id = intval($request['id']);
        $poll = get_post($id);
        if (!$poll || $poll->post_type !== 'jf_poll') {
            return new WP_Error('not_found', 'Poll not found', array('status' => 404));
        }

        $params = $request->get_json_params();

        $update_data = array('ID' => $id);
        if (isset($params['title'])) $update_data['post_title'] = sanitize_text_field($params['title']);
        if (isset($params['description'])) $update_data['post_excerpt'] = sanitize_textarea_field($params['description']);

        wp_update_post($update_data);
        self::apply_poll_meta($id, $params);

        return rest_ensure_response(self::poll_to_array(get_post($id)));
    }

    public static function delete_poll($request) {
        $id = intval($request['id']);
        $poll = get_post($id);
        if (!$poll || $poll->post_type !== 'jf_poll') {
            return new WP_Error('not_found', 'Poll not found', array('status' => 404));
        }

        global $wpdb;
        $table = JFP_DB::table_name();
        $wpdb->delete($table, array('poll_id' => $id), array('%d'));

        wp_delete_post($id, true);

        return rest_ensure_response(array('success' => true, 'message' => 'Poll deleted.'));
    }

    public static function cast_vote($request) {
        $id = intval($request['id']);
        $params = $request->get_json_params();
        $choices = isset($params['choices']) ? $params['choices'] : (isset($params['choice']) ? array($params['choice']) : array());

        // Verify nonce
        $nonce = $request->get_header('x-jfp-nonce');
        if (!wp_verify_nonce($nonce, 'jf_poll_vote_' . $id)) {
            return new WP_Error('invalid_nonce', 'Invalid nonce', array('status' => 403));
        }

        $result = JFP_DB::cast_vote($id, $choices);
        return rest_ensure_response($result);
    }

    public static function get_results($request) {
        $id = intval($request['id']);
        $poll = get_post($id);
        if (!$poll || $poll->post_type !== 'jf_poll') {
            return new WP_Error('not_found', 'Poll not found', array('status' => 404));
        }

        return rest_ensure_response(JFP_DB::get_results($id));
    }

    public static function get_comments($request) {
        $id = intval($request['id']);
        $comments = get_comments(array(
            'post_id' => $id,
            'status' => 'approve',
            'orderby' => 'comment_date',
            'order' => 'ASC',
        ));

        $out = array();
        foreach ($comments as $c) {
            $out[] = array(
                'id' => (int) $c->comment_ID,
                'author' => $c->comment_author,
                'content' => $c->comment_content,
                'date' => $c->comment_date,
                'parent' => (int) $c->comment_parent,
            );
        }

        return rest_ensure_response($out);
    }

    public static function post_comment($request) {
        $id = intval($request['id']);
        $params = $request->get_json_params();

        // Honeypot check
        if (!empty($params['website'])) {
            return new WP_Error('spam_detected', 'Spam detected', array('status' => 403));
        }

        $content = isset($params['content']) ? trim($params['content']) : '';
        if (empty($content)) {
            return new WP_Error('empty_comment', 'Comment cannot be empty', array('status' => 400));
        }

        $author = isset($params['author']) ? sanitize_text_field($params['author']) : 'Anonymous';
        $author = empty($author) ? 'Anonymous' : $author;

        $comment_data = array(
            'comment_post_ID' => $id,
            'comment_author' => $author,
            'comment_content' => sanitize_textarea_field($content),
            'comment_approved' => 1,
            'comment_type' => 'comment',
        );

        $comment_id = wp_insert_comment($comment_data);
        if (!$comment_id) {
            return new WP_Error('comment_failed', 'Failed to post comment', array('status' => 500));
        }

        return rest_ensure_response(array(
            'success' => true,
            'comment_id' => $comment_id,
            'message' => 'Comment posted.',
        ));
    }

    /**
     * Obsidian sync endpoint — batch create/update polls from Obsidian frontmatter.
     */
    public static function obsidian_sync($request) {
        $params = $request->get_json_params();
        $polls = isset($params['polls']) ? $params['polls'] : array();
        $results = array();

        foreach ($polls as $p) {
            // Check if poll exists by slug or ID
            $existing_id = isset($p['id']) ? intval($p['id']) : 0;

            if ($existing_id) {
                $existing = get_post($existing_id);
                if ($existing && $existing->post_type === 'jf_poll') {
                    wp_update_post(array(
                        'ID' => $existing_id,
                        'post_title' => sanitize_text_field($p['question']),
                        'post_excerpt' => isset($p['description']) ? sanitize_textarea_field($p['description']) : '',
                    ));
                    self::apply_poll_meta($existing_id, $p);
                    $results[] = array('id' => $existing_id, 'status' => 'updated');
                    continue;
                }
            }

            // Create new
            $new_id = wp_insert_post(array(
                'post_title' => sanitize_text_field($p['question']),
                'post_excerpt' => isset($p['description']) ? sanitize_textarea_field($p['description']) : '',
                'post_status' => 'publish',
                'post_type' => 'jf_poll',
            ));

            if (!is_wp_error($new_id)) {
                self::apply_poll_meta($new_id, $p);
                $results[] = array('id' => $new_id, 'status' => 'created');
            } else {
                $results[] = array('error' => $new_id->get_error_message(), 'question' => $p['question']);
            }
        }

        return rest_ensure_response(array(
            'success' => true,
            'synced' => count($results),
            'results' => $results,
        ));
    }

    // Helpers

    private static function apply_poll_meta($poll_id, $params) {
        if (isset($params['options']) && is_array($params['options'])) {
            $options = array_slice(array_map('sanitize_text_field', $params['options']), 0, 20);
            update_post_meta($poll_id, '_jfp_options', $options);
        }

        if (isset($params['status'])) {
            update_post_meta($poll_id, '_jfp_status', sanitize_key($params['status']));
        }

        if (isset($params['expiry'])) {
            update_post_meta($poll_id, '_jfp_expiry', sanitize_text_field($params['expiry']));
        }

        if (isset($params['show_results'])) {
            update_post_meta($poll_id, '_jfp_show_results', sanitize_key($params['show_results']));
        }

        if (isset($params['vote_type'])) {
            update_post_meta($poll_id, '_jfp_vote_type', sanitize_key($params['vote_type']));
        }

        if (isset($params['allow_comments'])) {
            update_post_meta($poll_id, '_jfp_allow_comments', (int) $params['allow_comments']);
        }
    }

    private static function poll_to_array($poll) {
        $options = JFP_Meta::get_options($poll->ID);
        $settings = JFP_Meta::get_display_settings($poll->ID);
        $status = JFP_Meta::get_status($poll->ID);
        $results = JFP_DB::get_results($poll->ID);

        return array(
            'id' => (int) $poll->ID,
            'title' => $poll->post_title,
            'description' => $poll->post_excerpt,
            'slug' => $poll->post_name,
            'status' => $status,
            'options' => $options,
            'expiry' => JFP_Meta::get_expiry($poll->ID),
            'show_results' => $settings['show_results'],
            'vote_type' => $settings['vote_type'],
            'allow_comments' => $settings['allow_comments'],
            'total_votes' => $results['total'],
            'results' => $results,
            'shortcode' => '[jf_poll id="' . $poll->ID . '"]',
            'date' => $poll->post_date,
        );
    }
}