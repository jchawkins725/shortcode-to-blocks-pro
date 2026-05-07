<?php
namespace STBP\includes;

use STBC\core\Converter;

defined('ABSPATH') || exit;

/**
 * Pro converter — extends the free Converter with advanced shortcode converters.
 */
class ConverterPro extends Converter {

    /**
     * Pro shortcodes added on top of the basic set.
     */
    private $pro_shortcodes = [
        'vc_cta', 'vc_toggle', 'vc_video', 'vc_gmaps', 'vc_raw_js',
        'vc_icon', 'vc_tta_tabs', 'vc_tabs', 'vc_tta_tour', 'vc_tour',
        'vc_tta_accordion', 'vc_accordion', 'vc_message', 'vc_gallery',
        'vc_basic_grid', 'vc_masonry_media_grid', 'vc_media_grid',
    ];

    public function get_supported_shortcodes(): array {
        $base = parent::get_supported_shortcodes();
        return array_unique(array_merge($base, $this->pro_shortcodes));
    }

    /* ========= PRO CONVERTERS ========= */

    // [vc_cta] - Call-to-action block
    public function convert_vc_cta($attrs, string $inner_content): string {
        $attrs = is_array($attrs) ? $attrs : [];
        $heading    = $attrs['h2'] ?? '';
        $subheading = $attrs['h4'] ?? '';
        $content    = trim($inner_content);
        $align      = $attrs['txt_align'] ?? 'left';
        [$custom_classes, $anchor] = $this->get_custom_classes_and_anchor($attrs);

        $has_button  = isset($attrs['add_button']) && $attrs['add_button'] !== 'false' && $attrs['add_button'] !== '';
        $button_text = $attrs['btn_title'] ?? '';
        $button_url  = $this->parse_vc_link($attrs['btn_link'] ?? '');

        $button_color = $attrs['btn_color'] ?? '';
        $color_hex_map = [
            'success' => '#28a745', 'danger' => '#dc3545', 'warning' => '#ffc107',
            'info' => '#17a2b8', 'primary' => '#007bff', 'secondary' => '#6c757d',
            'dark' => '#343a40', 'light' => '#f8f9fa',
        ];

        $cta_style = $attrs['style'] ?? '';
        $styling   = $this->get_cta_styling($cta_style, $attrs['color'] ?? '');

        $div_classes = ['cta-block'];
        if ($cta_style) $div_classes[] = 'cta-' . sanitize_html_class($cta_style);
        if ($align !== 'left') $div_classes[] = 'has-text-align-' . sanitize_html_class($align);
        if (!empty($custom_classes)) $div_classes = array_merge($div_classes, $custom_classes);

        $group_attrs = ['className' => implode(' ', $div_classes)];
        if ($anchor !== '') $group_attrs['anchor'] = $anchor;
        if (!empty($styling['block_attrs'])) $group_attrs['style'] = $styling['block_attrs'];

        $final_div_classes = array_merge(['wp-block-group'], $div_classes);
        if ($styling['has_background']) $final_div_classes[] = 'has-background';

        $attrs_json = wp_json_encode($group_attrs);
        $div_class  = implode(' ', $final_div_classes);
        $style_attr = !empty($styling['inline_styles']) ? ' style="' . implode(';', $styling['inline_styles']) . '"' : '';
        $id_attr    = $anchor !== '' ? ' id="' . esc_attr($anchor) . '"' : '';

        $out = "<!-- wp:group {$attrs_json} -->\n<div class=\"{$div_class}\"{$id_attr}{$style_attr}>";

        if (!empty($heading)) {
            $level = !empty($subheading) ? 2 : 3;
            $out .= "<!-- wp:heading {\"level\":{$level}} -->\n<h{$level}>" . esc_html($heading) . "</h{$level}>\n<!-- /wp:heading -->\n\n";
        }
        if (!empty($subheading)) {
            $out .= "<!-- wp:heading {\"level\":3} -->\n<h3>" . esc_html($subheading) . "</h3>\n<!-- /wp:heading -->\n\n";
        }
        if (!empty($content)) {
            $out .= $this->blocks_from_html($content) . "\n";
        }
        if ($has_button && !empty($button_text) && !empty($button_url)) {
            $out .= $this->generate_button_block($button_text, $button_url, $button_color, $color_hex_map, $attrs['btn_custom_background'] ?? '');
        }

        $out .= "</div>\n<!-- /wp:group -->";
        return $out;
    }

