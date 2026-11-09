<?php

namespace App\Models;

use CodeIgniter\Model;

class JobModel extends Model
{
    protected $table            = 'scraping_jobs';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'uuid',
        'sector',
        'ciudad',
        'pais',
        'fuente_tipo',
        'limite_solicitado',
        'leads_encontrados',
        'leads_guardados',
        'status',
        'error_mensaje',
        'iniciado_en',
        'finalizado_en',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'creado_en';
    protected $updatedField  = 'actualizado_en';
}

