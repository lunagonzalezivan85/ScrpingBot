<?php

namespace App\Controllers\Api;

use CodeIgniter\RESTful\ResourceController;
use App\Models\LeadModel;

class Leads extends ResourceController
{
    protected $format = 'json';

    public function index()
    {
        $leadModel = new LeadModel();

        $filtros = [
            'estado' => $this->request->getGet('estado'),
            'ciudad' => $this->request->getGet('ciudad'),
            'sector' => $this->request->getGet('sector'),
            'buscar' => $this->request->getGet('buscar'),
        ];

        $page   = (int)($this->request->getGet('page') ?? 1);
        $limit  = (int)($this->request->getGet('limit') ?? 50);
        $offset = ($page - 1) * $limit;

        $leads = $leadModel->getLeadsFiltrados($filtros, $limit, $offset);

        // Conteo total para paginación
        $totalBuilder = $leadModel->builder();
        if (!empty($filtros['estado'])) {
            $totalBuilder->where('estado_llamada', $filtros['estado']);
        }
        if (!empty($filtros['ciudad'])) {
            $totalBuilder->where('ciudad', $filtros['ciudad']);
        }
        if (!empty($filtros['buscar'])) {
            $buscar = $filtros['buscar'];
            $totalBuilder->groupStart()
                ->like('nombre_empresa', $buscar)
                ->orLike('telefono', $buscar)
                ->orLike('persona_contacto', $buscar)
            ->groupEnd();
        }
        $total = $totalBuilder->countAllResults();

        return $this->respond([
            'status'     => 'success',
            'data'       => $leads,
            'pagination' => [
                'current_page'  => $page,
                'per_page'      => $limit,
                'total_records' => $total,
                'total_pages'   => ceil($total / $limit),
            ],
        ]);
    }

    public function show($id = null)
    {
        $leadModel = new LeadModel();
        $lead = $leadModel->find($id);

        if (!$lead) {
            return $this->failNotFound('Prospecto no encontrado.');
        }

        return $this->respond([
            'status' => 'success',
            'data'   => $lead,
        ]);
    }

    public function update($id = null)
    {
        $leadModel = new LeadModel();
        $lead = $leadModel->find($id);

        if (!$lead) {
            return $this->failNotFound('Prospecto no encontrado.');
        }

        $raw = $this->request->getBody();
        $json = null;
        if (!empty($raw)) {
            if (!mb_detect_encoding($raw, 'UTF-8', true)) {
                $raw = mb_convert_encoding($raw, 'UTF-8');
            }
            $json = json_decode($raw, true);
        }
        if (!$json) {
            $json = $this->request->getRawInput();
        }

        $dataActualizar = [];
        if (isset($json['estado_llamada'])) {
            $dataActualizar['estado_llamada'] = $json['estado_llamada'];
            $dataActualizar['intentos_llamada'] = ($lead['intentos_llamada'] ?? 0) + 1;
            $dataActualizar['ultima_llamada_en'] = date('Y-m-d H:i:s');
        }
        if (isset($json['notas'])) {
            $dataActualizar['notas'] = $json['notas'];
        }
        if (isset($json['proxima_llamada_en'])) {
            $dataActualizar['proxima_llamada_en'] = $json['proxima_llamada_en'];
        }

        if (empty($dataActualizar)) {
            return $this->fail('No se enviaron datos para actualizar.');
        }

        $leadModel->update($id, $dataActualizar);

        return $this->respond([
            'status'  => 'success',
            'message' => 'Prospecto actualizado.',
            'data'    => $leadModel->find($id),
        ]);
    }

    public function delete($id = null)
    {
        $leadModel = new LeadModel();
        $lead = $leadModel->find($id);

        if (!$lead) {
            return $this->failNotFound('Prospecto no encontrado.');
        }

        $leadModel->delete($id);

        return $this->respondDeleted([
            'status'  => 'success',
            'message' => 'Prospecto eliminado correctamente.',
        ]);
    }

    public function stats()
    {
        $db = \Config\Database::connect();
        $builder = $db->table('leads');

        $total       = $builder->countAllResults(false);
        $pendientes  = (clone $builder)->where('estado_llamada', 'Pendiente')->countAllResults(false);
        $interesados = (clone $builder)->where('estado_llamada', 'Contactado - Interesado')->countAllResults(false);
        $noContesta  = (clone $builder)->where('estado_llamada', 'No contesta')->countAllResults(false);
        $descartados = (clone $builder)->where('estado_llamada', 'Descartado')->countAllResults(false);

        return $this->respond([
            'status' => 'success',
            'data'   => [
                'total'       => $total,
                'pendientes'  => $pendientes,
                'interesados' => $interesados,
                'no_contesta' => $noContesta,
                'descartados' => $descartados,
            ],
        ]);
    }

    /**
     * Exportar prospectos a archivo CSV descargable
     */
    public function export()
    {
        $leadModel = new LeadModel();
        $estado = $this->request->getGet('estado');
        
        $builder = $leadModel->builder();
        if (!empty($estado)) {
            $builder->where('estado_llamada', $estado);
        }
        $leads = $builder->orderBy('id', 'DESC')->get()->getResultArray();

        $filename = 'prospectos_' . date('Y-m-d_His') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');
        // BOM UTF-8 para Excel
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        fputcsv($output, [
            'ID', 'Empresa / Prestamista', 'Tipo', 'Telefono (+505)', 
            'Email', 'Contacto', 'Ciudad', 'Direccion', 'Estado', 
            'Intentos', 'Ultima Llamada', 'Proxima Llamada', 'Notas', 'Fuente Web'
        ]);

        foreach ($leads as $l) {
            fputcsv($output, [
                $l['id'],
                $l['nombre_empresa'],
                $l['tipo_entidad'],
                $l['telefono'],
                $l['email'],
                $l['persona_contacto'],
                $l['ciudad'],
                $l['direccion'],
                $l['estado_llamada'],
                $l['intentos_llamada'],
                $l['ultima_llamada_en'],
                $l['proxima_llamada_en'],
                $l['notas'],
                $l['sitio_web']
            ]);
        }

        fclose($output);
        exit;
    }
}