    private function get_cta_styling(string $style, string $color): array {
        $bg_color = !empty($color) ? preg_replace('/[^#a-zA-Z0-9(),.% -]/', '', $color) : '#f8f9fa';
        $result   = ['inline_styles' => [], 'block_attrs' => [], 'has_background' => false];

        switch ($style) {
            case 'flat':
            case 'classic':
                $result['inline_styles'] = [
                    'background-color:' . $bg_color, 'margin-top:16px', 'margin-bottom:16px',
                    'padding-top:24px', 'padding-right:24px', 'padding-bottom:24px', 'padding-left:24px',
                ];
                $result['block_attrs'] = [
                    'color'   => ['background' => $bg_color],
                    'spacing' => [
                        'padding' => ['top' => '24px', 'right' => '24px', 'bottom' => '24px', 'left' => '24px'],
                        'margin'  => ['top' => '16px', 'bottom' => '16px'],
                    ],
                ];
                $result['has_background'] = true;
                break;
            case 'outline':
                $border_color = !empty($color) ? $color : '#ddd';
                $result['inline_styles'] = [
                    'border-width:2px', 'border-style:solid', 'border-color:' . $border_color,
                    'padding-top:24px', 'padding-right:24px', 'padding-bottom:24px', 'padding-left:24px',
                ];
                $result['block_attrs'] = [
                    'border'  => ['width' => '2px', 'style' => 'solid', 'color' => $border_color],
                    'spacing' => ['padding' => ['top' => '24px', 'right' => '24px', 'bottom' => '24px', 'left' => '24px']],
                ];
                break;
            default:
                if (!empty($color)) {
                    $result['inline_styles'] = [
                        'background-color:' . $bg_color,
                        'padding-top:24px', 'padding-right:24px', 'padding-bottom:24px', 'padding-left:24px',
                    ];
                    $result['block_attrs'] = [
                        'color'   => ['background' => $bg_color],
                        'spacing' => ['padding' => ['top' => '24px', 'right' => '24px', 'bottom' => '24px', 'left' => '24px']],
                    ];
                    $result['has_background'] = true;
                }
                break;
        }
        return $result;
    }

    // [vc_toggle] → details
    public function convert_vc_toggle($attrs, string $inner_content): string {
        $attrs = is_array($attrs) ? $attrs : [];
        $title   = isset($attrs['title']) ? esc_html($attrs['title']) : 'Details';
        $content = trim($inner_content);
        $parsed  = ($content !== '') ? trim($this->blocks_from_html($content)) : '';
        [$custom_classes, $anchor] = $this->get_custom_classes_and_anchor($attrs);

        $block_attrs = [];
        if (!empty($custom_classes)) $block_attrs['className'] = implode(' ', $custom_classes);
        if ($anchor !== '') $block_attrs['anchor'] = $anchor;

        $class_names = array_merge(['wp-block-details'], $custom_classes);
        $id_attr = $anchor !== '' ? ' id="' . esc_attr($anchor) . '"' : '';
        $open = empty($block_attrs)
            ? '<!-- wp:details -->'
            : '<!-- wp:details ' . wp_json_encode($block_attrs) . ' -->';

        return $open . "\n<details class=\"" . esc_attr(implode(' ', array_values(array_unique($class_names)))) . "\"{$id_attr}>\n<summary>{$title}</summary>\n{$parsed}\n</details>\n<!-- /wp:details -->";
    }

