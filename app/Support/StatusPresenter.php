<?php
declare(strict_types=1);

namespace TripleR\Support;

/**
 * Turns stored values (no_show, out_of_service, ...) into the label and badge
 * tone staff see. Presentation only: stored values and form values are unchanged.
 */
final class StatusPresenter
{
    /** Labels that plain "replace underscores and capitalise" would get wrong. */
    private const LABELS = [
        'no_show' => 'No-show',
        'self_drive' => 'Self-drive',
        'walk_in' => 'Walk-in',
        'in_progress' => 'In progress',
        'out_of_service' => 'Out of service',
        'due_soon' => 'Due soon',
        'not_required' => 'Not required',
        'ph_driver_license' => 'PH driver’s licence',
        'national_id' => 'National ID',
        'other_government_id' => 'Other government ID',
        'chauffeur_fee' => 'Chauffeur fee',
        'system_admin' => 'System admin',
        'pre' => 'Pre-rental',
        'during' => 'During rental',
        'post' => 'Post-rental',
        'SUV' => 'SUV',
    ];

    /** Labels that depend on what the value describes. */
    private const KIND_LABELS = [
        'due' => ['due' => 'Due now'],
        'payment' => ['pending' => 'In progress', 'failed' => 'Not paid', 'expired' => 'Timed out'],
    ];

    private const TONES = [
        'vehicle' => [
            'available' => 'success', 'cleaning' => 'info', 'reserved' => 'info', 'rented' => 'info',
            'maintenance' => 'warning', 'out_of_service' => 'danger', 'retired' => 'neutral',
        ],
        'rental' => [
            'reserved' => 'info', 'confirmed' => 'success', 'active' => 'success', 'returned' => 'warning',
            'completed' => 'neutral', 'cancelled' => 'danger', 'no_show' => 'danger',
        ],
        'deposit' => [
            'not_required' => 'neutral', 'due' => 'warning', 'held' => 'info',
            'released' => 'success', 'refunded' => 'success', 'forfeited' => 'danger',
        ],
        'downpayment' => [
            'not_required' => 'neutral', 'due' => 'warning', 'received' => 'success',
        ],
        'payment' => [
            'pending' => 'info', 'paid' => 'success', 'failed' => 'danger', 'cancelled' => 'neutral', 'expired' => 'neutral',
        ],
        'driver' => [
            'active' => 'success', 'on_assignment' => 'info', 'off_duty' => 'warning',
            'inactive' => 'neutral', 'terminated' => 'danger',
        ],
        'service' => [
            'in_progress' => 'info', 'completed' => 'success', 'cancelled' => 'danger',
        ],
        'due' => [
            'due' => 'danger', 'overdue' => 'danger', 'due_soon' => 'warning',
        ],
        'location' => [
            'active' => 'success', 'retired' => 'neutral',
        ],
        'severity' => [
            'minor' => 'warning', 'moderate' => 'warning', 'severe' => 'danger',
        ],
    ];

    public static function label(mixed $value): string
    {
        $value = (string) ($value ?? '');
        if ($value === '') {
            return '—';
        }
        return self::LABELS[$value] ?? ucfirst(str_replace('_', ' ', $value));
    }

    public static function tone(string $kind, mixed $value): string
    {
        return self::TONES[$kind][(string) $value] ?? 'neutral';
    }

    /** A badge with the readable label; the text always carries the meaning, colour only supports it. */
    public static function badge(string $kind, mixed $value, ?string $label = null): string
    {
        $label ??= self::KIND_LABELS[$kind][(string) $value] ?? self::label($value);
        return '<span class="badge badge-' . self::tone($kind, $value) . '">' . View::e($label) . '</span>';
    }
}
