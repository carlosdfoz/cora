<?php
session_start();
require_once '../config/database.php';

// Verificar se está logado
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$db = new Database();
$action = $_GET['action'] ?? 'list';
$erro = '';
$sucesso = '';

// Processar ações
if ($_POST) {
    if ($action === 'new' || $action === 'edit') {
        $cliente_id = $_POST['cliente_id'] ?? '';
        $plano_id = $_POST['plano_id'] ?? '';
        $data_inicio = $_POST['data_inicio'] ?? '';
        $data_fim = $_POST['data_fim'] ?? null;
        $dia_vencimento = $_POST['dia_vencimento'] ?? 10;
        $valor_personalizado = $_POST['valor_personalizado'] ?? null;
        $status = $_POST['status'] ?? 'ativa';
        $observacoes = trim($_POST['observacoes'] ?? '');
        
        if ($valor_personalizado) {
            $valor_personalizado = str_replace(['R$', '.', ','], ['', '', '.'], $valor_personalizado);
            $valor_personalizado = floatval($valor_personalizado);
        }
        
        if (empty($cliente_id) || empty($plano_id) || empty($data_inicio)) {
            $erro = 'Preencha os campos obrigatórios';
        } elseif ($dia_vencimento < 1 || $dia_vencimento > 31) {
            $erro = 'Dia de vencimento deve estar entre 1 e 31';
        } else {
            try {
                if ($action === 'new') {
                    // Verificar se já existe assinatura ativa para este cliente/plano
                    $existe = $db->fetch("SELECT id FROM assinaturas WHERE cliente_id = ? AND plano_id = ? AND status = 'ativa'", [$cliente_id, $plano_id]);
                    if ($existe) {
                        $erro = 'Cliente já possui assinatura ativa para este plano';
                    } else {
                        $sql = "INSERT INTO assinaturas (cliente_id, plano_id, data_inicio, data_fim, dia_vencimento, valor_personalizado, status, observacoes) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                        $db->execute($sql, [$cliente_id, $plano_id, $data_inicio, $data_fim ?: null, $dia_vencimento, $valor_personalizado ?: null, $status, $observacoes]);
                        $sucesso = 'Assinatura criada com sucesso!';
                        Logger::success('Assinaturas', 'Assinatura criada', ['cliente_id' => $cliente_id, 'plano_id' => $plano_id]);
                    }
                } else {
                    $id = $_POST['id'];
                    $sql = "UPDATE assinaturas SET data_fim = ?, dia_vencimento = ?, valor_personalizado = ?, status = ?, observacoes = ? WHERE id = ?";
                    $db->execute($sql, [$data_fim ?: null, $dia_vencimento, $valor_personalizado ?: null, $status, $observacoes, $id]);
                    $sucesso = 'Assinatura atualizada com sucesso!';
                    Logger::success('Assinaturas', 'Assinatura atualizada', ['id' => $id]);
                }
            } catch (Exception $e) {
                $erro = 'Erro ao salvar: ' . $e->getMessage();
                Logger::error('Assinaturas', 'Erro ao salvar assinatura', ['error' => $e->getMessage()]);
            }
        }
    } elseif ($action === 'pause' && isset($_POST['assinatura_id'])) {
        try {
            $db->execute("UPDATE assinaturas SET status = 'pausada' WHERE id = ?", [$_POST['assinatura_id']]);
            $sucesso = 'Assinatura pausada com sucesso!';
        } catch (Exception $e) {
            $erro = 'Erro ao pausar: ' . $e->getMessage();
        }
    } elseif ($action === 'resume' && isset($_POST['assinatura_id'])) {
        try {
            $db->execute("UPDATE assinaturas SET status = 'ativa' WHERE id = ?", [$_POST['assinatura_id']]);
            $sucesso = 'Assinatura reativada com sucesso!';
        } catch (Exception $e) {
            $erro = 'Erro ao reativar: ' . $e->getMessage();
        }
    } elseif ($action === 'cancel' && isset($_POST['assinatura_id'])) {
        try {
            $db->execute("UPDATE assinaturas SET status = 'cancelada', data_fim = CURDATE() WHERE id = ?", [$_POST['assinatura_id']]);
            $sucesso = 'Assinatura cancelada com sucesso!';
        } catch (Exception $e) {
            $erro = 'Erro ao cancelar: ' . $e->getMessage();
        }
    }
}

