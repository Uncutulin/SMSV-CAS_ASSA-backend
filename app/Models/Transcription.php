<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transcription extends Model
{
    use HasFactory;

    protected $fillable = [
        'filename',
        'agent_id',
        'call_date',
        'call_time_utc',
        'call_time_argentina',
        'call_index',
        'transcription',
        'status',
    ];
}
