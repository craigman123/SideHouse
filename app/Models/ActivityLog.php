<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $table = 'activity_logs';

    protected $fillable = [
        'user_id',
        'user_name',
        'action',
        'subject_type',
        'subject_id',
        'description',
        'properties',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'properties' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /**
     * Human-friendly "Type #id" label for the audit table, e.g. "Booking #42".
     * Returns null when the event has no subject (e.g. a failed login),
     * so the view can fall back to a placeholder like "—".
     */
    public function getSubjectLabelAttribute(): ?string
    {
        if (! $this->subject_type) {
            return null;
        }

        return $this->subject_id
            ? "{$this->subject_type} #{$this->subject_id}"
            : $this->subject_type;
    }
}