    // [vc_gmaps]
    public function convert_vc_gmaps($attrs, $inner_content = ''): string {
        $attrs = is_array($attrs) ? $attrs : [];
        $address = $attrs['address'] ?? '';
        $zoom    = (int) ($attrs['zoom'] ?? 14);
        if ($address === '') return '';
        [$custom_classes, $anchor] = $this->get_custom_classes_and_anchor($attrs);
        $url = "https://maps.google.com/?q=" . rawurlencode($address) . "&z={$zoom}";
        $block_attrs = ['url' => $url];
        if (!empty($custom_classes)) $block_attrs['className'] = implode(' ', $custom_classes);
        if ($anchor !== '') $block_attrs['anchor'] = $anchor;

        $figure_classes = array_merge(['wp-block-embed', 'is-type-embed', 'is-provider-googlemaps', 'wp-block-embed-googlemaps'], $custom_classes);
        $id_attr = $anchor !== '' ? ' id="' . esc_attr($anchor) . '"' : '';

        return '<!-- wp:embed ' . wp_json_encode($block_attrs) . ' -->' . "\n"
            . '<figure class="' . esc_attr(implode(' ', array_values(array_unique($figure_classes)))) . '"' . $id_attr . '><div class="wp-block-embed__wrapper">' . "\n{$url}\n</div></figure>\n<!-- /wp:embed -->";
    }

    // [vc_raw_js]
    public function convert_vc_raw_js($attrs, $inner_content = ''): string {
        $encoded        = trim($inner_content);
        $base64_decoded = base64_decode($encoded, true);
        $decoded        = ($base64_decoded !== false) ? urldecode($base64_decoded) : $encoded;
        $decoded        = preg_replace('#^<script[^>]*>|</script>$#i', '', trim($decoded));
        return "<!-- wp:html -->\n<script>\n$decoded\n</script>\n<!-- /wp:html -->";
    }

    // [vc_video]
    public function convert_vc_video($attrs, $inner_content = ''): string {
        $attrs = is_array($attrs) ? $attrs : [];
        $url = '';
        if (!empty($attrs['link']) && filter_var($attrs['link'], FILTER_VALIDATE_URL)) {
            $url = $attrs['link'];
        } else {
            $maybe = trim($inner_content);
            if (filter_var($maybe, FILTER_VALIDATE_URL)) $url = $maybe;
        }
        if ($url === '') return '';
        [$custom_classes, $anchor] = $this->get_custom_classes_and_anchor($attrs);

        $provider = '';
        if (preg_match('~(youtube\.com|youtu\.be)~i', $url)) $provider = 'youtube';
        elseif (preg_match('~vimeo\.com~i', $url)) $provider = 'vimeo';

        if ($provider) {
            $block_attrs = ['url' => $url];
            if (!empty($custom_classes)) $block_attrs['className'] = implode(' ', $custom_classes);
            if ($anchor !== '') $block_attrs['anchor'] = $anchor;

            $figure_classes = array_merge(['wp-block-embed', 'is-type-video', 'is-provider-' . $provider, 'wp-block-embed-' . $provider], $custom_classes);
            $id_attr = $anchor !== '' ? ' id="' . esc_attr($anchor) . '"' : '';

            return '<!-- wp:embed ' . wp_json_encode($block_attrs) . ' -->' . "\n"
                . '<figure class="' . esc_attr(implode(' ', array_values(array_unique($figure_classes)))) . '"' . $id_attr . '><div class="wp-block-embed__wrapper">' . "\n{$url}\n</div></figure>\n<!-- /wp:embed -->";
        }

        $wrapper_classes = array_merge(['vc-video'], $custom_classes);
        $id_attr = $anchor !== '' ? ' id="' . esc_attr($anchor) . '"' : '';
        return "<!-- wp:html -->\n<div class=\"" . esc_attr(implode(' ', array_values(array_unique($wrapper_classes)))) . "\"{$id_attr}><iframe src=\"" . esc_url($url) . "\" allowfullscreen loading=\"lazy\"></iframe></div>\n<!-- /wp:html -->";
    }

