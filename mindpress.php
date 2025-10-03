<?php
/**
 * Plugin Name: MindPress – Mind Map to Post
 * Description: Plan blog posts visually in WP Admin. Reuse a single mind map to generate unlimited new blog posts or insert into the current post.
 * Version:  0.4.0
 * Author: You
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * Text Domain: mindpress
 */
if (!defined('ABSPATH')) { exit; }

class MindPressPlugin {
    const CPT        = 'mp_mindmap';
    const NONCE      = 'mp_nonce';
    const META_TREE  = '_mp_tree';
    const META_TPL   = '_mp_is_template';
    const META_TAGS  = '_mp_level_tags';       // per-level tag map
    const META_GEN_C = '_mp_generated_count';
    const META_GEN_L = '_mp_generated_last';

    /** allowed tags per level */
    private $allowed_tags = ['h1','h2','h3','h4','h5','h6','p'];

    public function __construct() {
        add_action('init',                         [$this, 'register_cpt']);
        add_action('add_meta_boxes',               [$this, 'add_metaboxes']);
        add_action('admin_enqueue_scripts',        [$this, 'enqueue']);
        add_action('save_post',                    [$this, 'save'], 10, 2);

        // AJAX endpoints
        add_action('wp_ajax_mp_save_tree',         [$this, 'ajax_save_tree']);
        add_action('wp_ajax_mp_get_tree',          [$this, 'ajax_get_tree']);
        add_action('wp_ajax_mp_generate_post',     [$this, 'ajax_generate_post']); // always NEW draft
        add_action('wp_ajax_mp_insert_into',       [$this, 'ajax_insert_into']);   // replace current post content

        // Reusable workflow actions in list screen
        add_filter('post_row_actions',             [$this, 'row_actions'], 10, 2);
        add_action('admin_post_mp_gen_from_map',   [$this, 'admin_gen_from_map']);  // list: Generate New Blog
        add_action('admin_post_mp_duplicate_map',  [$this, 'admin_duplicate_map']); // list: Duplicate
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

    public function add_metaboxes() {
        // main builder
        add_meta_box(
            'mp_builder',
            __('Mind Map Builder', 'mindpress'),
            [$this, 'render_builder_metabox'],
            [ self::CPT, 'post' ],
            'normal',
            'high'
        );

        // heading mapping (per-map/per-post)
        add_meta_box(
            'mp_level_tags',
            __('Heading Mapping', 'mindpress'),
            [$this, 'render_levels_metabox'],
            [ self::CPT, 'post' ],
            'side',
            'default'
        );

        // optional template flag (CPT only)
        add_meta_box(
            'mp_template',
            __('Template', 'mindpress'),
            [$this, 'render_template_metabox'],
            self::CPT,
            'side',
            'low'
        );
    }

    public function enqueue($hook) {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->post_type, [self::CPT, 'post'], true)) return;

        wp_enqueue_style ('mp-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', [], '0.4.0');
        wp_enqueue_script('mp-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', ['jquery','wp-i18n'], '0.4.0', true);

        $post = get_post();
        $post_id = $post ? (int) $post->ID : 0;

