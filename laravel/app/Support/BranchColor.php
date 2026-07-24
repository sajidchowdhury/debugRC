<?php

namespace App\Support;

/**
 * Branch color helper — thin accessor over config('branches.colors').
 *
 * Usage:
 *   BranchColor::get('HO')           // array of all color fields
 *   BranchColor::hex('HO')           // '#dc2626'
 *   BranchColor::tint('HO', '22')    // '#dc262622' (13% alpha)
 *   BranchColor::get('UNKNOWN')      // falls back to HO
 */

class BranchColor
{
    /**
     * Get the full color config for a branch code (falls back to HO).
     *
     * @return array{code:string,name:string,name_bn:string,color_name:string,color_hex:string,bg_class:string,text_class:string,border_class:string,gradient_from:string,gradient_to:string}
     */
    public static function get(?string $branchCode): array
    {
        $code = strtoupper((string) $branchCode);
        return config("branches.colors.{$code}", config('branches.colors.' . config('branches.default', 'HO')));
    }

    /** The branch hex color, e.g. '#dc2626'. */
    public static function hex(?string $branchCode): string
    {
        return self::get($branchCode)['color_hex'];
    }

    /**
     * Branch hex color with an alpha suffix, for inline-style tints.
     * Common alpha: '11' (~7%), '15' (~8%), '22' (~13%), '33' (~20%).
     */
    public static function tint(?string $branchCode, string $alphaHex = '22'): string
    {
        return self::hex($branchCode) . $alphaHex;
    }

    /** The Tailwind bg utility class, e.g. 'bg-red-100'. */
    public static function bgClass(?string $branchCode): string
    {
        return self::get($branchCode)['bg_class'];
    }

    /** The Tailwind text utility class, e.g. 'text-red-700'. */
    public static function textClass(?string $branchCode): string
    {
        return self::get($branchCode)['text_class'];
    }

    /** The Tailwind border utility class, e.g. 'border-red-400'. */
    public static function borderClass(?string $branchCode): string
    {
        return self::get($branchCode)['border_class'];
    }
}