    // [vc_icon]
    public function convert_vc_icon($attrs, $inner_content = ''): string {
        $attrs = is_array($attrs) ? $attrs : [];
        $icon_class = '';
        foreach (['icon_fontawesome', 'icon_openiconic', 'icon_typicons', 'icon_entypo', 'icon_linecons'] as $key) {
            if (!empty($attrs[$key])) { $icon_class = $attrs[$key]; break; }
        }
        if (empty($icon_class)) return '';
        [$custom_classes, $anchor] = $this->get_custom_classes_and_anchor($attrs);

        $size_map  = ['xs' => '12px', 'sm' => '16px', 'md' => '24px', 'lg' => '32px', 'xl' => '48px'];
        $font_size = $size_map[$attrs['size'] ?? 'md'] ?? '24px';
        $styles    = ["font-size: {$font_size}"];

        if (!empty($attrs['color'])) {
            $styles[] = "color: " . preg_replace('/[^#a-zA-Z0-9(),.% -]/', '', $attrs['color']);
        }

        $background_style = $attrs['background_style'] ?? '';
        $background_color = !empty($attrs['background_color']) ? preg_replace('/[^#a-zA-Z0-9(),.% -]/', '', $attrs['background_color']) : '';

        if ($background_style && $background_color) {
            $styles[] = "background-color: {$background_color}";
            $styles[] = "padding: 8px";
            $styles[] = "display: inline-block";
            if ($background_style === 'rounded') $styles[] = "border-radius: 50%";
            elseif ($background_style === 'boxed') $styles[] = "border-radius: 4px";
        }

        $align          = $attrs['align'] ?? 'left';
        $wrapper_styles = [];
        if ($align === 'center') $wrapper_styles[] = "text-align: center";
        elseif ($align === 'right') $wrapper_styles[] = "text-align: right";

        $style_attr = !empty($styles) ? ' style="' . esc_attr(implode('; ', $styles)) . '"' : '';
        $icon_html  = '<i class="' . esc_attr($icon_class) . '"' . $style_attr . '></i>';

        if (!empty($attrs['link'])) {
            $url = $target = $title = '';
            foreach (explode('|', $attrs['link']) as $part) {
                if (strpos($part, 'url:') === 0) $url = esc_url(urldecode(substr($part, 4)));
                elseif (strpos($part, 'target:') === 0) { $t = substr($part, 7); $target = in_array($t, ['_blank','_self','_parent','_top']) ? $t : ''; }
                elseif (strpos($part, 'title:') === 0) $title = esc_attr(substr($part, 6));
            }
            if (empty($url) && filter_var($attrs['link'], FILTER_VALIDATE_URL)) $url = esc_url($attrs['link']);

            if ($url) {
                $link_attrs = ['href="' . $url . '"'];
                if ($target) { $link_attrs[] = 'target="' . $target . '"'; if ($target === '_blank') $link_attrs[] = 'rel="noopener"'; }
                if ($title) $link_attrs[] = 'title="' . $title . '"';
                $icon_html = '<a ' . implode(' ', $link_attrs) . '>' . $icon_html . '</a>';
            }
        }

        $wrapper_classes = array_merge(['vc-icon-wrapper'], $custom_classes);
        $wrapper_style_attr = !empty($wrapper_styles) ? ' style="' . esc_attr(implode('; ', $wrapper_styles)) . '"' : '';
        $id_attr = $anchor !== '' ? ' id="' . esc_attr($anchor) . '"' : '';
        $final_html = '<div class="' . esc_attr(implode(' ', array_values(array_unique($wrapper_classes)))) . '"' . $id_attr . $wrapper_style_attr . '>' . $icon_html . '</div>';
        return "<!-- wp:html -->\n{$final_html}\n<!-- /wp:html -->";
    }

