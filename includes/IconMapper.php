<?php
namespace STBP\includes;

defined('ABSPATH') || exit;

/**
 * Resolves legacy VC icon tokens to safe core/icon slugs.
 */
class IconMapper {
    private const DEFAULT_ICON = 'star-filled';

    // Keep this list constrained to known Gutenberg icon slugs.
    private const ALLOWED_SLUGS = [
        'star-filled',
        'star-empty',
        'calendar',
        'audio',
        'home',
        'image',
        'info',
        'map-marker',
        'video',
        'desktop',
        'tablet',
        'mobile',
        'plus',
        'people',
        'store',
        'check',
        'envelope',
        'help',
        'search',
        'link',
        'external',
        'download',
        'upload',
        'close',
        'trash',
    ];

    private const KEYWORD_MAP = [
        'star' => 'star-filled',
        'calendar' => 'calendar',
        'date' => 'calendar',
        'time' => 'calendar',

        'desktop' => 'desktop',
        'computer' => 'desktop',
        'screen' => 'desktop',

        'tablet' => 'tablet',
        'ipad' => 'tablet',

        'mobile' => 'mobile',
        'phone' => 'mobile',
        'cell' => 'mobile',

        'plus' => 'plus',
        'add' => 'plus',

        'people' => 'people',
        'person' => 'people',
        'user' => 'people',
        'users' => 'people',
        'team' => 'people',
        'group' => 'people',

        'store' => 'store',
        'shop' => 'store',
        'cart' => 'store',
        'bag' => 'store',

        'check' => 'check',
        'success' => 'check',
        'done' => 'check',

        'envelope' => 'envelope',
        'email' => 'envelope',
        'mail' => 'envelope',
        'contact' => 'envelope',

        'map' => 'map-marker',
        'marker' => 'map-marker',
        'location' => 'map-marker',
        'pin' => 'map-marker',
        'address' => 'map-marker',

        'video' => 'video',
        'movie' => 'video',
        'play' => 'video',

        'image' => 'image',
        'photo' => 'image',
        'camera' => 'image',
        'gallery' => 'image',
        'media' => 'image',

        'home' => 'home',
        'audio' => 'audio',
        'music' => 'audio',
        'sound' => 'audio',

        'info' => 'info',
        'help' => 'help',
        'search' => 'search',

        'link' => 'link',
        'external' => 'external',
        'download' => 'download',
        'upload' => 'upload',

        'close' => 'close',
        'remove' => 'close',
        'delete' => 'trash',
        'trash' => 'trash',
        'edit' => 'check',
    ];

    public static function resolveCoreIconSlug(string $token): string {
        $normalized = self::normalize($token);
        if ($normalized === '') {
            return self::DEFAULT_ICON;
        }

        if (in_array($normalized, self::ALLOWED_SLUGS, true)) {
            return $normalized;
        }

        foreach (self::KEYWORD_MAP as $needle => $slug) {
            if (strpos($normalized, $needle) !== false && in_array($slug, self::ALLOWED_SLUGS, true)) {
                return $slug;
            }
        }

        $parts = array_values(array_filter(explode('-', $normalized)));
        foreach ($parts as $part) {
            if (!empty(self::KEYWORD_MAP[$part])) {
                $slug = self::KEYWORD_MAP[$part];
                if (in_array($slug, self::ALLOWED_SLUGS, true)) {
                    return $slug;
                }
            }
        }

        return self::DEFAULT_ICON;
    }

    private static function normalize(string $token): string {
        $token = strtolower(trim($token));
        if ($token === '') {
            return '';
        }

        $token = str_replace('_', '-', $token);
        $token = preg_replace('/[^a-z0-9-]/', '-', $token);
        $token = preg_replace('/-+/', '-', $token);
        return trim((string) $token, '-');
    }
}
