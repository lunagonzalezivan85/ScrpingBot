<?php

namespace App\Models;

use CodeIgniter\Model;

class LeadModel extends Model
{
    protected $table            = 'leads';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'job_id',
        'sector',
        'ciudad',
        'departamento',
        'pais',
        'nombre_empresa',
        'tipo_entidad',
        'telefono',
        'telefono_raw',
        'email',
        'persona_contacto',
        'direccion',
        'sitio_web',
        'detalles_oferta',
        'estado_llamada',
        'intentos_llamada',
        'ultima_llamada_en',
        'proxima_llamada_en',
        'notas',
    ];

    protected bool $allowEmptyInserts = false;

    // Dates
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'creado_en';
    protected $updatedField  = 'actualizado_en';

    /**
     * Obtener prospectos filtrados para el marcador CRM
     */
    public function getLeadsFiltrados(array $filtros = [], int $limit = 50, int $offset = 0)
    {
        $builder = $this->builder();

        if (!empty($filtros['estado'])) {
            $builder->where('estado_llamada', $filtros['estado']);
        }
        if (!empty($filtros['ciudad'])) {
            $builder->where('ciudad', $filtros['ciudad']);
        }
        if (!empty($filtros['sector'])) {
            $builder->like('sector', $filtros['sector']);
        }
        if (!empty($filtros['buscar'])) {
            $buscar = $filtros['buscar'];
            $builder->groupStart()
                ->like('nombre_empresa', $buscar)
                ->orLike('telefono', $buscar)
                ->orLike('persona_contacto', $buscar)
            ->groupEnd();
        }

        $builder->orderBy('id', 'DESC');
        return $builder->get($limit, $offset)->getResultArray();
    }
}