    // Unified tab/tour/accordion helper
    private function convert_tabs_to_headings($inner_content, $section_shortcode): string {
        if (empty(trim($inner_content))) return '';
        $output  = '';
        $pattern = '/\[' . preg_quote($section_shortcode) . '([^\]]*)\](.*?)\[\/' . preg_quote($section_shortcode) . '\]/s';

        if (preg_match_all($pattern, $inner_content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $section_attrs   = shortcode_parse_atts($match[1]);
                $section_content = trim($match[2]);
                $title           = $section_attrs['title'] ?? '';

                if (!empty($title)) {
                    $output .= "<!-- wp:heading {\"level\":3} -->\n<h3>" . esc_html($title) . "</h3>\n<!-- /wp:heading -->\n\n";
                }
                if (!empty($section_content)) {
                    $output .= $this->wrap_non_vc_shortcodes($section_content) . "\n\n";
                }
            }
        }
        return trim($output);
    }

    public function convert_vc_tta_tabs($attrs, string $inner_content): string {
        return $this->convert_tabs_to_headings($inner_content, 'vc_tta_section');
    }

    public function convert_vc_tabs($attrs, string $inner_content): string {
        return $this->convert_tabs_to_headings($inner_content, 'vc_tab');
    }

    public function convert_vc_tta_tour($attrs, string $inner_content): string {
        return $this->convert_tabs_to_headings($inner_content, 'vc_tta_section');
    }

    public function convert_vc_tour($attrs, string $inner_content): string {
        return $this->convert_tabs_to_headings($inner_content, 'vc_tab');
    }

    public function convert_vc_tta_accordion($attrs, string $inner_content): string {
        return $this->convert_tabs_to_headings($inner_content, 'vc_tta_section');
    }

    public function convert_vc_accordion($attrs, string $inner_content): string {
        if (empty(trim($inner_content))) return '';
        $output = '';
        preg_match_all('/<!-- wp:paragraph -->\s*<p>(.*?)<\/p>\s*<!-- \/wp:paragraph -->/s', $inner_content, $matches);

        if (!empty($matches[1])) {
            $n = 1;
            foreach ($matches[1] as $paragraph) {
                $c = trim($paragraph);
                if (empty($c) || $c === '<p></p>' || $c === '&nbsp;') continue;
                $output .= "<!-- wp:heading {\"level\":3} -->\n<h3>Section {$n}</h3>\n<!-- /wp:heading -->\n\n";
                $output .= "<!-- wp:paragraph -->\n<p>{$c}</p>\n<!-- /wp:paragraph -->\n\n";
                $n++;
            }
        }
        return trim($output);
    }

