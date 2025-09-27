<?php
/**
 * Plugin Name: MindPress – Mind Map to Post
 * Description: Plan blog posts visually in WP Admin. Autosaves the map, can generate a draft or insert into the current post.
 * Version: 0.2.3
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
        add_action('init',                         [$this, 'register_cpt']);
        add_action('add_meta_boxes',               [$this, 'add_metabox']);
        add_action('admin_enqueue_scripts',        [$this, 'enqueue']);
        add_action('save_post',                    [$this, 'save'], 10, 2);
        add_action('wp_ajax_mp_save_tree',         [$this, 'ajax_save_tree']);    // autosave
        add_action('wp_ajax_mp_get_tree',          [$this, 'ajax_get_tree']);     // load fallback
        add_action('wp_ajax_mp_generate_post',     [$this, 'ajax_generate_post']);
        add_action('wp_ajax_mp_insert_into',       [$this, 'ajax_insert_into']);
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

    public function enqueue($hook) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && in_array($screen->base, ['post', 'post-new'], true)) {
            $ptype = isset($screen->post_type) ? $screen->post_type : '';
            if (in_array($ptype, [ self::CPT, 'post' ], true)) {
                wp_enqueue_style ('mp-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', [], '0.2.3');
                wp_enqueue_script('mp-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', ['jquery'], '0.2.3', true);

                // Use $post global only; don't touch $_GET to avoid nonce warnings
                $post_id = 0;
                $post    = get_post();
                if ($post) { $post_id = (int) $post->ID; }

                wp_localize_script('mp-admin', 'MindPress', [
                    'nonce'  => wp_create_nonce(self::NONCE),
                    'ajax'   => admin_url('admin-ajax.php'),
                    'postId' => $post_id,
                    'ptype'  => $post ? $post->post_type : $ptype,
                    'i18n'   => [
                        'root'       => __('Root Idea', 'mindpress'),
                        'addChild'   => __('Add child', 'mindpress'),
                        'addSibling' => __('Add sibling', 'mindpress'),
                        'delete'     => __('Delete', 'mindpress'),
                        'generate'   => __('Generate Draft Post', 'mindpress'),
                        'insert'     => __('Insert into this post', 'mindpress'),
                        'search'     => __('Search...', 'mindpress'),
                        'exported'   => __('Exported JSON downloaded', 'mindpress'),
                        'importErr'  => __('Invalid JSON', 'mindpress'),
                        'saving'     => __('Saving…', 'mindpress'),
                        'saved'      => __('Saved ✓', 'mindpress'),
                    ],
                ]);
            }
        }
    }

    public function render_metabox($post) {
        $json = get_post_meta($post->ID, '_mp_tree', true);
        wp_nonce_field(self::NONCE, self::NONCE);

        echo '<div class="mp-toolbar">
                <input type="text" id="mp-search" class="mp-search" placeholder="'.esc_attr__('Search...', 'mindpress').'" />
                <div class="mp-toolbar-buttons">
                    <button type="button" class="button" id="mp-expand-all">Expand all</button>
                    <button type="button" class="button" id="mp-collapse-all">Collapse all</button>
                    <button type="button" class="button" id="mp-export">Export</button>
                    <button type="button" class="button" id="mp-import">Import</button>
                    <span class="mp-status" id="mp-status" aria-live="polite"></span>
                </div>
              </div>';

        echo '<div id="mp-app" class="mp-wrap" data-json="' . esc_attr($json ?: '') . '"></div>';
        echo '<input type="hidden" name="_mp_tree" id="_mp_tree" value="' . esc_attr($json ?: '') . '" />';

        echo '<p class="mp-actions">';
        echo '<button type="button" class="button button-primary" id="mp-generate">' . esc_html__('Generate Draft Post', 'mindpress') . '</button> ';
        if ($post->post_type === 'post') {
            echo '<button type="button" class="button" id="mp-insert">' . esc_html__('Insert into this post', 'mindpress') . '</button> ';
        }
        echo '</p>';
        echo '<p class="description">'.esc_html__('Tip: Outline visually. Autosave is on. Use Generate to create a new draft, or Insert to replace current post content.', 'mindpress').'</p>';
    }

    /** Fallback save when clicking Update/Publish */
    public function save($post_id, $post) {
        if (!in_array($post->post_type, [ self::CPT, 'post' ], true)) return;

        $nonce = isset($_POST[self::NONCE]) ? sanitize_text_field( wp_unslash($_POST[self::NONCE]) ) : '';
        if (!$nonce || !wp_verify_nonce($nonce, self::NONCE)) return;

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $json_raw = isset($_POST['_mp_tree']) ? wp_unslash($_POST['_mp_tree']) : '';
        if ($json_raw !== '') {
            $arr = json_decode($json_raw, true);
            if (is_array($arr)) {
                update_post_meta($post_id, '_mp_tree', wp_json_encode($arr));
            } else {
                // invalid JSON; remove to avoid storing unsafe data
                delete_post_meta($post_id, '_mp_tree');
            }
        } else {
            delete_post_meta($post_id, '_mp_tree');
        }
    }

    /** AUTOSAVE endpoint */
    public function ajax_save_tree() {
        check_ajax_referer(self::NONCE, 'nonce');

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
        }

        $raw = isset($_POST['tree']) ? wp_unslash($_POST['tree']) : '';
        $arr = json_decode($raw, true);
        if (!is_array($arr)) {
            wp_send_json_error(['message' => 'Invalid JSON'], 400);
        }

        update_post_meta($post_id, '_mp_tree', wp_json_encode($arr));
        wp_send_json_success(['ok' => true]);
    }

    /** LOAD fallback for existing data */
    public function ajax_get_tree() {
        check_ajax_referer(self::NONCE, 'nonce');

        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
        }

        $tree = get_post_meta($post_id, '_mp_tree', true);
        wp_send_json_success(['tree' => $tree]);
    }

    public function ajax_generate_post() {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
        }

        $raw_tree = isset($_POST['tree']) ? wp_unslash($_POST['tree']) : '';
        $map      = json_decode($raw_tree, true);
        $title    = isset($_POST['title']) ? sanitize_text_field( wp_unslash($_POST['title']) ) : 'MindPress Draft';

        if (!is_array($map)) {
            wp_send_json_error(['message' => 'Invalid map'], 400);
        }

        $content = $this->tree_to_content($map);
        $post_id = wp_insert_post([
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => 'draft',
            'post_type'    => 'post',
        ], true);

        if (is_wp_error($post_id)) {
            wp_send_json_error(['message' => $post_id->get_error_message()]);
        }
        wp_send_json_success(['post_id' => $post_id, 'edit_link' => get_edit_post_link($post_id, 'raw')]);
    }

    public function ajax_insert_into() {
        check_ajax_referer(self::NONCE, 'nonce');

        $post_id  = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $raw_tree = isset($_POST['tree']) ? wp_unslash($_POST['tree']) : '';
        $map      = json_decode($raw_tree, true);

        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
        }
        if (!is_array($map)) {
            wp_send_json_error(['message' => 'Invalid map'], 400);
        }

        $content = $this->tree_to_content($map);
        $res = wp_update_post([
            'ID'           => $post_id,
            'post_content' => $content,
        ], true);

        if (is_wp_error($res)) {
            wp_send_json_error(['message' => $res->get_error_message()]);
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
                    $lines[] = wp_strip_all_tags($node['notes']);
                }
                $lines[] = '';
            }
            if (!empty($node['children']) && is_array($node['children'])) {
                foreach ($node['children'] as $child) {
                    $walk($child, $level + 1);
                }
            }
        };
        $walk($tree, 1);
        return $this->markdown_like_to_html(implode("\n", $lines));
    }

    private function markdown_like_to_html($md) {
        $html  = '';
        foreach (preg_split('/\r?\n/', $md) as $line) {
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
