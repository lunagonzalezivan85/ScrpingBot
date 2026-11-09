<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scraph - Prospección con IA y Dial CRM</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- React & Babel -->
    <script crossorigin src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
    <script crossorigin src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
    <script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen">
    <div id="root"></div>

    <script type="text/babel">
        const { useState, useEffect, useRef } = React;

        const BASE_URL = "<?= rtrim(base_url(), '/') ?>";
        const API_BASE = `${BASE_URL}/api`;

        function App() {
            // Estados principales
            const [leads, setLeads] = useState([]);
            const [stats, setStats] = useState({ total: 0, pendientes: 0, interesados: 0, no_contesta: 0, descartados: 0 });
            const [filtroEstado, setFiltroEstado] = useState("");
            const [busqueda, setBusqueda] = useState("");
            const [loading, setLoading] = useState(false);

            // Modal de detalle y edición
            const [leadSeleccionado, setLeadSeleccionado] = useState(null);

            // Estado del formulario de prospección
            const [formData, setFormData] = useState({
                sector: "Microcréditos y préstamos",
                ciudad: "Chinandega",
                limite: 5,
                fuente_tipo: "web_search",
                api_key: ""
            });

            // Estado de trabajos activos (Jobs)
            const [activeJob, setActiveJob] = useState(null);
            const [jobLogs, setJobLogs] = useState([]);
            const [isScraping, setIsScraping] = useState(false);

            useEffect(() => {
                cargarLeads();
                cargarStats();
            }, [filtroEstado]);

            useEffect(() => {
                let interval = null;
                if (activeJob && (activeJob.status === "pending" || activeJob.status === "running")) {
                    interval = setInterval(() => {
                        verificarEstadoJob(activeJob.id);
                    }, 2500);
                }
                return () => clearInterval(interval);
            }, [activeJob]);

            const cargarLeads = async () => {
                setLoading(true);
                try {
                    let url = `${API_BASE}/leads?limit=100`;
                    if (filtroEstado) url += `&estado=${encodeURIComponent(filtroEstado)}`;
                    if (busqueda) url += `&buscar=${encodeURIComponent(busqueda)}`;
                    const res = await fetch(url);
                    const json = await res.json();
                    if (json.status === "success") {
                        setLeads(json.data);
                    }
                } catch (e) {
                    console.error("Error cargando leads:", e);
                } finally {
                    setLoading(false);
                }
            };

            const cargarStats = async () => {
                try {
                    const res = await fetch(`${API_BASE}/leads/stats`);
                    const json = await res.json();
                    if (json.status === "success") {
                        setStats(json.data);
                    }
                } catch (e) {
                    console.error("Error cargando estadísticas:", e);
                }
            };

            const iniciarProspeccion = async (e) => {
                e.preventDefault();
                setIsScraping(true);
                try {
                    const res = await fetch(`${API_BASE}/jobs`, {
                        method: "POST",
                        headers: { "Content-Type": "application/json" },
                        body: JSON.stringify(formData)
                    });
                    const json = await res.json();
                    if (json.status === "success") {
                        setActiveJob({ id: json.data.job_id, status: "pending", limite_solicitado: formData.limite });
                        setJobLogs([{ nivel: "info", mensaje: "Trabajo encolado en segundo plano..." }]);
                    } else {
                        alert("Error: " + (json.messages ? Object.values(json.messages).join(", ") : "No se pudo iniciar"));
                        setIsScraping(false);
                    }
                } catch (err) {
                    alert("Error conectando al servidor: " + err.message);
                    setIsScraping(false);
                }
            };

            const verificarEstadoJob = async (jobId) => {
                try {
                    const res = await fetch(`${API_BASE}/jobs/${jobId}/status`);
                    const json = await res.json();
                    if (json.status === "success") {
                        const job = json.data.job;
                        setActiveJob(job);
                        setJobLogs(json.data.logs || []);

                        if (job.status === "completed" || job.status === "failed") {
                            setIsScraping(false);
                            cargarLeads();
                            cargarStats();
                        }
                    }
                } catch (e) {
                    console.error("Error verificando job:", e);
                }
            };

            const actualizarEstadoLead = async (id, nuevoEstado) => {
                try {
                    const res = await fetch(`${API_BASE}/leads/${id}`, {
                        method: "POST",
                        headers: { "Content-Type": "application/json" },
                        body: JSON.stringify({ estado_llamada: nuevoEstado })
                    });
                    const json = await res.json();
                    if (json.status === "success") {
                        setLeads(leads.map(l => l.id === id ? { 
                            ...l, 
                            estado_llamada: nuevoEstado, 
                            intentos_llamada: (l.intentos_llamada || 0) + 1,
                            ultima_llamada_en: new Date().toISOString()
                        } : l));
                        cargarStats();
                    }
                } catch (e) {
                    alert("Error actualizando lead: " + e.message);
                }
            };

            const guardarEdicionLead = async (e) => {
                e.preventDefault();
                if (!leadSeleccionado) return;
                try {
                    const res = await fetch(`${API_BASE}/leads/${leadSeleccionado.id}`, {
                        method: "POST",
                        headers: { "Content-Type": "application/json" },
                        body: JSON.stringify({
                            estado_llamada: leadSeleccionado.estado_llamada,
                            notas: leadSeleccionado.notas,
                            proxima_llamada_en: leadSeleccionado.proxima_llamada_en
                        })
                    });
                    const json = await res.json();
                    if (json.status === "success") {
                        setLeads(leads.map(l => l.id === leadSeleccionado.id ? leadSeleccionado : l));
                        setLeadSeleccionado(null);
                        cargarStats();
                    }
                } catch (e) {
                    alert("Error guardando cambios: " + e.message);
                }
            };

            const eliminarLead = async (id) => {
                if (!confirm("¿Deseas eliminar este prospecto?")) return;
                try {
                    const res = await fetch(`${API_BASE}/leads/${id}`, { method: "DELETE" });
                    const json = await res.json();
                    if (json.status === "success") {
                        setLeads(leads.filter(l => l.id !== id));
                        cargarStats();
                    }
                } catch (e) {
                    alert("Error eliminando lead: " + e.message);
                }
            };

            return (
                <div className="max-w-7xl mx-auto px-4 py-8">
                    {/* Header */}
                    <div className="flex flex-col md:flex-row md:items-center justify-between pb-6 border-b border-slate-200 mb-8 gap-4">
                        <div className="flex items-center gap-3">
                            <span className="bg-indigo-600 text-white p-2.5 rounded-xl shadow-md flex items-center justify-center">
                                <i className="bi bi-robot text-2xl"></i>
                            </span>
                            <div>
                                <h1 className="text-2xl font-bold tracking-tight text-slate-900">Scraph</h1>
                                <p className="text-sm text-slate-500">Motor de Prospección con IA (ScrapeGraphAI) & Dial CRM para Chinandega</p>
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            <a
                                href={`${API_BASE}/leads/export?estado=${encodeURIComponent(filtroEstado)}`}
                                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-white border border-slate-300 text-slate-700 hover:bg-slate-50 shadow-sm transition-all"
                                title="Descargar en Excel/CSV"
                            >
                                <i className="bi bi-file-earmark-spreadsheet text-emerald-600"></i>
                                Exportar CSV
                            </a>
                            <span className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-emerald-100 text-emerald-800">
                                <span className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                                Online (+505)
                            </span>
                        </div>
                    </div>

                    {/* Tarjetas de Estadísticas */}
                    <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-4 mb-8">
                        <div className="bg-white p-4 rounded-xl shadow-sm border border-slate-200">
                            <span className="text-xs font-semibold uppercase text-slate-400">Total Leads</span>
                            <p className="text-2xl font-bold text-slate-800 mt-1">{stats.total}</p>
                        </div>
                        <div className="bg-white p-4 rounded-xl shadow-sm border border-amber-200 bg-amber-50/30">
                            <span className="text-xs font-semibold uppercase text-amber-700">Pendientes</span>
                            <p className="text-2xl font-bold text-amber-600 mt-1">{stats.pendientes}</p>
                        </div>
                        <div className="bg-white p-4 rounded-xl shadow-sm border border-emerald-200 bg-emerald-50/30">
                            <span className="text-xs font-semibold uppercase text-emerald-700">Interesados</span>
                            <p className="text-2xl font-bold text-emerald-600 mt-1">{stats.interesados}</p>
                        </div>
                        <div className="bg-white p-4 rounded-xl shadow-sm border border-sky-200 bg-sky-50/30">
                            <span className="text-xs font-semibold uppercase text-sky-700">No Contesta</span>
                            <p className="text-2xl font-bold text-sky-600 mt-1">{stats.no_contesta}</p>
                        </div>
                        <div className="bg-white p-4 rounded-xl shadow-sm border border-slate-200">
                            <span className="text-xs font-semibold uppercase text-slate-400">Descartados</span>
                            <p className="text-2xl font-bold text-slate-500 mt-1">{stats.descartados}</p>
                        </div>
                    </div>

                    {/* Formulario de Prospección */}
                    <div className="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 mb-8">
                        <div className="flex items-center justify-between mb-4">
                            <div className="flex items-center gap-2">
                                <i className="bi bi-search text-indigo-600 font-bold"></i>
                                <h2 className="text-lg font-semibold text-slate-800">Lanzar Nueva Prospección Automática</h2>
                            </div>
                            <span className="text-xs text-slate-400">Motor: DuckDuckGo + ScrapeGraphAI + Playwright</span>
                        </div>
                        <form onSubmit={iniciarProspeccion} className="grid grid-cols-1 md:grid-cols-5 gap-4">
                            <div>
                                <label className="block text-xs font-medium text-slate-600 mb-1">Sector o Nicho</label>
                                <input
                                    type="text"
                                    value={formData.sector}
                                    onChange={(e) => setFormData({ ...formData, sector: e.target.value })}
                                    className="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                                    placeholder="Ej. Microcréditos, Prestamistas"
                                    required
                                />
                            </div>
                            <div>
                                <label className="block text-xs font-medium text-slate-600 mb-1">Ciudad / Departamento</label>
                                <input
                                    type="text"
                                    value={formData.ciudad}
                                    onChange={(e) => setFormData({ ...formData, ciudad: e.target.value })}
                                    className="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                                    placeholder="Ej. Chinandega"
                                    required
                                />
                            </div>
                            <div>
                                <label className="block text-xs font-medium text-slate-600 mb-1">Fuente</label>
                                <select
                                    value={formData.fuente_tipo}
                                    onChange={(e) => setFormData({ ...formData, fuente_tipo: e.target.value })}
                                    className="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                                >
                                    <option value="web_search">Búsqueda Abierta (Clasificados/Web)</option>
                                    <option value="pdf_conami">Registro Oficial CONAMI (PDFs)</option>
                                    <option value="directorio">Directorios Comerciales</option>
                                </select>
                            </div>
                            <div>
                                <label className="block text-xs font-medium text-slate-600 mb-1">Límite</label>
                                <select
                                    value={formData.limite}
                                    onChange={(e) => setFormData({ ...formData, limite: parseInt(e.target.value) })}
                                    className="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                                >
                                    <option value="3">3 prospectos (rápido)</option>
                                    <option value="5">5 prospectos</option>
                                    <option value="10">10 prospectos</option>
                                    <option value="20">20 prospectos</option>
                                </select>
                            </div>
                            <div className="flex items-end">
                                <button
                                    type="submit"
                                    disabled={isScraping}
                                    className={`w-full py-2 px-4 rounded-lg font-medium text-sm text-white shadow-sm flex items-center justify-center gap-2 transition-all ${
                                        isScraping ? "bg-indigo-400 cursor-not-allowed" : "bg-indigo-600 hover:bg-indigo-700"
                                    }`}
                                >
                                    {isScraping ? (
                                        <>
                                            <span className="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
                                            Extrayendo...
                                        </>
                                    ) : (
                                        <>
                                            <i className="bi bi-lightning-charge-fill text-amber-300"></i>
                                            Iniciar Prospección
                                        </>
                                    )}
                                </button>
                            </div>
                        </form>

                        {/* Banner de progreso y logs en tiempo real */}
                        {activeJob && (
                            <div className="mt-5 p-4 rounded-xl bg-slate-50 border border-slate-200">
                                <div className="flex items-center justify-between mb-2">
                                    <div className="flex items-center gap-2">
                                        <span className={`w-2.5 h-2.5 rounded-full ${activeJob.status === "running" ? "bg-amber-500 animate-ping" : activeJob.status === "completed" ? "bg-emerald-500" : "bg-slate-400"}`}></span>
                                        <span className="text-sm font-semibold text-slate-800">
                                            Estado del Trabajo #{activeJob.id}: {activeJob.status.toUpperCase()}
                                        </span>
                                    </div>
                                    <span className="text-xs bg-indigo-100 text-indigo-700 font-medium px-2 py-0.5 rounded-md">
                                        Leads Guardados: {activeJob.leads_guardados || 0} / {activeJob.limite_solicitado}
                                    </span>
                                </div>
                                <div className="bg-slate-900 text-slate-200 p-3 rounded-lg font-mono text-xs max-h-36 overflow-y-auto space-y-1">
                                    {jobLogs.length === 0 ? (
                                        <p className="text-slate-500">Esperando conexión con el motor Python...</p>
                                    ) : (
                                        jobLogs.map((log, i) => (
                                            <div key={i} className="flex gap-2">
                                                <span className={`text-[10px] uppercase font-bold ${log.nivel === 'success' ? 'text-emerald-400' : log.nivel === 'warning' ? 'text-amber-400' : log.nivel === 'error' ? 'text-rose-400' : 'text-slate-400'}`}>
                                                    [{log.nivel}]
                                                </span>
                                                <span>{log.mensaje}</span>
                                            </div>
                                        ))
                                    )}
                                </div>
                            </div>
                        )}
                    </div>

                    {/* Filtros y Marcador Dial CRM */}
                    <div className="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                        <div className="p-4 sm:p-6 border-b border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-4">
                            <div className="flex items-center gap-2">
                                <i className="bi bi-telephone-outbound text-indigo-600 font-bold"></i>
                                <h2 className="text-lg font-semibold text-slate-800">
                                    Marcador Telefónico (Dial CRM)
                                </h2>
                                <span className="text-xs bg-slate-100 text-slate-600 px-2 py-0.5 rounded-full font-medium">
                                    {leads.length} prospectos
                                </span>
                            </div>

                            <div className="flex flex-wrap items-center gap-2 w-full sm:w-auto">
                                <div className="relative flex-1 sm:w-60">
                                    <i className="bi bi-search absolute left-3 top-2.5 text-slate-400 text-xs"></i>
                                    <input
                                        type="text"
                                        placeholder="Buscar por nombre o tel..."
                                        value={busqueda}
                                        onChange={(e) => setBusqueda(e.target.value)}
                                        onKeyUp={(e) => e.key === 'Enter' && cargarLeads()}
                                        className="w-full pl-8 pr-3 py-1.5 text-xs border border-slate-300 rounded-lg focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                                    />
                                </div>

                                <div className="inline-flex rounded-lg border border-slate-200 p-0.5 bg-slate-50 text-xs">
                                    {["", "Pendiente", "Contactado - Interesado", "No contesta", "Descartado"].map((est) => (
                                        <button
                                            key={est}
                                            onClick={() => setFiltroEstado(est)}
                                            className={`px-3 py-1 rounded-md font-medium transition-colors ${
                                                filtroEstado === est ? "bg-white text-indigo-700 shadow-sm" : "text-slate-600 hover:text-slate-900"
                                            }`}
                                        >
                                            {est === "" ? "Todos" : est}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        </div>

                        {/* Tabla de Prospectos */}
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm text-slate-600">
                                <thead className="bg-slate-50 text-slate-700 text-xs uppercase font-semibold border-b border-slate-200">
                                    <tr>
                                        <th className="px-6 py-3">Empresa / Contacto</th>
                                        <th className="px-6 py-3">Acción Rápida (Llamar / WA)</th>
                                        <th className="px-6 py-3">Ubicación / Oferta</th>
                                        <th className="px-6 py-3">Estado de Llamada</th>
                                        <th className="px-6 py-3">Notas</th>
                                        <th className="px-4 py-3 text-right">Detalle / Acción</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {loading ? (
                                        <tr>
                                            <td colSpan="6" className="text-center py-12 text-slate-400">
                                                <div className="inline-block animate-spin rounded-full h-6 w-6 border-2 border-indigo-600 border-t-transparent"></div>
                                                <p className="mt-2 text-xs">Cargando prospectos...</p>
                                            </td>
                                        </tr>
                                    ) : leads.length === 0 ? (
                                        <tr>
                                            <td colSpan="6" className="text-center py-12 text-slate-400">
                                                <i className="bi bi-inbox text-3xl"></i>
                                                <p className="mt-2 text-sm font-medium">No hay prospectos con este filtro.</p>
                                                <p className="text-xs text-slate-400">Prueba lanzando una nueva prospección arriba.</p>
                                            </td>
                                        </tr>
                                    ) : (
                                        leads.map((lead) => {
                                            const telSoloDigitos = lead.telefono.replace(/[^\d]/g, '');
                                            const mensajeWA = encodeURIComponent(`Hola, le escribo en referencia a las opciones de préstamos y financiamiento en Chinandega para ${lead.nombre_empresa}. ¿Con quién tengo el gusto?`);

                                            return (
                                                <tr key={lead.id} className="hover:bg-slate-50 transition-colors">
                                                    <td className="px-6 py-4">
                                                        <div className="font-semibold text-slate-900">{lead.nombre_empresa}</div>
                                                        <div className="flex items-center gap-2 mt-1">
                                                            <span className="inline-block px-2 py-0.5 text-[11px] rounded bg-slate-100 text-slate-700 border border-slate-200">
                                                                {lead.tipo_entidad || 'No especificado'}
                                                            </span>
                                                            {lead.persona_contacto && (
                                                                <span className="text-xs text-slate-500">
                                                                    <i className="bi bi-person mr-1"></i>{lead.persona_contacto}
                                                                </span>
                                                            )}
                                                        </div>
                                                    </td>
                                                    <td className="px-6 py-4">
                                                        <div className="flex items-center gap-1.5">
                                                            {/* Botón de llamada telefónica */}
                                                            <a
                                                                href={`tel:${lead.telefono}`}
                                                                onClick={() => actualizarEstadoLead(lead.id, lead.estado_llamada === 'Pendiente' ? 'Contactado - Interesado' : lead.estado_llamada)}
                                                                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-semibold text-xs transition-colors border border-indigo-200"
                                                                title="Llamar con 1 clic (tel:)"
                                                            >
                                                                <i className="bi bi-telephone-fill"></i>
                                                                {lead.telefono}
                                                            </a>
                                                            {/* Botón directo a WhatsApp */}
                                                            <a
                                                                href={`https://wa.me/${telSoloDigitos}?text=${mensajeWA}`}
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                                className="p-1.5 rounded-lg bg-emerald-50 hover:bg-emerald-100 text-emerald-600 border border-emerald-200 transition-colors"
                                                                title="Enviar WhatsApp"
                                                            >
                                                                <i className="bi bi-whatsapp text-sm"></i>
                                                            </a>
                                                        </div>
                                                        <div className="text-[11px] text-slate-400 mt-1">
                                                            Intentos: {lead.intentos_llamada || 0}
                                                        </div>
                                                    </td>
                                                    <td className="px-6 py-4 max-w-xs">
                                                        <div className="text-xs text-slate-700 truncate" title={lead.direccion}>
                                                            <i className="bi bi-geo-alt text-slate-400 mr-1"></i>
                                                            {lead.direccion || lead.ciudad || "Chinandega"}
                                                        </div>
                                                        {lead.detalles_oferta && (
                                                            <div className="text-[11px] text-slate-500 line-clamp-2 mt-1" title={lead.detalles_oferta}>
                                                                {lead.detalles_oferta}
                                                            </div>
                                                        )}
                                                        {lead.sitio_web && (
                                                            <a href={lead.sitio_web} target="_blank" rel="noopener noreferrer" className="text-[11px] text-indigo-600 hover:underline block mt-0.5">
                                                                Ver fuente web <i className="bi bi-box-arrow-up-right text-[9px]"></i>
                                                            </a>
                                                        )}
                                                    </td>
                                                    <td className="px-6 py-4">
                                                        <select
                                                            value={lead.estado_llamada}
                                                            onChange={(e) => actualizarEstadoLead(lead.id, e.target.value)}
                                                            className={`text-xs font-medium rounded-lg px-2.5 py-1.5 border focus:outline-none focus:ring-1 focus:ring-indigo-500 ${
                                                                lead.estado_llamada === 'Pendiente' ? 'bg-amber-50 text-amber-800 border-amber-200' :
                                                                lead.estado_llamada === 'Contactado - Interesado' ? 'bg-emerald-50 text-emerald-800 border-emerald-200' :
                                                                lead.estado_llamada === 'No contesta' ? 'bg-sky-50 text-sky-800 border-sky-200' :
                                                                'bg-slate-100 text-slate-700 border-slate-200'
                                                            }`}
                                                        >
                                                            <option value="Pendiente">Pendiente</option>
                                                            <option value="Contactado - Interesado">Interesado</option>
                                                            <option value="No contesta">No contesta</option>
                                                            <option value="Volver a llamar">Volver a llamar</option>
                                                            <option value="Numero equivocado">Número equivocado</option>
                                                            <option value="Descartado">Descartado</option>
                                                        </select>
                                                    </td>
                                                    <td className="px-6 py-4">
                                                        <span className="text-xs text-slate-600 truncate block max-w-[150px]">
                                                            {lead.notas || <em className="text-slate-400">Sin notas</em>}
                                                        </span>
                                                    </td>
                                                    <td className="px-4 py-4 text-right">
                                                        <div className="flex items-center justify-end gap-1">
                                                            <button
                                                                onClick={() => setLeadSeleccionado({ ...lead })}
                                                                className="text-slate-500 hover:text-indigo-600 p-1.5 rounded hover:bg-slate-100 transition-colors"
                                                                title="Editar / Ver Detalle"
                                                            >
                                                                <i className="bi bi-pencil-square"></i>
                                                            </button>
                                                            <button
                                                                onClick={() => eliminarLead(lead.id)}
                                                                className="text-slate-400 hover:text-rose-600 p-1.5 rounded hover:bg-slate-100 transition-colors"
                                                                title="Eliminar lead"
                                                            >
                                                                <i className="bi bi-trash"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            );
                                        })
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {/* Modal de Detalle y Seguimiento */}
                    {leadSeleccionado && (
                        <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
                            <div className="bg-white rounded-2xl shadow-xl max-w-lg w-full p-6 animate-in fade-in zoom-in duration-150">
                                <div className="flex items-center justify-between pb-4 border-b border-slate-100">
                                    <h3 className="text-base font-bold text-slate-800">
                                        Detalle y Gestión de Llamada
                                    </h3>
                                    <button
                                        onClick={() => setLeadSeleccionado(null)}
                                        className="text-slate-400 hover:text-slate-600 p-1"
                                    >
                                        <i className="bi bi-x-lg"></i>
                                    </button>
                                </div>
                                <form onSubmit={guardarEdicionLead} className="space-y-4 mt-4">
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-500 uppercase">Empresa / Titular</label>
                                        <p className="text-sm font-bold text-slate-800">{leadSeleccionado.nombre_empresa}</p>
                                        <span className="text-xs text-indigo-600">{leadSeleccionado.telefono} ({leadSeleccionado.ciudad})</span>
                                    </div>
                                    {leadSeleccionado.direccion && (
                                        <div>
                                            <label className="block text-xs font-semibold text-slate-500 uppercase">Dirección</label>
                                            <p className="text-xs text-slate-600">{leadSeleccionado.direccion}</p>
                                        </div>
                                    )}
                                    {leadSeleccionado.detalles_oferta && (
                                        <div className="p-3 rounded-lg bg-slate-50 border border-slate-200">
                                            <label className="block text-xs font-semibold text-slate-500 uppercase mb-1">Detalles de Oferta Detectados</label>
                                            <p className="text-xs text-slate-600">{leadSeleccionado.detalles_oferta}</p>
                                        </div>
                                    )}
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-700 mb-1">Estado de la Gestión</label>
                                        <select
                                            value={leadSeleccionado.estado_llamada}
                                            onChange={(e) => setLeadSeleccionado({ ...leadSeleccionado, estado_llamada: e.target.value })}
                                            className="w-full text-sm border border-slate-300 rounded-lg p-2 focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                                        >
                                            <option value="Pendiente">Pendiente</option>
                                            <option value="Contactado - Interesado">Contactado - Interesado</option>
                                            <option value="Contactado - No Interesado">Contactado - No Interesado</option>
                                            <option value="No contesta">No contesta</option>
                                            <option value="Volver a llamar">Volver a llamar (Seguimiento)</option>
                                            <option value="Numero equivocado">Número equivocado</option>
                                            <option value="Descartado">Descartado</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-700 mb-1">Fecha y Hora de Próxima Llamada (Opcional)</label>
                                        <input
                                            type="datetime-local"
                                            value={leadSeleccionado.proxima_llamada_en ? leadSeleccionado.proxima_llamada_en.replace(' ', 'T') : ''}
                                            onChange={(e) => setLeadSeleccionado({ ...leadSeleccionado, proxima_llamada_en: e.target.value ? e.target.value.replace('T', ' ') : null })}
                                            className="w-full text-sm border border-slate-300 rounded-lg p-2 focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-700 mb-1">Notas de la Conversación</label>
                                        <textarea
                                            rows="3"
                                            value={leadSeleccionado.notas || ''}
                                            onChange={(e) => setLeadSeleccionado({ ...leadSeleccionado, notas: e.target.value })}
                                            placeholder="Detalla lo hablado con el cliente, montos solicitados, objeciones..."
                                            className="w-full text-sm border border-slate-300 rounded-lg p-2 focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                                        ></textarea>
                                    </div>
                                    <div className="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                                        <button
                                            type="button"
                                            onClick={() => setLeadSeleccionado(null)}
                                            className="px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100 rounded-lg transition-colors"
                                        >
                                            Cancelar
                                        </button>
                                        <button
                                            type="submit"
                                            className="px-4 py-2 text-xs font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg shadow-sm transition-colors"
                                        >
                                            Guardar Cambios
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    )}
                </div>
            );
        }

        ReactDOM.createRoot(document.getElementById('root')).render(<App />);
    </script>
</body>
</html>
