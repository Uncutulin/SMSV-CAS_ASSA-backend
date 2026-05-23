<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoberturaAceptada extends Model
{
    protected $table = 'coberturas_aceptadas';

    protected $fillable = [
        'email',
        'name',
        'templateid',
        'attach1',
        'attach2',
        'attach3',
        'attach4',
        'nombre',
        'dni',
        'numero_poliza',
        'fecha_vigencia',
        'src_file',
        'batch_id'
    ];
}