    // [vc_message]
    public function convert_vc_message($attrs, string $inner_content): string {
        $attrs = is_array($attrs) ? $attrs : [];
        $content = trim($inner_content);
        if (empty($content)) return '';

        $message_type = $attrs['style'] ?? 'info';
        $icon         = $attrs['icon_fontawesome'] ?? '';
        $closeable    = isset($attrs['closeable']) && $this->vc_bool($attrs['closeable']);

        $type_map    = ['info' => 'info', 'success' => 'success', 'warning' => 'warning', 'danger' => 'error', 'alert' => 'warning', 'custom' => 'info'];
        $notice_type = $type_map[$message_type] ?? 'info';

        $css_classes = ['vc-message', 'vc-message-' . $notice_type];
        if ($closeable) $css_classes[] = 'vc-message-closeable';
        [$custom_classes, $anchor] = $this->get_custom_classes_and_anchor($attrs);
        if (!empty($custom_classes)) {
            $css_classes = array_merge($css_classes, $custom_classes);
        }

        $color_map = [
            'info'    => ['bg' => '#d1ecf1', 'border' => '#bee5eb', 'text' => '#0c5460'],
            'success' => ['bg' => '#d4edda', 'border' => '#c3e6cb', 'text' => '#155724'],
            'warning' => ['bg' => '#fff3cd', 'border' => '#ffeaa7', 'text' => '#856404'],
            'error'   => ['bg' => '#f8d7da', 'border' => '#f5c6cb', 'text' => '#721c24'],
        ];

        $message_html = '';
        if (!empty($icon)) $message_html .= '<i class="' . esc_attr($icon) . '" style="margin-right: 8px;"></i>';

        $processed_content = $this->blocks_from_html($content);
        $paragraph_content = '';
        if (preg_match('/^<!-- wp:paragraph -->\s*<p>(.*?)<\/p>\s*<!-- \/wp:paragraph -->\s*$/s', trim($processed_content), $match)) {
            $paragraph_content = $match[1];
        } else {
            $paragraph_content = $content;
        }
        if ($closeable) {
            $paragraph_content .= '<button type="button" class="vc-message-close" style="float: right; background: none; border: none; font-size: 18px; cursor: pointer; margin-left: 8px;">&times;</button>';
        }

        $block_attrs = [];
        if (!empty($css_classes)) $block_attrs['className'] = implode(' ', $css_classes);
        if ($anchor !== '') $block_attrs['anchor'] = $anchor;
        $style_obj = [];
        if (isset($color_map[$notice_type])) {
            $colors = $color_map[$notice_type];
            $style_obj['color']    = ['text' => $colors['text']];
            $style_obj['spacing']  = ['padding' => ['top' => '12px', 'right' => '16px', 'bottom' => '12px', 'left' => '16px'], 'margin' => ['top' => '16px', 'bottom' => '16px']];
            $style_obj['border']   = ['radius' => '4px', 'left' => ['width' => '4px', 'color' => $colors['border'], 'style' => 'solid']];
            $style_obj['elements'] = ['background' => ['color' => $colors['bg']]];
        }
        if (!empty($attrs['color']) && $message_type === 'custom') {
            $bg_color = preg_replace('/[^#a-zA-Z0-9(),.% -]/', '', $attrs['color']);
            $style_obj['elements'] = ['background' => ['color' => $bg_color]];
        }
        if (!empty($style_obj)) $block_attrs['style'] = $style_obj;
        $attrs_json     = wp_json_encode($block_attrs);
        $div_classes    = array_merge(['wp-block-group'], $css_classes);
        $id_attr        = $anchor !== '' ? ' id="' . esc_attr($anchor) . '"' : '';

        return "<!-- wp:group {$attrs_json} -->\n<div class=\"wp-block-group " . implode(' ', $div_classes) . "\"{$id_attr}>\n<!-- wp:paragraph -->\n<p>{$message_html}{$paragraph_content}</p>\n<!-- /wp:paragraph -->\n</div>\n<!-- /wp:group -->";
    }

