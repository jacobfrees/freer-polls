<?php
if (!defined('ABSPATH')) exit;

class JFP_Shortcode {

    public static function render($atts) {
        $atts = shortcode_atts(array(
            'id' => 0,
        ), $atts, 'jf_poll');

        $poll_id = intval($atts['id']);
        if (!$poll_id) return '<p class="jfp-error">No poll ID specified.</p>';

        $poll = get_post($poll_id);
        if (!$poll || $poll->post_type !== 'jf_poll' || $poll->post_status !== 'publish') {
            return '<p class="jfp-error">Poll not found.</p>';
        }

        $options = JFP_Meta::get_options($poll_id);
        if (empty($options)) return '<p class="jfp-error">This poll has no options.</p>';

        $status = JFP_Meta::get_status($poll_id);
        $settings = JFP_Meta::get_display_settings($poll_id);
        $has_voted = JFP_DB::has_voted($poll_id);
        $results = JFP_DB::get_results($poll_id);

        // Determine if results should be shown
        $show_results = false;
        if ($settings['show_results'] === 'always') {
            $show_results = true;
        } elseif ($settings['show_results'] === 'after_vote' && $has_voted) {
            $show_results = true;
        } elseif ($settings['show_results'] === 'after_close' && $status === 'closed') {
            $show_results = true;
        }

        // Build HTML
        $html = '<div class="jfp-poll" data-poll-id="' . esc_attr($poll_id) . '" data-vote-type="' . esc_attr($settings['vote_type']) . '">';

        // Question
        $html .= '<h3 class="jfp-poll-question">' . esc_html($poll->post_title) . '</h3>';

        // Status badge
        if ($status === 'closed') {
            $html .= '<span class="jfp-poll-badge jfp-badge-closed">Closed</span>';
        }

        // Excerpt / description
        if ($poll->post_excerpt) {
            $html .= '<p class="jfp-poll-desc">' . esc_html($poll->post_excerpt) . '</p>';
        }

        // Vote form (only if open + hasn't voted)
        if ($status === 'open' && !$has_voted) {
            $html .= '<div class="jfp-poll-options" data-nonce="' . wp_create_nonce('jf_poll_vote_' . $poll_id) . '">';
            $input_type = $settings['vote_type'] === 'multi' ? 'checkbox' : 'radio';
            $input_name = 'jfp_choice_' . $poll_id;

            foreach ($options as $idx => $label) {
                $html .= '<button type="button" class="jfp-poll-option" data-choice="' . esc_attr($idx) . '" data-poll="' . esc_attr($poll_id) . '">';
                $html .= '<span class="jfp-poll-option-label">' . esc_html($label) . '</span>';
                $html .= '<span class="jfp-poll-option-check">' . ($settings['vote_type'] === 'multi' ? '☐' : '○') . '</span>';
                $html .= '</button>';
            }
            $html .= '</div>';

            // Submit button
            $html .= '<button type="button" class="jfp-poll-submit jfp-btn-primary" data-poll="' . esc_attr($poll_id) . '">Vote</button>';
        } elseif ($status === 'open' && $has_voted) {
            $html .= '<p class="jfp-poll-voted-msg">✓ You voted. Here are the current results.</p>';
        }

        // Results
        if ($show_results) {
            $html .= self::render_results($results, $has_voted);
        }

        // Comments
        if ($settings['allow_comments']) {
            $html .= self::render_comments_section($poll_id);
        }

        $html .= '</div>';

        return $html;
    }

    private static function render_results($results, $has_voted) {
        if (empty($results) || $results['total'] === 0) {
            return '<div class="jfp-poll-results"><p class="jfp-no-votes">No votes yet. Be the first.</p></div>';
        }

        $html = '<div class="jfp-poll-results">';
        $html .= '<div class="jfp-poll-total">' . $results['total'] . ' votes</div>';

        // Find max for bar scaling
        $max_votes = 0;
        foreach ($results['options'] as $opt) {
            if ($opt['votes'] > $max_votes) $max_votes = $opt['votes'];
        }

        foreach ($results['options'] as $opt) {
            $bar_width = $max_votes > 0 ? round(($opt['votes'] / $max_votes) * 100, 1) : 0;
            $is_leader = $opt['votes'] === $max_votes && $opt['votes'] > 0;

            $html .= '<div class="jfp-result-row' . ($is_leader ? ' jfp-result-leader' : '') . '">';
            $html .= '<div class="jfp-result-label">' . esc_html($opt['label']) . '</div>';
            $html .= '<div class="jfp-result-bar-wrap">';
            $html .= '<div class="jfp-result-bar" style="width:' . esc_attr($bar_width) . '%"></div>';
            $html .= '<span class="jfp-result-pct">' . esc_html($opt['percentage']) . '%</span>';
            $html .= '<span class="jfp-result-count">(' . $opt['votes'] . ')</span>';
            $html .= '</div>';
            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    private static function render_comments_section($poll_id) {
        $html = '<div class="jfp-poll-comments" data-poll-id="' . esc_attr($poll_id) . '">';
        $html .= '<h4 class="jfp-comments-title">Discussion <span class="jfp-comments-count">' . get_comments_number($poll_id) . '</span></h4>';

        // Comments list
        $comments = get_comments(array(
            'post_id' => $poll_id,
            'status' => 'approve',
            'order' => 'ASC',
        ));

        $html .= '<div class="jfp-comments-list">';
        if (empty($comments)) {
            $html .= '<p class="jfp-no-comments">No comments yet. Start the conversation.</p>';
        } else {
            wp_list_comments(array(
                'style' => 'div',
                'short_ping' => true,
                'avatar_size' => 32,
            ), $comments);
        }
        $html .= '</div>';

        // Comment form
        $html .= '<div class="jfp-comment-form">';
        $html .= '<textarea class="jfp-comment-input" placeholder="Share your thoughts..." rows="3"></textarea>';
        $html .= '<div class="jfp-comment-form-row">';
        $html .= '<input type="text" class="jfp-comment-name" placeholder="Your name (optional)" />';
        $html .= '<button type="button" class="jfp-comment-submit jfp-btn-ghost" data-poll="' . esc_attr($poll_id) . '">Post Comment</button>';
        $html .= '</div>';
        $html .= '<p class="jfp-comment-status"></p>';
        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }
}