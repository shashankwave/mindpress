<?php
/**
 * Plugin Name: MindPress – Mind Map to Post
 * Description: Plan blog posts with a simple mind map in WP Admin. Save as JSON and generate a draft post from the map.
 * Version: 0.1.2
 * Author: You
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * Text Domain: mindpress
 */

if (!defined('ABSPATH')) { exit; }

class MindPressPlugin {
    const CPT   = 'mp_mindmap';
    const NONCE = 'mp_nonce';

    public function __construct() {
        add_action('init',                       [$this, 'register_cpt']);
        add_action('add_meta_boxes',             [$this, 'add_metabox']);
        add_action('admin_enqueue_scripts',      [$this, 'enqueue']);          // load CSS/JS
        add_action('save_post',                  [$this, 'save'], 10, 2);
        add_action('wp_ajax_mp_generate_post',   [$this, 'ajax_generate_post']);
    }

    public function register_cpt() {
        register_post_type(self::CPT, [
            'labels' => [
                'name'          => __('Mind Maps', 'mindpress'),
                'singular_name' => __('Mind Map', 'mindpress'),
                'add_new_item'  => __('Add Mind Map', 'mindpress'),
            ],
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => true,
            'menu_icon'    => 'dashicons-networking',
            'supports'     => ['title'],
        ]);
    }

    // Show the metabox on both Mind Maps and regular Posts
    public function add_metabox() {
        add_meta_box(
            'mp_builder',
            __('Mind Map Builder', 'mindpress'),
            [$this, 'render_metabox'],
            [ self::CPT, 'post' ],
            'normal',
            'high'
        );
    }

    // Robust enqueue: use get_current_screen() (works with Classic/Gutenberg)
    public function enqueue($hook) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        // Only load on edit screens where a post is present
        if ($screen && in_array($screen->base, ['post', 'post-new'], true)) {
            $ptype = isset($screen->post_type) ? $screen->post_type : '';
            if (in_array($ptype, [ self::CPT, 'post' ], true)) {
                wp_enqueue_style ('mp-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', [], '0.1.2');
                wp_enqueue_script('mp-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', ['jquery'], '0.1.2', true);
                wp_localize_script('mp-admin', 'MindPress', [
                    'nonce' => wp_create_nonce(self::NONCE),
                    'ajax'  => admin_url('admin-ajax.php'),
                    'i18n'  => [
                        'root'     => __('Root Idea', 'mindpress'),
                        'addChild' => __('Add child', 'mindpress'),
                        'delete'   => __('Delete', 'mindpress'),
                        'generate' => __('Generate Draft Post', 'mindpress'),
                    ],
                ]);
            }
        }
    }

    public function render_metabox($post) {
        $json = get_post_meta($post->ID, '_mp_tree', true);
        wp_nonce_field(self::NONCE, self::NONCE);
        echo '<div id="mp-app" class="mp-wrap" data-json="' . esc_attr($json ?: '') . '"></div>';
        echo '<input type="hidden" name="_mp_tree" id="_mp_tree" value="' . esc_attr($json ?: '') . '" />';
        echo '<p><button type="button" class="button button-primary" id="mp-generate">' . esc_html__('Generate Draft Post', 'mindpress') . '</button></p>';
        echo '<p class="description">' . esc_html__('Tip: Use the tree to outline your post. Save the mind map, then click Generate to create a draft blog post with headings.', 'mindpress') . '</p>';
    }

    public function save($post_id, $post) {
        if (!in_array($post->post_type, [ self::CPT, 'post' ], true)) return;
        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce($_POST[self::NONCE], self::NONCE)) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $json = isset($_POST['_mp_tree']) ? wp_unslash($_POST['_mp_tree']) : '';
        if ($json) update_post_meta($post_id, '_mp_tree', $json);
        else       delete_post_meta($post_id, '_mp_tree');
    }

    public function ajax_generate_post() {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error(['message' => 'Permission denied'], 403);

        $map   = isset($_POST['tree']) ? json_decode(stripslashes($_POST['tree']), true) : null;
        $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : 'MindPress Draft';
        if (!$map || !is_array($map)) wp_send_json_error(['message' => 'Invalid map']);

        $content = $this->tree_to_content($map);
        $post_id = wp_insert_post([
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => 'draft',
            'post_type'    => 'post',
        ]);

        if (is_wp_error($post_id)) {
            wp_send_json_error(['message' => $post_id->get_error_message()]);
        }
        wp_send_json_success(['post_id' => $post_id, 'edit_link' => get_edit_post_link($post_id, 'raw')]);
    }

    private function tree_to_content($tree) {
        $lines = [];
        $walk = function($node, $level) use (&$walk, &$lines) {
            $text = isset($node['text']) ? wp_strip_all_tags($node['text']) : '';
            if ($text !== '') {
                $prefix  = str_repeat('#', min(6, $level + 1));
                $lines[] = $prefix . ' ' . $text;
                if (!empty($node['notes'])) {
                    $lines[] = '';
                    $lines[] = $node['notes'];
                }
                $lines[] = '';
            }
            if (!empty($node['children']) && is_array($node['children'])) {
                foreach ($node['children'] as $child) $walk($child, $level + 1);
            }
        };
        $walk($tree, 1);
        $md = implode("\n", $lines);
        return $this->markdown_like_to_html($md);
    }

    private function markdown_like_to_html($md) {
        $html  = '';
        $lines = preg_split('/\r?\n/', $md);
        foreach ($lines as $line) {
            if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
                $h    = strlen($m[1]);
                $html .= '<h' . $h . '>' . esc_html($m[2]) . '</h' . $h . '>';
            } elseif (trim($line) === '') {
                $html .= "\n";
            } else {
                $html .= '<p>' . esc_html($line) . '</p>';
            }
        }
        return $html;
    }
}

new MindPressPlugin();