    // [vc_gallery]
    public function convert_vc_gallery($attrs, string $inner_content): string {
        $attrs = is_array($attrs) ? $attrs : [];
        $images = $attrs['images'] ?? '';
        if (empty($images)) return '';
        [$custom_classes, $anchor] = $this->get_custom_classes_and_anchor($attrs);

        $image_ids = array_filter(array_map('intval', explode(',', $images)));
        if (empty($image_ids)) return '';

        $columns = 3;
        if (isset($attrs['interval'])) $columns = max(1, min(8, (int) $attrs['interval']));
        elseif (isset($attrs['columns_count'])) $columns = max(1, min(8, (int) $attrs['columns_count']));

        $link_to  = 'none';
        $onclick  = $attrs['onclick'] ?? '';
        if ($onclick === 'link_image' || $onclick === 'img_link_large') $link_to = 'media';
        elseif ($onclick === 'custom_link') $link_to = 'custom';

        $size_slug = 'large';
        if (!empty($attrs['img_size'])) {
            $size_map  = ['thumbnail' => 'thumbnail', 'medium' => 'medium', 'large' => 'large', 'full' => 'full'];
            $requested = strtolower(trim($attrs['img_size']));
            if (isset($size_map[$requested])) $size_slug = $size_map[$requested];
        }

        $gallery_classes = ['columns-' . $columns];
        if (!empty($custom_classes)) $gallery_classes = array_merge($gallery_classes, $custom_classes);

        $block_attrs = ['linkTo' => $link_to, 'className' => implode(' ', $gallery_classes)];
        if ($anchor !== '') $block_attrs['anchor'] = $anchor;
        if (!empty($attrs['gap'])) {
            $gap_map = ['0' => '0px', '5' => '5px', '10' => '10px', '15' => '15px', '20' => '20px', '25' => '25px', '30' => '30px', '35' => '35px'];
            if (isset($gap_map[$attrs['gap']])) $block_attrs['style'] = ['spacing' => ['blockGap' => $gap_map[$attrs['gap']]]];
        }
        $attrs_json = wp_json_encode($block_attrs);

        $gallery_images = '';
        foreach ($image_ids as $img_id) {
            $url = wp_get_attachment_image_url($img_id, $size_slug);
            $alt = get_post_meta($img_id, '_wp_attachment_image_alt', true);
            $alt = is_string($alt) ? $alt : '';
            if (!$url) continue;

            $img_block_attrs = ['id' => $img_id, 'sizeSlug' => $size_slug, 'linkDestination' => $link_to];
            $img_tag = '<img src="' . esc_url($url) . '" alt="' . esc_attr($alt) . '" class="wp-image-' . $img_id . '"/>';
            if ($link_to === 'media') {
                $full_url = wp_get_attachment_image_url($img_id, 'full');
                $img_tag  = '<a href="' . esc_url($full_url) . '">' . $img_tag . '</a>';
            }
            $gallery_images .= "<!-- wp:image " . wp_json_encode($img_block_attrs) . " -->\n";
            $gallery_images .= '<figure class="wp-block-image size-' . esc_attr($size_slug) . '">' . $img_tag . '</figure>' . "\n";
            $gallery_images .= "<!-- /wp:image -->";
        }
        if (empty($gallery_images)) return '';

        $figure_classes = array_merge(['wp-block-gallery', 'has-nested-images', 'columns-default', 'is-cropped', 'columns-' . $columns], $custom_classes);
        $id_attr = $anchor !== '' ? ' id="' . esc_attr($anchor) . '"' : '';

        return "<!-- wp:gallery {$attrs_json} -->\n<figure class=\"" . esc_attr(implode(' ', array_values(array_unique($figure_classes)))) . "\"{$id_attr}>{$gallery_images}</figure>\n<!-- /wp:gallery -->";
    }

    // [vc_media_grid]
    public function convert_vc_media_grid($attrs, string $inner_content): string {
        $attrs = is_array($attrs) ? $attrs : [];
        if (!empty($attrs['include'])) {
            $gallery_attrs = ['images' => $attrs['include']];
            if (!empty($attrs['grid_columns_count'])) $gallery_attrs['interval'] = $attrs['grid_columns_count'];
            elseif (!empty($attrs['columns'])) $gallery_attrs['interval'] = $attrs['columns'];
            if (!empty($attrs['item_size'])) $gallery_attrs['img_size'] = $attrs['item_size'];
            if (!empty($attrs['onclick'])) $gallery_attrs['onclick'] = $attrs['onclick'];
            if (!empty($attrs['gap'])) $gallery_attrs['gap'] = $attrs['gap'];
            if (!empty($attrs['el_class'])) $gallery_attrs['el_class'] = $attrs['el_class'];
            if (!empty($attrs['el_id'])) $gallery_attrs['el_id'] = $attrs['el_id'];
            return $this->convert_vc_gallery($gallery_attrs, $inner_content);
        }
        return $this->preserve_grid_shortcode('vc_media_grid', $attrs, 'Note: This media grid requires WPBakery. Consider converting to a Gallery block.');
    }

    // [vc_basic_grid]
    public function convert_vc_basic_grid($attrs, string $inner_content): string {
        return $this->preserve_grid_shortcode('vc_basic_grid', $attrs, 'Note: This post grid requires WPBakery. Consider using the Query Loop block.');
    }

    // [vc_masonry_media_grid]
    public function convert_vc_masonry_media_grid($attrs, string $inner_content): string {
        return $this->preserve_grid_shortcode('vc_masonry_media_grid', $attrs, 'Note: This masonry layout requires WPBakery. Consider using a Gallery block or masonry plugin.');
    }
}
