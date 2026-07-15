<?php

/**
 * The goal of this file is to allow developers a location
 * where they can overwrite core procedural functions and
 * replace them with their own. This file is loaded during
 * the bootstrap process and is called during the framework's
 * execution.
 *
 * This can be looked at as a `master helper` file that is
 * loaded early on, and may also contain additional functions
 * that you'd like to use throughout your entire application
 *
 * @see: https://codeigniter.com/user_guide/extending/common.html
 */

if (! function_exists('badge_status')) {
    /** Badge status berwarna (tema BIPROO) utk status stage_history. */
    function badge_status(?string $status): string
    {
        if ($status === null || $status === '' || $status === '-') {
            return '<span class="badge badge-netral">-</span>';
        }

        $label = \App\Controllers\Lamaran::STATUS_LABEL[$status] ?? $status;
        $kelas = match ($status) {
            'passed'  => 'badge-lolos',
            'failed'  => 'badge-gagal',
            'flagged' => 'badge-flag',
            default   => 'badge-netral',
        };

        return '<span class="badge ' . $kelas . '">' . esc($label) . '</span>';
    }
}
