<?php

namespace App\Support;

/**
 * Accent color → literal Tailwind class strings.
 *
 * Tailwind v4 only generates utilities for class strings that appear
 * verbatim in scanned source files, so dynamic `border-l-{$color}-500`
 * interpolation is NOT safe. This map provides the literal strings keyed
 * by an AccentColor token; Blade components look up the classes they need
 * and compose them via Blade's class="" or a cn()-style helper.
 *
 * Usage:
 *   Accents::get('amber')['border_l_500']   // 'border-l-amber-500'
 *   Accents::class('amber', 'bg_500')       // 'bg-amber-500'
 */

class Accents
{
    public const AMBER = 'amber';
    public const ORANGE = 'orange';
    public const CYAN = 'cyan';
    public const GREEN = 'green';
    public const RED = 'red';
    public const YELLOW = 'yellow';
    public const BLUE = 'blue';
    public const GRAY = 'gray';

    /**
     * @return array{
     *   border_l_500:string, border_l_400:string,
     *   text_400:string, text_500:string, text_600:string, text_700:string,
     *   bg_50:string, bg_100:string, bg_500:string, bg_600:string,
     *   hover_bg_600:string,
     *   border_300:string, border_400:string,
     *   from_500:string, to_600:string
     * }
     */
    public static function get(string $accent): array
    {
        return self::all()[$accent] ?? self::all()[self::AMBER];
    }

    /** Convenience: get a single class string by accent + key. */
    public static function class(string $accent, string $key): string
    {
        $cfg = self::get($accent);
        return $cfg[$key] ?? '';
    }

    /**
     * @return array<string, array<string,string>>
     */
    public static function all(): array
    {
        return [
            self::AMBER => [
                'border_l_500' => 'border-l-amber-500',
                'border_l_400' => 'border-l-amber-400',
                'text_400' => 'text-amber-400',
                'text_500' => 'text-amber-500',
                'text_600' => 'text-amber-600',
                'text_700' => 'text-amber-700',
                'bg_50' => 'bg-amber-50',
                'bg_100' => 'bg-amber-100',
                'bg_500' => 'bg-amber-500',
                'bg_600' => 'bg-amber-600',
                'hover_bg_600' => 'hover:bg-amber-600',
                'border_300' => 'border-amber-300',
                'border_400' => 'border-amber-400',
                'from_500' => 'from-amber-500',
                'to_600' => 'to-amber-600',
            ],
            self::ORANGE => [
                'border_l_500' => 'border-l-orange-500',
                'border_l_400' => 'border-l-orange-400',
                'text_400' => 'text-orange-400',
                'text_500' => 'text-orange-500',
                'text_600' => 'text-orange-600',
                'text_700' => 'text-orange-700',
                'bg_50' => 'bg-orange-50',
                'bg_100' => 'bg-orange-100',
                'bg_500' => 'bg-orange-500',
                'bg_600' => 'bg-orange-600',
                'hover_bg_600' => 'hover:bg-orange-600',
                'border_300' => 'border-orange-300',
                'border_400' => 'border-orange-400',
                'from_500' => 'from-orange-500',
                'to_600' => 'to-orange-600',
            ],
            self::CYAN => [
                'border_l_500' => 'border-l-cyan-500',
                'border_l_400' => 'border-l-cyan-400',
                'text_400' => 'text-cyan-400',
                'text_500' => 'text-cyan-500',
                'text_600' => 'text-cyan-600',
                'text_700' => 'text-cyan-700',
                'bg_50' => 'bg-cyan-50',
                'bg_100' => 'bg-cyan-100',
                'bg_500' => 'bg-cyan-500',
                'bg_600' => 'bg-cyan-600',
                'hover_bg_600' => 'hover:bg-cyan-600',
                'border_300' => 'border-cyan-300',
                'border_400' => 'border-cyan-400',
                'from_500' => 'from-cyan-500',
                'to_600' => 'to-cyan-600',
            ],
            self::GREEN => [
                'border_l_500' => 'border-l-green-500',
                'border_l_400' => 'border-l-green-400',
                'text_400' => 'text-green-400',
                'text_500' => 'text-green-500',
                'text_600' => 'text-green-600',
                'text_700' => 'text-green-700',
                'bg_50' => 'bg-green-50',
                'bg_100' => 'bg-green-100',
                'bg_500' => 'bg-green-500',
                'bg_600' => 'bg-green-600',
                'hover_bg_600' => 'hover:bg-green-600',
                'border_300' => 'border-green-300',
                'border_400' => 'border-green-400',
                'from_500' => 'from-green-500',
                'to_600' => 'to-green-600',
            ],
            self::RED => [
                'border_l_500' => 'border-l-red-500',
                'border_l_400' => 'border-l-red-400',
                'text_400' => 'text-red-400',
                'text_500' => 'text-red-500',
                'text_600' => 'text-red-600',
                'text_700' => 'text-red-700',
                'bg_50' => 'bg-red-50',
                'bg_100' => 'bg-red-100',
                'bg_500' => 'bg-red-500',
                'bg_600' => 'bg-red-600',
                'hover_bg_600' => 'hover:bg-red-600',
                'border_300' => 'border-red-300',
                'border_400' => 'border-red-400',
                'from_500' => 'from-red-500',
                'to_600' => 'to-red-600',
            ],
            self::YELLOW => [
                'border_l_500' => 'border-l-yellow-500',
                'border_l_400' => 'border-l-yellow-400',
                'text_400' => 'text-yellow-400',
                'text_500' => 'text-yellow-500',
                'text_600' => 'text-yellow-600',
                'text_700' => 'text-yellow-700',
                'bg_50' => 'bg-yellow-50',
                'bg_100' => 'bg-yellow-100',
                'bg_500' => 'bg-yellow-500',
                'bg_600' => 'bg-yellow-600',
                'hover_bg_600' => 'hover:bg-yellow-600',
                'border_300' => 'border-yellow-300',
                'border_400' => 'border-yellow-400',
                'from_500' => 'from-yellow-500',
                'to_600' => 'to-yellow-600',
            ],
            self::BLUE => [
                'border_l_500' => 'border-l-blue-500',
                'border_l_400' => 'border-l-blue-400',
                'text_400' => 'text-blue-400',
                'text_500' => 'text-blue-500',
                'text_600' => 'text-blue-600',
                'text_700' => 'text-blue-700',
                'bg_50' => 'bg-blue-50',
                'bg_100' => 'bg-blue-100',
                'bg_500' => 'bg-blue-500',
                'bg_600' => 'bg-blue-600',
                'hover_bg_600' => 'hover:bg-blue-600',
                'border_300' => 'border-blue-300',
                'border_400' => 'border-blue-400',
                'from_500' => 'from-blue-500',
                'to_600' => 'to-blue-600',
            ],
            self::GRAY => [
                'border_l_500' => 'border-l-gray-500',
                'border_l_400' => 'border-l-gray-400',
                'text_400' => 'text-gray-400',
                'text_500' => 'text-gray-500',
                'text_600' => 'text-gray-600',
                'text_700' => 'text-gray-700',
                'bg_50' => 'bg-gray-50',
                'bg_100' => 'bg-gray-100',
                'bg_500' => 'bg-gray-500',
                'bg_600' => 'bg-gray-600',
                'hover_bg_600' => 'hover:bg-gray-600',
                'border_300' => 'border-gray-300',
                'border_400' => 'border-gray-400',
                'from_500' => 'from-gray-500',
                'to_600' => 'to-gray-600',
            ],
        ];
    }
}
