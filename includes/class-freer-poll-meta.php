<?php
if (!defined('ABSPATH')) exit;

class Freer_Poll_Meta {

    public static function add_meta_boxes() {
        add_meta_box(
            'freer_poll_config',
            'Poll Configuration',
            array(self::class, 'render_meta_box'),
            'freer_poll',
            'normal',
            'high'
        );
    }

    public static function render_meta_box($post) {
        wp_nonce_field('freer_poll_meta', 'freer_poll_nonce');

        $options = self::get_options($post->ID);
        $status = self::get_status($post->ID);
        $settings = self::get_display_settings($post->ID);

        ?>
        <div class="freer-poll-meta-wrap">
            <!-- Poll Options -->
            <div class="freer-poll-meta-section">
                <h4>Poll Options (one per line)</h4>
                <textarea name="freer_poll_options" id="freer_poll_options" rows="6" style="width:100%;" placeholder="Weekly Calls&#10;More Courses&#10;Digital Products"><?php
                    echo esc_textarea(implode("\n", $options));
                ?></textarea>
                <p class="description">Each line becomes a voteable option. Max 20 options.</p>
            </div>

            <!-- Poll Status -->
            <div class="freer-poll-meta-section">
                <h4>Poll Status</h4>
                <select name="freer_poll_status" id="freer_poll_status">
                    <option value="open" <?php selected($status, 'open'); ?>>Open (accepting votes)</option>
                    <option value="closed" <?php selected($status, 'closed'); ?>>Closed (no more votes)</option>
                </select>
            </div>

            <!-- Expiry -->
            <div class="freer-poll-meta-section">
                <h4>Auto-Close Date (optional)</h4>
                <input type="datetime-local" name="freer_poll_expiry" id="freer_poll_expiry" value="<?php echo esc_attr(self::get_expiry($post->ID)); ?>" />
                <p class="description">Leave blank for no auto-close. Poll will automatically close at this time.</p>
            </div>

            <!-- Display Settings -->
            <div class="freer-poll-meta-section">
                <h4>Results Visibility</h4>
                <select name="freer_polls_show_results">
                    <option value="after_vote" <?php selected($settings['show_results'], 'after_vote'); ?>>Show after voting</option>
                    <option value="always" <?php selected($settings['show_results'], 'always'); ?>>Always visible</option>
                    <option value="after_close" <?php selected($settings['show_results'], 'after_close'); ?>>Show only after poll closes</option>
                </select>
            </div>

            <div class="freer-poll-meta-section">
                <h4>Vote Type</h4>
                <select name="freer_polls_vote_type">
                    <option value="single" <?php selected($settings['vote_type'], 'single'); ?>>Single choice (pick one)</option>
                    <option value="multi" <?php selected($settings['vote_type'], 'multi'); ?>>Multiple choice (pick any)</option>
                </select>
            </div>

            <div class="freer-poll-meta-section">
                <h4>Allow Comments</h4>
                <input type="checkbox" name="freer_polls_allow_comments" value="1" <?php checked($settings['allow_comments'], 1); ?> />
                <label for="freer_polls_allow_comments">Enable discussion on this poll</label>
            </div>

            <!-- Vote count preview -->
            <div class="freer-poll-meta-section">
                <h4>Current Vote Count</h4>
                <p class="freer-poll-vote-count"><?php echo Freer_Poll_DB::get_total_votes($post->ID); ?> votes cast</p>
            </div>
        </div>
        <?php
    }

    public static function save_meta($post_id, $post) {
        if (!isset($_POST['freer_poll_nonce']) || !wp_verify_nonce($_POST['freer_poll_nonce'], 'freer_poll_meta')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        // Parse options
        $raw = isset($_POST['freer_poll_options']) ? $_POST['freer_poll_options'] : '';
        $lines = array_filter(array_map('trim', explode("\n", $raw)));
        $options = array_slice($lines, 0, 20);
        update_post_meta($post_id, '_freer_polls_options', $options);

        // Status
        $status = isset($_POST['freer_poll_status']) ? sanitize_key($_POST['freer_poll_status']) : 'open';
        update_post_meta($post_id, '_freer_polls_status', $status);

        // Expiry
        $expiry = isset($_POST['freer_poll_expiry']) ? sanitize_text_field($_POST['freer_poll_expiry']) : '';
        update_post_meta($post_id, '_freer_polls_expiry', $expiry);

        // Display settings
        $show_results = isset($_POST['freer_polls_show_results']) ? sanitize_key($_POST['freer_polls_show_results']) : 'after_vote';
        $vote_type = isset($_POST['freer_polls_vote_type']) ? sanitize_key($_POST['freer_polls_vote_type']) : 'single';
        $allow_comments = isset($_POST['freer_polls_allow_comments']) ? 1 : 0;

        update_post_meta($post_id, '_freer_polls_show_results', $show_results);
        update_post_meta($post_id, '_freer_polls_vote_type', $vote_type);
        update_post_meta($post_id, '_freer_polls_allow_comments', $allow_comments);

        // Sync comment status with WP native
        if ($allow_comments) {
            update_post_meta($post_id, '_comments_enabled', 1);
        } else {
            update_post_meta($post_id, '_comments_enabled', 0);
        }
    }

    public static function get_options($post_id) {
        $opts = get_post_meta($post_id, '_freer_polls_options', true);
        if (!is_array($opts)) return array();
        return $opts;
    }

    public static function get_status($post_id) {
        $status = get_post_meta($post_id, '_freer_polls_status', true);
        if (empty($status)) return 'open';

        // Check auto-close
        if ($status === 'open') {
            $expiry = self::get_expiry($post_id);
            if ($expiry && strtotime($expiry) < current_time('timestamp')) {
                update_post_meta($post_id, '_freer_polls_status', 'closed');
                return 'closed';
            }
        }
        return $status;
    }

    public static function get_expiry($post_id) {
        return get_post_meta($post_id, '_freer_polls_expiry', true);
    }

    public static function get_display_settings($post_id) {
        return array(
            'show_results' => get_post_meta($post_id, '_freer_polls_show_results', true) ?: 'after_vote',
            'vote_type' => get_post_meta($post_id, '_freer_polls_vote_type', true) ?: 'single',
            'allow_comments' => (int) (get_post_meta($post_id, '_freer_polls_allow_comments', true) ?: 1),
        );
    }

    public static function admin_columns($columns) {
        $new = array();
        foreach ($columns as $key => $val) {
            if ($key === 'date') {
                $new['freer_polls_votes'] = 'Votes';
                $new['freer_polls_status'] = 'Status';
            }
            $new[$key] = $val;
        }
        return $new;
    }

    public static function admin_column_content($column, $post_id) {
        if ($column === 'freer_polls_votes') {
            echo Freer_Poll_DB::get_total_votes($post_id);
        } elseif ($column === 'freer_polls_status') {
            $status = self::get_status($post_id);
            $color = $status === 'open' ? '#22c55e' : '#6b7280';
            echo '<span style="color:' . $color . '; font-weight:600;">' . ucfirst($status) . '</span>';
        }
    }
}