        wp_localize_script('mp-admin', 'MindPress', [
            'nonce'   => wp_create_nonce(self::NONCE),
            'ajax'    => admin_url('admin-ajax.php'),
            'postId'  => $post_id,
            'ptype'   => $post ? $post->post_type : $screen->post_type,
            'levelTags' => $this->get_level_tags($post_id),
            'i18n'    => [
                'root'       => __('Root Idea', 'mindpress'),
                'addChild'   => __('Add child', 'mindpress'),
                'addSibling' => __('Add sibling', 'mindpress'),
                'delete'     => __('Delete this node?', 'mindpress'),
                'generate'   => __('Generate New Blog Post', 'mindpress'),
                'insert'     => __('Insert into this post', 'mindpress'),
                'search'     => __('Search...', 'mindpress'),
                'exported'   => __('Exported JSON downloaded', 'mindpress'),
                'importErr'  => __('Invalid JSON file format.', 'mindpress'),
                'saving'     => __('Saving…', 'mindpress'),
                'saved'      => __('Saved ✓', 'mindpress'),
            ],
        ]);
    }

    /** ===== Metaboxes ===== */

    public function render_builder_metabox($post) {
        $json = get_post_meta($post->ID, self::META_TREE, true);
        wp_nonce_field(self::NONCE, self::NONCE);

        echo '<div class="mp-toolbar">
                <input type="text" id="mp-search" class="mp-search" placeholder="'.esc_attr__('Search...', 'mindpress').'" />
                <div class="mp-toolbar-buttons">
                    <button type="button" class="button" id="mp-expand-all">'.esc_html__('Expand all','mindpress').'</button>
                    <button type="button" class="button" id="mp-collapse-all">'.esc_html__('Collapse all','mindpress').'</button>
                    <button type="button" class="button" id="mp-export">'.esc_html__('Export','mindpress').'</button>
                    <button type="button" class="button" id="mp-import">'.esc_html__('Import','mindpress').'</button>
                    <span class="mp-status" id="mp-status" aria-live="polite"></span>
                </div>
              </div>';

        echo '<div id="mp-app" class="mp-wrap" data-json="' . esc_attr($json ?: '') . '"></div>';
        echo '<input type="hidden" name="_mp_tree" id="_mp_tree" value="' . esc_attr($json ?: '') . '" />';

        echo '<p class="mp-actions">';
        echo '<button type="button" class="button button-primary" id="mp-generate">' . esc_html__('Generate New Blog Post', 'mindpress') . '</button> ';
        if ($post->post_type === 'post') {
            echo '<button type="button" class="button" id="mp-insert">' . esc_html__('Insert into this post', 'mindpress') . '</button> ';
        }
        echo '</p>';

        echo '<p class="description">'.esc_html__(
            'This map is reusable. Every click on “Generate New Blog Post” creates a fresh draft; the map stays unchanged.',
            'mindpress'
        ).'</p>';
    }

    public function render_levels_metabox($post) {
        $map = $this->get_level_tags($post->ID);
        echo '<table class="mp-tagmap"><tr><th>' . esc_html__('Depth', 'mindpress') . '</th><th>' . esc_html__('Tag', 'mindpress') . '</th></tr>';

        for ($d = 1; $d <= 6; $d++) {
            $d_str = (string) $d;
            echo '<tr><td>' . esc_html($d_str) . '</td>';
            echo '<td><select name="mp_tag_map[' . esc_attr($d_str) . ']">';
            foreach ($this->allowed_tags as $tag) {
                printf(
                    '<option value="%s"%s>%s</option>',
                    esc_attr($tag),
                    selected( $map[$d_str] ?? '', $tag, false ),
                    esc_html( strtoupper($tag) )
                );
            }
            echo '</select></td></tr>';
        }
        echo '</table>';
        echo '<p class="description">' . esc_html__('Choose how each depth renders (H1–H6 or P).', 'mindpress') . '</p>';
    }

    public function render_template_metabox($post) {
        if ($post->post_type !== self::CPT) return;
        $is_template = get_post_meta($post->ID, self::META_TPL, true) === '1';
        echo '<label><input type="checkbox" name="_mp_is_template" value="1" '.checked($is_template, true, false).' /> ';
        echo esc_html__('Use this Mind Map as a template', 'mindpress').'</label>';
    }

    /** ===== Save ===== */

    public function save($post_id, $post) {
        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE])), self::NONCE)) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!in_array($post->post_type, [ self::CPT, 'post' ], true) || !current_user_can('edit_post', $post_id)) return;

        // Save JSON tree from hidden field
        $tree_raw = filter_input(INPUT_POST, '_mp_tree', FILTER_UNSAFE_RAW, ['flags' => FILTER_NULL_ON_FAILURE]);
        if (null !== $tree_raw) {
            $tree_json = sanitize_textarea_field((string) $tree_raw);
            $this->update_tree_from_json($post_id, $tree_json);
        }

        // Save level tag mapping
        $tag_map_raw = filter_input(INPUT_POST, 'mp_tag_map', FILTER_DEFAULT, ['flags' => FILTER_REQUIRE_ARRAY | FILTER_NULL_ON_FAILURE]);
        if (is_array($tag_map_raw)) {
            $sanitized_map  = [];
            foreach ($tag_map_raw as $depth => $tag) {
                if (is_string($tag)) {
                    $sanitized_map[$depth] = sanitize_key($tag);
                }
            }
            $this->update_level_tags_from_map($post_id, $sanitized_map);
        }

        // Template toggle (CPT only)
        if ($post->post_type === self::CPT) {
            $is_tpl = isset($_POST['_mp_is_template']) ? '1' : '0';
            update_post_meta($post_id, self::META_TPL, $is_tpl);
        }
    }

    /** ===== AJAX ===== */

    public function ajax_save_tree() {
        check_ajax_referer(self::NONCE, 'nonce');
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
        }
        $tree_raw = filter_input(INPUT_POST, 'tree', FILTER_UNSAFE_RAW, ['flags' => FILTER_NULL_ON_FAILURE]);
        $tree_json  = is_string($tree_raw) ? sanitize_textarea_field($tree_raw) : '';
        $this->update_tree_from_json($post_id, $tree_json);
        wp_send_json_success(['ok' => true]);
    }

    public function ajax_get_tree() {
        check_ajax_referer(self::NONCE, 'nonce');
        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
        }
        $tree = get_post_meta($post_id, self::META_TREE, true);
        wp_send_json_success(['tree' => $tree ?: null]);
    }

    public function ajax_generate_post() {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
        }

        $source_id = isset($_POST['source_id']) ? absint($_POST['source_id']) : 0;
        $map_raw = filter_input(INPUT_POST, 'tree', FILTER_UNSAFE_RAW, ['flags' => FILTER_NULL_ON_FAILURE]);
        $map_json  = is_string($map_raw) ? sanitize_textarea_field($map_raw) : '';
        $map       = json_decode($map_json, true);
        if (!is_array($map)) wp_send_json_error(['message' => 'Invalid map data'], 400);

        $title_in  = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $title     = $title_in ?: ( $source_id ? get_the_title($source_id) : __('MindPress Draft', 'mindpress') );

        $content = $this->tree_to_content_with_tags($map, $this->get_level_tags($source_id));
        $new_id = wp_insert_post([
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => 'draft',
            'post_type'    => 'post',
        ], true);

        if (is_wp_error($new_id)) {
            wp_send_json_error(['message' => $new_id->get_error_message()]);
        }
        wp_send_json_success(['post_id' => $new_id, 'edit_link' => get_edit_post_link($new_id, 'raw')]);
    }

    public function ajax_insert_into() {
        check_ajax_referer(self::NONCE, 'nonce');
        $post_id  = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $src_id   = isset($_POST['source_id']) ? absint($_POST['source_id']) : 0;
        $map_raw = filter_input(INPUT_POST, 'tree', FILTER_UNSAFE_RAW, ['flags' => FILTER_NULL_ON_FAILURE]);
        $map_json = is_string($map_raw) ? sanitize_textarea_field($map_raw) : '';
        $map      = json_decode($map_json, true);

        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
        }
        if (!is_array($map)) {
            wp_send_json_error(['message' => 'Invalid map data'], 400);
        }

        $content = $this->tree_to_content_with_tags($map, $this->get_level_tags($src_id ?: $post_id));
        $res = wp_update_post(['ID' => $post_id, 'post_content' => $content], true);

        if (is_wp_error($res)) {
            wp_send_json_error(['message' => $res->get_error_message()]);
        }
        wp_send_json_success(['content' => $content]);
    }

    /** ===== List row actions ===== */

    public function row_actions($actions, $post) {
        if ($post->post_type !== self::CPT || !current_user_can('edit_post', $post->ID)) return $actions;

        $gen_url = wp_nonce_url(admin_url('admin-post.php?action=mp_gen_from_map&post_id='.$post->ID), self::NONCE, 'nonce');
        $dup_url = wp_nonce_url(admin_url('admin-post.php?action=mp_duplicate_map&post_id='.$post->ID), self::NONCE, 'nonce');

        $actions['mp-generate']  = sprintf('<a href="%s"><span class="dashicons dashicons-edit-page" style="vertical-align:text-bottom;"></span> %s</a>', esc_url($gen_url), esc_html__('Generate New Blog','mindpress'));
        $actions['mp-duplicate'] = sprintf('<a href="%s"><span class="dashicons dashicons-admin-page" style="vertical-align:text-bottom;"></span> %s</a>', esc_url($dup_url), esc_html__('Duplicate','mindpress'));
        return $actions;
    }

    public function admin_gen_from_map() {
        if (!isset($_GET['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['nonce'])), self::NONCE)) {
            wp_die(esc_html__('Invalid nonce.', 'mindpress'));
        }
        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
        $map_post = get_post($post_id);

        if (!$map_post || $map_post->post_type !== self::CPT) wp_die(esc_html__('Invalid Mind Map.', 'mindpress'));
        if (!current_user_can('edit_post', $post_id) || !current_user_can('edit_posts')) wp_die(esc_html__('Permission denied.', 'mindpress'));

        $tree = get_post_meta($post_id, self::META_TREE, true);
        $arr  = $tree ? json_decode($tree, true) : null;
        if (!is_array($arr)) wp_die(esc_html__('This Mind Map has no valid content.', 'mindpress'));

        $title   = $map_post->post_title ?: __('MindPress Draft', 'mindpress');
        $content = $this->tree_to_content_with_tags($arr, $this->get_level_tags($post_id));
        $new_id  = wp_insert_post(['post_title' => $title, 'post_content' => $content, 'post_status' => 'draft', 'post_type' => 'post'], true);

        if (is_wp_error($new_id)) wp_die(esc_html($new_id->get_error_message()));

        update_post_meta($post_id, self::META_GEN_C, (int) get_post_meta($post_id, self::META_GEN_C, true) + 1);
        update_post_meta($post_id, self::META_GEN_L, time());

        wp_safe_redirect(get_edit_post_link($new_id, 'raw'));
        exit;
    }

    public function admin_duplicate_map() {
        if (!isset($_GET['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['nonce'])), self::NONCE)) {
            wp_die(esc_html__('Invalid nonce.', 'mindpress'));
        }
        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
        $map_post = get_post($post_id);

        if (!$map_post || $map_post->post_type !== self::CPT) wp_die(esc_html__('Invalid Mind Map.', 'mindpress'));
        if (!current_user_can('edit_post', $post_id)) wp_die(esc_html__('Permission denied.', 'mindpress'));

        $new_id = wp_insert_post([
            'post_title'  => ($map_post->post_title ? $map_post->post_title.' – '. __('Copy','mindpress') : __('Mind Map Copy','mindpress')),
            'post_status' => 'draft',
            'post_type'   => self::CPT,
        ], true);
        if (is_wp_error($new_id)) wp_die(esc_html($new_id->get_error_message()));

        update_post_meta($new_id, self::META_TREE, get_post_meta($post_id, self::META_TREE, true));
        update_post_meta($new_id, self::META_TPL, get_post_meta($post_id, self::META_TPL, true));

        wp_safe_redirect(get_edit_post_link($new_id, 'raw'));
        exit;
    }

    /** ===== Helpers ===== */

    private function update_tree_from_json($post_id, $json) {
        $arr = json_decode($json, true);
        if (is_array($arr)) {
            // Sanitize recursively
            array_walk_recursive($arr, function(&$value, $key) {
                if ($key === 'text' || $key === 'notes') {
                    $value = sanitize_textarea_field($value);
                }
            });
            update_post_meta($post_id, self::META_TREE, wp_json_encode($arr));
        } else {
            delete_post_meta($post_id, self::META_TREE);
        }
    }

    private function update_level_tags_from_map($post_id, $map_in) {
        $clean = [];
        foreach ($map_in as $depth => $tag) {
            $d = absint($depth);
            $t = sanitize_key($tag);
            if ($d >= 1 && $d <= 6 && in_array($t, $this->allowed_tags, true)) {
                $clean[(string)$d] = $t;
            }
        }
        update_post_meta($post_id, self::META_TAGS, $clean);
    }

    private function get_level_tags($post_id) {
        $defaults = ['1'=>'h2','2'=>'h3','3'=>'h4','4'=>'h5','5'=>'p','6'=>'p'];
        $saved = $post_id ? get_post_meta($post_id, self::META_TAGS, true) : [];
        return is_array($saved) ? array_merge($defaults, $saved) : $defaults;
    }

    private function tree_to_content_with_tags($data, $level_tags) {
        $nodes = $this->normalize_nodes($data);
        $html  = '';
        $walk = function($node, $level) use (&$walk, &$html, $level_tags) {
            $text = isset($node['text']) ? wp_kses_post($node['text']) : '';
            $notes = isset($node['notes']) ? wp_kses_post($node['notes']) : '';
            $tag = $level_tags[(string) min(6, max(1, $level))] ?? 'p';

            if ($text) {
                $html .= "<$tag>" . esc_html($text) . "</$tag>";
                if ($notes) {
                    $html .= '<p>' . esc_html($notes) . '</p>';
                }
            }
            if (!empty($node['children']) && is_array($node['children'])) {
                foreach ($node['children'] as $child) {
                    $walk($child, $level + 1);
                }
            }
        };
        foreach ($nodes as $n) { $walk($n, 1); }
        return $html;
    }

    private function normalize_nodes($data) {
        if (is_array($data) && isset($data['children']) && is_array($data['children'])) return $data['children'];
        if (is_array($data) && isset($data['text'])) return [$data];
        return is_array($data) ? $data : [];
    }
}
new MindPressPlugin();
