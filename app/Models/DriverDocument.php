<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DriverDocument extends Model
{
    use HasFactory;

    public const TYPE_KTP = 'ktp';

    public const TYPE_SIM_A = 'sim_a';

    public const TYPE_SIM_C = 'sim_c';

    protected $fillable = ['driver_id', 'type', 'file_path', 'expires_at'];

    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return ['expires_at' => 'date'];
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }
}
