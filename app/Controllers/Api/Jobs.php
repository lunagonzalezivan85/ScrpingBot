<?php

namespace App\Controllers\Api;

use CodeIgniter\RESTful\ResourceController;
use App\Models\JobModel;
use App\Models\LogModel;

class Jobs extends ResourceController
{
    protected $format = 'json';

    public function index()
    {
        $jobModel = new JobModel();
        $jobs = $jobModel->orderBy('id', 'DESC')->findAll(20);
        return $this->respond([
            'status' => 'success',
            'data'   => $jobs,
        ]);
    }

    public function create()
    {
        $raw = $this->request->getBody();
        $json = null;
        if (!empty($raw)) {
            if (!mb_detect_encoding($raw, 'UTF-8', true)) {
                $raw = mb_convert_encoding($raw, 'UTF-8');
            }
            $json = json_decode($raw, true);
        }
        if (!$json) {
            $json = $this->request->getPost();
        }

        $rules = [
            'sector' => 'required|min_length[2]|max_length[100]',
            'ciudad' => 'required|min_length[2]|max_length[100]',
            'limite' => 'permit_empty',
        ];

        if (!$this->validateData($json ?? [], $rules)) {
            return $this->failValidationErrors($this->validator->getErrors());
        }

        $sector = trim($json['sector']);
        $ciudad = trim($json['ciudad']);
        $limite = !empty($json['limite']) ? (int)$json['limite'] : 5;
        $pais   = $json['pais'] ?? 'Nicaragua';
        $fuente = $json['fuente_tipo'] ?? 'web_search';

        // Generar UUID único
        $jobUuid = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        $jobModel = new JobModel();
        $jobId = $jobModel->insert([
            'uuid'              => $jobUuid,
            'sector'            => $sector,
            'ciudad'            => $ciudad,
            'pais'              => $pais,
            'fuente_tipo'       => $fuente,
            'limite_solicitado' => $limite,
            'status'            => 'pending',
        ]);

        if (!$jobId) {
            return $this->failServerError('No se pudo registrar el trabajo en la base de datos.');
        }

        // Ejecutar el motor de Python en segundo plano con cmd /c en Windows
        $pythonExe = realpath(ROOTPATH . 'engine/venv/Scripts/python.exe');
        $workerPy  = realpath(ROOTPATH . 'engine/worker.py');
        $logFile   = WRITEPATH . 'logs/job_' . $jobId . '.log';

        if ($pythonExe && file_exists($pythonExe)) {
            $cmd = sprintf(
                'cmd /c start /B "" "%s" "%s" --job_id=%d > "%s" 2>&1',
                $pythonExe,
                $workerPy,
                $jobId,
                $logFile
            );
            pclose(popen($cmd, "r"));
        } else {
            $cmd = sprintf(
                'cmd /c start /B "" python "%s" --job_id=%d > "%s" 2>&1',
                $workerPy,
                $jobId,
                $logFile
            );
            pclose(popen($cmd, "r"));
        }

        return $this->respondCreated([
            'status'  => 'success',
            'message' => 'Trabajo de prospección iniciado en segundo plano.',
            'data'    => [
                'job_id' => $jobId,
                'uuid'   => $jobUuid,
                'status' => 'pending',
                'sector' => $sector,
                'ciudad' => $ciudad,
            ],
        ]);
    }

    public function status($id = null)
    {
        $jobModel = new JobModel();
        $job = $jobModel->find($id);

        if (!$job) {
            return $this->failNotFound('Trabajo no encontrado.');
        }

        $logModel = new LogModel();
        $logs = $logModel->where('job_id', $id)->orderBy('id', 'ASC')->findAll(50);

        return $this->respond([
            'status' => 'success',
            'data'   => [
                'job'  => $job,
                'logs' => $logs,
            ],
        ]);
    }
}