// Buscar assinaturas
$filtros = [];
$params = [];
$where = [];

$cliente_id = $_GET['cliente_id'] ?? '';
$status = $_GET['status'] ?? '';

if ($cliente_id) {
    $where[] = "a.cliente_id = ?";
    $params[] = $cliente_id;
}

if ($status) {
    $where[] = "a.status = ?";
    $params[] = $status;
}

$sql = "SELECT a.*, c.nome as cliente_nome, c.email as cliente_email, 
               p.nome as plano_nome, p.valor as plano_valor, p.periodicidade
        FROM assinaturas a
        INNER JOIN clientes c ON a.cliente_id = c.id
        INNER JOIN planos p ON a.plano_id = p.id";

if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY a.data_cadastro DESC";

$assinaturas = $db->fetchAll($sql, $params);

// Buscar clientes para formulário
$clientes = $db->fetchAll("SELECT id, nome, email FROM clientes WHERE status = 'ativo' ORDER BY nome");

// Buscar planos para formulário
$planos = $db->fetchAll("SELECT id, nome, valor, periodicidade FROM planos WHERE status = 'ativo' ORDER BY nome");

// Buscar assinatura específica para edição
$assinatura = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $assinatura = $db->fetch("SELECT * FROM assinaturas WHERE id = ?", [$_GET['id']]);
    if (!$assinatura) {
        $erro = 'Assinatura não encontrada';
        $action = 'list';
    }
}

function formatarMoeda($valor) {
    return 'R$ ' . number_format($valor, 2, ',', '.');
}

function formatarData($data) {
    return date('d/m/Y', strtotime($data));
}

function getStatusClass($status) {
    switch ($status) {
        case 'ativa': return 'status-success';
        case 'pausada': return 'status-warning';
        case 'cancelada': return 'status-danger';
        case 'vencida': return 'status-secondary';
        default: return 'status-secondary';
    }
}

function getStatusTexto($status) {
    switch ($status) {
        case 'ativa': return 'Ativa';
        case 'pausada': return 'Pausada';
        case 'cancelada': return 'Cancelada';
        case 'vencida': return 'Vencida';
        default: return ucfirst($status);
    }
}

