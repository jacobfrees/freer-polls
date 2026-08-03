<?php
if (!defined('ABSPATH')) exit;

class JFP_CPT {

    public static function register() {
        register_post_type('jf_poll', array(
            'labels' => array(
                'name' => 'Polls',
                'singular_name' => 'Poll',
                'add_new' => 'Create Poll',
                'add_new_item' => 'Create New Poll',
                'edit_item' => 'Edit Poll',
                'new_item' => 'New Poll',
                'view_item' => 'View Poll',
                'search_items' => 'Search Polls',
                'not_found' => 'No polls found',
                'not_found_in_trash' => 'No polls in trash',
                'all_items' => 'All Polls',
                'menu_name' => 'Evolves Polls',
            ),
            'public' => true,
            'has_archive' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_rest' => true,
            'menu_icon' => 'dashicons-chart-bar',
            'menu_position' => 8,
            'supports' => array('title', 'editor', 'comments', 'excerpt'),
            'rewrite' => false,
            'capability_type' => 'post',
        ));
    }
}