function getPeriodicidadeTexto($periodicidade) {
    switch ($periodicidade) {
        case 'mensal': return 'Mensal';
        case 'bimestral': return 'Bimestral';
        case 'trimestral': return 'Trimestral';
        case 'semestral': return 'Semestral';
        case 'anual': return 'Anual';
        default: return ucfirst($periodicidade);
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assinaturas - Tray Sistemas</title>
    <link href="https://fonts.googleapis.com/css2?family=Segoe+UI:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8fafc;
            color: #1e293b;
        }

        .admin-header {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            padding: 20px 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1400px;
            margin: 0 auto;
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .logo-img {
            height: 40px;
            width: auto;
        }

        .admin-header h1 {
            font-size: 24px;
            font-weight: 700;
        }

        .admin-nav {
            display: flex;
            gap: 20px;
            align-items: center;
        }

        .admin-nav a {
            color: white;
            text-decoration: none;
            font-weight: 500;
            padding: 8px 15px;
            border-radius: 6px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .admin-nav a:hover, .admin-nav a.active {
            background: rgba(255,255,255,0.2);
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px;
        }

        .page-header {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-title {
            font-size: 28px;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
            cursor: pointer;
        }

        .btn-primary {
            background: linear-gradient(135deg, #3b82f6, #1e40af);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(59, 130, 246, 0.3);
        }

        .btn-secondary {
            background: #6b7280;
            color: white;
        }

        .btn-success {
            background: #10b981;
            color: white;
        }

        .btn-warning {
            background: #f59e0b;
            color: white;
        }

        .btn-danger {
            background: #ef4444;
            color: white;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }

        .filters-section {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .table-section {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            overflow: hidden;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th,
        .table td {
            padding: 15px 20px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }

        .table th {
            background: #f8fafc;
            font-weight: 600;
            color: #475569;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .table tbody tr:hover {
            background: #f8fafc;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-success {
            background: #dcfce7;
            color: #166534;
        }

        .status-warning {
            background: #fef3c7;
            color: #92400e;
        }

        .status-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-secondary {
            background: #f3f4f6;
            color: #6b7280;
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: #dcfce7;
            border: 1px solid #bbf7d0;
            color: #166534;
        }

        .alert-danger {
            background: #fee2e2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }

        .form-section {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }

        .info-card {
            background: #f0f9ff;
            border: 2px solid #0ea5e9;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .info-card h4 {
            color: #0369a1;
            margin-bottom: 10px;
        }

        .info-card p {
            color: #0c4a6e;
            margin: 0;
            font-size: 14px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            text-align: center;
            border-top: 4px solid var(--border-color);
        }

        .stat-card.blue { --border-color: #3b82f6; }
        .stat-card.green { --border-color: #10b981; }
        .stat-card.yellow { --border-color: #f59e0b; }
        .stat-card.red { --border-color: #ef4444; }

        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 5px;
        }

        .stat-label {
            color: #64748b;
            font-size: 14px;
            font-weight: 500;
        }

        @media (max-width: 768px) {
            .container {
                padding: 20px;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .filters-grid {
                grid-template-columns: 1fr;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 12px;
            max-width: 500px;
            width: 90%;
        }
    </style>
</head>
<body>
    <div class="admin-header">
        <div class="header-content">
            <div class="logo-section">
                <img src="https://rricaria.sirv.com/traysistemas/traysistemas.webp" alt="Tray Sistemas" class="logo-img">
                <h1><i class="fas fa-sync"></i> Assinaturas</h1>
            </div>
            
            <div class="admin-nav">
                <a href="dashboard.php"><i class="fas fa-chart-line"></i> Dashboard</a>
                <a href="clientes.php"><i class="fas fa-users"></i> Clientes</a>
                <a href="boletos.php"><i class="fas fa-file-invoice"></i> Boletos</a>
                <a href="assinaturas.php" class="active"><i class="fas fa-sync"></i> Assinaturas</a>
                <a href="planos.php"><i class="fas fa-box"></i> Planos</a>
                <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Sair</a>
            </div>
        </div>
    </div>

    <div class="container">
        <?php if ($erro): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($erro) ?>
            </div>
        <?php endif; ?>

        <?php if ($sucesso): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($sucesso) ?>
            </div>
        <?php endif; ?>

        <?php if ($action === 'list'): ?>
            <!-- Estatísticas das assinaturas -->
            <?php
            $totalAssinaturas = count($assinaturas);
            $ativas = count(array_filter($assinaturas, fn($a) => $a['status'] === 'ativa'));
            $pausadas = count(array_filter($assinaturas, fn($a) => $a['status'] === 'pausada'));
            $canceladas = count(array_filter($assinaturas, fn($a) => $a['status'] === 'cancelada'));
            $valorMensalTotal = array_sum(array_map(function($a) {
                return $a['status'] === 'ativa' ? ($a['valor_personalizado'] ?: $a['plano_valor']) : 0;
            }, $assinaturas));
            ?>
            
            <div class="stats-grid">
                <div class="stat-card blue">
                    <div class="stat-value"><?= $totalAssinaturas ?></div>
                    <div class="stat-label">Total de Assinaturas</div>
                </div>
                <div class="stat-card green">
                    <div class="stat-value"><?= $ativas ?></div>
                    <div class="stat-label">Assinaturas Ativas</div>
                </div>
                <div class="stat-card yellow">
                    <div class="stat-value"><?= $pausadas ?></div>
                    <div class="stat-label">Pausadas</div>
                </div>
                <div class="stat-card red">
                    <div class="stat-value"><?= $canceladas ?></div>
                    <div class="stat-label">Canceladas</div>
                </div>
            </div>

            <div class="page-header">
                <h1 class="page-title">
                    <i class="fas fa-sync"></i>
                    Gestão de Assinaturas
                </h1>
                <div>
                    <span style="background: #f0f9ff; color: #0369a1; padding: 8px 16px; border-radius: 20px; font-size: 14px; font-weight: 600;">
                        <i class="fas fa-chart-line"></i> Receita Mensal: <?= formatarMoeda($valorMensalTotal) ?>
                    </span>
                    <a href="?action=new" class="btn btn-primary" style="margin-left: 15px;">
                        <i class="fas fa-plus"></i> Nova Assinatura
                    </a>
                </div>
            </div>

            <div class="filters-section">
                <form method="GET">
                    <div class="filters-grid">
                        <div class="form-group">
                            <label>Cliente</label>
                            <select name="cliente_id" class="form-control">
                                <option value="">Todos os clientes</option>
                                <?php foreach ($clientes as $cliente): ?>
                                    <option value="<?= $cliente['id'] ?>" <?= $cliente_id == $cliente['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cliente['nome']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status" class="form-control">
                                <option value="">Todos os status</option>
                                <option value="ativa" <?= $status === 'ativa' ? 'selected' : '' ?>>Ativa</option>
                                <option value="pausada" <?= $status === 'pausada' ? 'selected' : '' ?>>Pausada</option>
                                <option value="cancelada" <?= $status === 'cancelada' ? 'selected' : '' ?>>Cancelada</option>
                                <option value="vencida" <?= $status === 'vencida' ? 'selected' : '' ?>>Vencida</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="actions" style="border: none; padding: 0;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Filtrar
                        </button>
                        <a href="?" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Limpar
                        </a>
                    </div>
                </form>
            </div>

            <div class="table-section">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Cliente</th>
                            <th>Plano</th>
                            <th>Valor</th>
                            <th>Periodicidade</th>
                            <th>Vencimento</th>
                            <th>Status</th>
                            <th>Início</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($assinaturas)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 40px;">
                                    <i class="fas fa-sync" style="font-size: 48px; color: #cbd5e1; margin-bottom: 15px;"></i>
                                    <div>Nenhuma assinatura encontrada</div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($assinaturas as $assin): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 600;"><?= htmlspecialchars($assin['cliente_nome']) ?></div>
                                        <div style="font-size: 12px; color: #6b7280;"><?= htmlspecialchars($assin['cliente_email']) ?></div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600;"><?= htmlspecialchars($assin['plano_nome']) ?></div>
                                        <div style="font-size: 11px; color: #6b7280;">ID: <?= $assin['id'] ?></div>
                                    </td>
                                    <td>
                                        <?php $valor = $assin['valor_personalizado'] ?: $assin['plano_valor']; ?>
                                        <div style="font-weight: 600;"><?= formatarMoeda($valor) ?></div>
                                        <?php if ($assin['valor_personalizado']): ?>
                                            <div style="font-size: 11px; color: #f59e0b;">Personalizado</div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= getPeriodicidadeTexto($assin['periodicidade']) ?></td>
                                    <td>
                                        <div>Dia <?= $assin['dia_vencimento'] ?></div>
                                    </td>
                                    <td>
                                        <span class="status-badge <?= getStatusClass($assin['status']) ?>">
                                            <?= getStatusTexto($assin['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div><?= formatarData($assin['data_inicio']) ?></div>
                                        <?php if ($assin['data_fim']): ?>
                                            <div style="font-size: 11px; color: #ef4444;">Fim: <?= formatarData($assin['data_fim']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                            <a href="?action=edit&id=<?= $assin['id'] ?>" class="btn btn-secondary btn-sm">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="boletos.php?cliente_id=<?= $assin['cliente_id'] ?>" class="btn btn-primary btn-sm">
                                                <i class="fas fa-file-invoice"></i>
                                            </a>
                                            
                                            <?php if ($assin['status'] === 'ativa'): ?>
                                                <button onclick="pausarAssinatura(<?= $assin['id'] ?>)" class="btn btn-warning btn-sm">
                                                    <i class="fas fa-pause"></i>
                                                </button>
                                                <button onclick="cancelarAssinatura(<?= $assin['id'] ?>)" class="btn btn-danger btn-sm">
                                                    <i class="fas fa-ban"></i>
                                                </button>
                                            <?php elseif ($assin['status'] === 'pausada'): ?>
                                                <button onclick="reativarAssinatura(<?= $assin['id'] ?>)" class="btn btn-success btn-sm">
                                                    <i class="fas fa-play"></i>
                                                </button>
                                                <button onclick="cancelarAssinatura(<?= $assin['id'] ?>)" class="btn btn-danger btn-sm">
                                                    <i class="fas fa-ban"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        <?php elseif ($action === 'new' || $action === 'edit'): ?>
            <div class="page-header">
                <h1 class="page-title">
                    <i class="fas fa-<?= $action === 'new' ? 'plus' : 'edit' ?>"></i>
                    <?= $action === 'new' ? 'Nova Assinatura' : 'Editar Assinatura' ?>
                </h1>
                <a href="?" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>

            <?php if ($action === 'new'): ?>
            <div class="info-card">
                <h4><i class="fas fa-info-circle"></i> Informação</h4>
                <p>
                    A assinatura gerará boletos automaticamente conforme a periodicidade do plano escolhido. 
                    O primeiro boleto será gerado no dia especificado como vencimento.
                </p>
            </div>
            <?php endif; ?>

            <div class="form-section">
                <form method="POST">
                    <?php if ($action === 'edit'): ?>
                        <input type="hidden" name="id" value="<?= $assinatura['id'] ?>">
                    <?php endif; ?>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Cliente *</label>
                            <select name="cliente_id" class="form-control" required <?= $action === 'edit' ? 'disabled' : '' ?>>
                                <option value="">Selecione o cliente</option>
                                <?php foreach ($clientes as $cliente): ?>
                                    <option value="<?= $cliente['id'] ?>" <?= ($assinatura['cliente_id'] ?? '') == $cliente['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cliente['nome']) ?> - <?= htmlspecialchars($cliente['email']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($action === 'edit'): ?>
                                <input type="hidden" name="cliente_id" value="<?= $assinatura['cliente_id'] ?>">
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-group">
                            <label>Plano *</label>
                            <select name="plano_id" class="form-control" required <?= $action === 'edit' ? 'disabled' : '' ?>>
                                <option value="">Selecione o plano</option>
                                <?php foreach ($planos as $plano): ?>
                                    <option value="<?= $plano['id'] ?>" data-valor="<?= $plano['valor'] ?>" <?= ($assinatura['plano_id'] ?? '') == $plano['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($plano['nome']) ?> - <?= formatarMoeda($plano['valor']) ?> (<?= getPeriodicidadeTexto($plano['periodicidade']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($action === 'edit'): ?>
                                <input type="hidden" name="plano_id" value="<?= $assinatura['plano_id'] ?>">
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-group">
                            <label>Data de Início *</label>
                            <input 
                                type="date" 
                                name="data_inicio" 
                                class="form-control" 
                                value="<?= $assinatura['data_inicio'] ?? date('Y-m-d') ?>"
                                <?= $action === 'edit' ? 'readonly' : '' ?>
                                required
                            >
                        </div>
                        
                        <div class="form-group">
                            <label>Data de Fim (opcional)</label>
                            <input 
                                type="date" 
                                name="data_fim" 
                                class="form-control" 
                                value="<?= $assinatura['data_fim'] ?? '' ?>"
                            >
                        </div>
                        
                        <div class="form-group">
                            <label>Dia de Vencimento *</label>
                            <select name="dia_vencimento" class="form-control" required>
                                <?php for ($i = 1; $i <= 31; $i++): ?>
                                    <option value="<?= $i ?>" <?= ($assinatura['dia_vencimento'] ?? 10) == $i ? 'selected' : '' ?>>
                                        Dia <?= $i ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Valor Personalizado (opcional)</label>
                            <input 
                                type="text" 
                                name="valor_personalizado" 
                                class="form-control" 
                                placeholder="R$ 0,00"
                                value="<?= $assinatura['valor_personalizado'] ? formatarMoeda($assinatura['valor_personalizado']) : '' ?>"
                                id="valorPersonalizado"
                            >
                            <div style="font-size: 12px; color: #6b7280; margin-top: 5px;">
                                Deixe em branco para usar o valor do plano
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Status *</label>
                            <select name="status" class="form-control" required>
                                <option value="ativa" <?= ($assinatura['status'] ?? 'ativa') === 'ativa' ? 'selected' : '' ?>>Ativa</option>
                                <option value="pausada" <?= ($assinatura['status'] ?? '') === 'pausada' ? 'selected' : '' ?>>Pausada</option>
                                <option value="cancelada" <?= ($assinatura['status'] ?? '') === 'cancelada' ? 'selected' : '' ?>>Cancelada</option>
                                <option value="vencida" <?= ($assinatura['status'] ?? '') === 'vencida' ? 'selected' : '' ?>>Vencida</option>
                            </select>
                        </div>
                        
                        <div class="form-group" style="grid-column: 1/-1;">
                            <label>Observações</label>
                            <textarea 
                                name="observacoes" 
                                class="form-control" 
                                rows="3"
                                placeholder="Observações sobre a assinatura (opcional)"
                            ><?= htmlspecialchars($assinatura['observacoes'] ?? '') ?></textarea>
                        </div>
                    </div>
                    
                    <div class="actions">
                        <a href="?" class="btn btn-secondary">Cancelar</a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i>
                            <?= $action === 'new' ? 'Criar Assinatura' : 'Salvar Alterações' ?>
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <!-- Modais para ações -->
    <div id="modalPause" class="modal">
        <div class="modal-content">
            <h3 style="margin-bottom: 20px;">Pausar Assinatura</h3>
            <p>Tem certeza que deseja pausar esta assinatura? Nenhum boleto será gerado enquanto estiver pausada.</p>
            <form id="formPause" method="POST">
                <input type="hidden" name="assinatura_id" id="pauseAssinaturaId">
                <div class="actions">
                    <button type="button" onclick="fecharModal('modalPause')" class="btn btn-secondary">Cancelar</button>
                    <button type="submit" name="action" value="pause" class="btn btn-warning">
                        <i class="fas fa-pause"></i> Pausar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div id="modalResume" class="modal">
        <div class="modal-content">
            <h3 style="margin-bottom: 20px;">Reativar Assinatura</h3>
            <p>Tem certeza que deseja reativar esta assinatura? Os boletos voltarão a ser gerados automaticamente.</p>
            <form id="formResume" method="POST">
                <input type="hidden" name="assinatura_id" id="resumeAssinaturaId">
                <div class="actions">
                    <button type="button" onclick="fecharModal('modalResume')" class="btn btn-secondary">Cancelar</button>
                    <button type="submit" name="action" value="resume" class="btn btn-success">
                        <i class="fas fa-play"></i> Reativar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div id="modalCancel" class="modal">
        <div class="modal-content">
            <h3 style="margin-bottom: 20px;">Cancelar Assinatura</h3>
            <p style="color: #ef4444; font-weight: 600;">ATENÇÃO: Esta ação não pode ser desfeita!</p>
            <p>Tem certeza que deseja cancelar permanentemente esta assinatura?</p>
            <form id="formCancel" method="POST">
                <input type="hidden" name="assinatura_id" id="cancelAssinaturaId">
                <div class="actions">
                    <button type="button" onclick="fecharModal('modalCancel')" class="btn btn-secondary">Voltar</button>
                    <button type="submit" name="action" value="cancel" class="btn btn-danger">
                        <i class="fas fa-ban"></i> Cancelar Assinatura
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Máscara para valores monetários
        function mascaraMoeda(input) {
            let value = input.value.replace(/\D/g, '');
            if (value === '') {
                input.value = '';
                return;
            }
            value = (value / 100).toFixed(2) + '';
            value = value.replace(".", ",");
            value = value.replace(/(\d)(\d{3})(\d{3}),/g, "$1.$2.$3,");
            value = value.replace(/(\d)(\d{3}),/g, "$1.$2,");
            input.value = 'R$ ' + value;
        }

        // Aplicar máscara no valor personalizado
        document.getElementById('valorPersonalizado')?.addEventListener('input', function() {
            mascaraMoeda(this);
        });

        // Funções dos modais
        function pausarAssinatura(id) {
            document.getElementById('pauseAssinaturaId').value = id;
            document.getElementById('modalPause').style.display = 'flex';
        }

        function reativarAssinatura(id) {
            document.getElementById('resumeAssinaturaId').value = id;
            document.getElementById('modalResume').style.display = 'flex';
        }

        function cancelarAssinatura(id) {
            document.getElementById('cancelAssinaturaId').value = id;
            document.getElementById('modalCancel').style.display = 'flex';
        }

        function fecharModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Fechar modais clicando fora
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    fecharModal(this.id);
                }
            });
        });

        // ESC para fechar modais
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal').forEach(modal => {
                    modal.style.display = 'none';
                });
            }
        });
    </script>
</body>
</html>