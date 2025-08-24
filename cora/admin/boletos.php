<?php
session_start();
require_once '../config/database.php';
require_once '../services/BoletoService.php';
require_once '../api/CoraAPI.php';

// Verificar se está logado
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$db = new Database();
$boletoService = new BoletoService();
$action = $_GET['action'] ?? 'list';
$erro = '';
$sucesso = '';

// Processar ações
if ($_POST) {
    if ($action === 'new') {
        $cliente_id = $_POST['cliente_id'] ?? '';
        $plano_id = $_POST['plano_id'] ?? null;
        $valor = str_replace(['R$', '.', ','], ['', '', '.'], $_POST['valor'] ?? '0');
        $data_vencimento = $_POST['data_vencimento'] ?? '';
        $descricao = trim($_POST['descricao'] ?? '');
        $tipo = $_POST['tipo'] ?? 'avulso';
        $multa = $_POST['multa'] ?? 2.00;
        $juros_dia = $_POST['juros_dia'] ?? 0.0333;
        $desconto = str_replace(['R$', '.', ','], ['', '', '.'], $_POST['desconto'] ?? '0');
        $data_limite_desconto = $_POST['data_limite_desconto'] ?? null;
        
        if (empty($cliente_id) || empty($valor) || empty($data_vencimento)) {
            $erro = 'Preencha os campos obrigatórios';
        } elseif (!is_numeric($valor) || $valor <= 0) {
            $erro = 'Valor inválido';
        } else {
            try {
                $dadosBoleto = [
                    'cliente_id' => $cliente_id,
                    'plano_id' => $plano_id ?: null,
                    'valor' => floatval($valor),
                    'data_vencimento' => $data_vencimento,
                    'descricao' => $descricao ?: 'Boleto Avulso - Tray Sistemas',
                    'tipo' => $tipo,
                    'multa' => floatval($multa),
                    'juros_dia' => floatval($juros_dia),
                    'desconto' => floatval($desconto),
                    'data_limite_desconto' => $data_limite_desconto ?: null
                ];
                
                $resultado = $boletoService->criarBoleto($dadosBoleto);
                
                if ($resultado['success']) {
                    $sucesso = 'Boleto gerado com sucesso! Nosso número: ' . $resultado['nosso_numero'];
                    Logger::success('Boletos', 'Boleto avulso criado', [
                        'nosso_numero' => $resultado['nosso_numero'],
                        'valor' => $valor,
                        'cliente_id' => $cliente_id
                    ]);
                } else {
                    $erro = 'Erro ao gerar boleto: ' . $resultado['error'];
                }
            } catch (Exception $e) {
                $erro = 'Erro interno: ' . $e->getMessage();
                Logger::error('Boletos', 'Erro ao criar boleto', ['error' => $e->getMessage()]);
            }
        }
    } elseif ($action === 'cancel' && isset($_POST['boleto_id'])) {
        try {
            $resultado = $boletoService->cancelarBoleto($_POST['boleto_id'], $_POST['motivo'] ?? null);
            
            if ($resultado['success']) {
                $sucesso = 'Boleto cancelado com sucesso!';
            } else {
                $erro = 'Erro ao cancelar: ' . $resultado['error'];
            }
        } catch (Exception $e) {
            $erro = 'Erro interno: ' . $e->getMessage();
        }
    }
}

// Buscar boletos
$filtros = [];
$cliente_id = $_GET['cliente_id'] ?? '';
$status = $_GET['status'] ?? '';
$data_inicio = $_GET['data_inicio'] ?? '';
$data_fim = $_GET['data_fim'] ?? '';

if ($cliente_id) $filtros['cliente_id'] = $cliente_id;
if ($status) $filtros['status'] = $status;
if ($data_inicio) $filtros['data_inicio'] = $data_inicio;
if ($data_fim) $filtros['data_fim'] = $data_fim;

$boletos = $boletoService->listarBoletos($filtros);

// Buscar clientes para formulário
$clientes = $db->fetchAll("SELECT id, nome, email FROM clientes WHERE status = 'ativo' ORDER BY nome");

// Buscar planos para formulário
$planos = $db->fetchAll("SELECT id, nome, valor FROM planos WHERE status = 'ativo' ORDER BY nome");

// Buscar boleto específico para detalhes
$boleto = null;
if ($action === 'view' && isset($_GET['id'])) {
    $boleto = $boletoService->buscarBoleto($_GET['id']);
    if (!$boleto) {
        $erro = 'Boleto não encontrado';
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
        case 'pago': return 'status-success';
        case 'pendente': return 'status-warning';
        case 'vencido': return 'status-danger';
        default: return 'status-secondary';
    }
}

function getStatusTexto($status) {
    switch ($status) {
        case 'pago': return 'Pago';
        case 'pendente': return 'Pendente';
        case 'vencido': return 'Vencido';
        default: return 'Cancelado';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Boletos - Tray Sistemas</title>
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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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

        .boleto-details {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .detail-item {
            background: #f8fafc;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #3b82f6;
        }

        .detail-label {
            font-size: 12px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .detail-value {
            font-size: 16px;
            font-weight: 600;
            color: #1e293b;
        }

        .qr-code-section {
            text-align: center;
            padding: 20px;
            background: #f8fafc;
            border-radius: 8px;
            margin: 20px 0;
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
            
            .detail-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="admin-header">
        <div class="header-content">
            <div class="logo-section">
                <img src="https://rricaria.sirv.com/traysistemas/traysistemas.webp" alt="Tray Sistemas" class="logo-img">
                <h1><i class="fas fa-file-invoice"></i> Boletos</h1>
            </div>
            
            <div class="admin-nav">
                <a href="dashboard.php"><i class="fas fa-chart-line"></i> Dashboard</a>
                <a href="clientes.php"><i class="fas fa-users"></i> Clientes</a>
                <a href="boletos.php" class="active"><i class="fas fa-file-invoice"></i> Boletos</a>
                <a href="assinaturas.php"><i class="fas fa-sync"></i> Assinaturas</a>
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
            <div class="page-header">
                <h1 class="page-title">
                    <i class="fas fa-file-invoice"></i>
                    Gestão de Boletos
                </h1>
                <div>
                    <a href="?action=new" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Novo Boleto
                    </a>
                    <button onclick="executarCron()" class="btn btn-secondary" id="btnCron">
                        <i class="fas fa-sync"></i> Sincronizar
                    </button>
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
                                <option value="pendente" <?= $status === 'pendente' ? 'selected' : '' ?>>Pendente</option>
                                <option value="pago" <?= $status === 'pago' ? 'selected' : '' ?>>Pago</option>
                                <option value="vencido" <?= $status === 'vencido' ? 'selected' : '' ?>>Vencido</option>
                                <option value="cancelado" <?= $status === 'cancelado' ? 'selected' : '' ?>>Cancelado</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Data Início</label>
                            <input type="date" name="data_inicio" class="form-control" value="<?= htmlspecialchars($data_inicio) ?>">
                        </div>
                        
                        <div class="form-group">
                            <label>Data Fim</label>
                            <input type="date" name="data_fim" class="form-control" value="<?= htmlspecialchars($data_fim) ?>">
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
                            <th>Nosso Número</th>
                            <th>Cliente</th>
                            <th>Valor</th>
                            <th>Vencimento</th>
                            <th>Status</th>
                            <th>Tipo</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($boletos)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 40px;">
                                    <i class="fas fa-file-invoice" style="font-size: 48px; color: #cbd5e1; margin-bottom: 15px;"></i>
                                    <div>Nenhum boleto encontrado</div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($boletos as $blt): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 600;"><?= htmlspecialchars($blt['nosso_numero'] ?: 'N/A') ?></div>
                                        <div style="font-size: 11px; color: #6b7280;">ID: <?= $blt['id'] ?></div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600;"><?= htmlspecialchars($blt['cliente_nome']) ?></div>
                                        <div style="font-size: 12px; color: #6b7280;"><?= htmlspecialchars($blt['cliente_email']) ?></div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600;"><?= formatarMoeda($blt['valor']) ?></div>
                                    </td>
                                    <td>
                                        <div><?= formatarData($blt['data_vencimento']) ?></div>
                                        <?php if ($blt['data_pagamento']): ?>
                                            <div style="font-size: 11px; color: #10b981;">Pago: <?= formatarData($blt['data_pagamento']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge <?= getStatusClass($blt['status']) ?>">
                                            <?= getStatusTexto($blt['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div><?= ucfirst($blt['tipo']) ?></div>
                                        <?php if ($blt['plano_nome']): ?>
                                            <div style="font-size: 11px; color: #6b7280;"><?= htmlspecialchars($blt['plano_nome']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                            <a href="?action=view&id=<?= $blt['id'] ?>" class="btn btn-secondary btn-sm">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if ($blt['status'] !== 'pago' && $blt['status'] !== 'cancelado'): ?>
                                                <button onclick="cancelarBoleto(<?= $blt['id'] ?>)" class="btn btn-danger btn-sm">
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

        <?php elseif ($action === 'new'): ?>
            <div class="page-header">
                <h1 class="page-title">
                    <i class="fas fa-plus"></i>
                    Novo Boleto
                </h1>
                <a href="?" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>

            <div class="form-section">
                <form method="POST">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Cliente *</label>
                            <select name="cliente_id" class="form-control" required>
                                <option value="">Selecione o cliente</option>
                                <?php foreach ($clientes as $cliente): ?>
                                    <option value="<?= $cliente['id'] ?>">
                                        <?= htmlspecialchars($cliente['nome']) ?> - <?= htmlspecialchars($cliente['email']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Plano (opcional)</label>
                            <select name="plano_id" class="form-control">
                                <option value="">Boleto Avulso</option>
                                <?php foreach ($planos as $plano): ?>
                                    <option value="<?= $plano['id'] ?>" data-valor="<?= $plano['valor'] ?>">
                                        <?= htmlspecialchars($plano['nome']) ?> - <?= formatarMoeda($plano['valor']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Valor *</label>
                            <input 
                                type="text" 
                                name="valor" 
                                class="form-control" 
                                placeholder="R$ 0,00"
                                required
                                id="valorInput"
                            >
                        </div>
                        
                        <div class="form-group">
                            <label>Data de Vencimento *</label>
                            <input 
                                type="date" 
                                name="data_vencimento" 
                                class="form-control" 
                                min="<?= date('Y-m-d') ?>"
                                value="<?= date('Y-m-d', strtotime('+10 days')) ?>"
                                required
                            >
                        </div>
                        
                        <div class="form-group">
                            <label>Descrição</label>
                            <input 
                                type="text" 
                                name="descricao" 
                                class="form-control" 
                                placeholder="Descrição do boleto"
                            >
                        </div>
                        
                        <div class="form-group">
                            <label>Tipo</label>
                            <select name="tipo" class="form-control">
                                <option value="avulso">Avulso</option>
                                <option value="recorrente">Recorrente</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Multa (%) *</label>
                            <input 
                                type="number" 
                                name="multa" 
                                class="form-control" 
                                value="2.00"
                                step="0.01"
                                min="0"
                                max="20"
                                required
                            >
                        </div>
                        
                        <div class="form-group">
                            <label>Juros ao Dia (%) *</label>
                            <input 
                                type="number" 
                                name="juros_dia" 
                                class="form-control" 
                                value="0.0333"
                                step="0.0001"
                                min="0"
                                max="1"
                                required
                            >
                        </div>
                        
                        <div class="form-group">
                            <label>Desconto</label>
                            <input 
                                type="text" 
                                name="desconto" 
                                class="form-control" 
                                placeholder="R$ 0,00"
                            >
                        </div>
                        
                        <div class="form-group">
                            <label>Data Limite Desconto</label>
                            <input 
                                type="date" 
                                name="data_limite_desconto" 
                                class="form-control"
                            >
                        </div>
                    </div>
                    
                    <div class="actions">
                        <a href="?" class="btn btn-secondary">Cancelar</a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Gerar Boleto
                        </button>
                    </div>
                </form>
            </div>

        <?php elseif ($action === 'view' && $boleto): ?>
            <div class="page-header">
                <h1 class="page-title">
                    <i class="fas fa-eye"></i>
                    Detalhes do Boleto
                </h1>
                <a href="?" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>

            <div class="boleto-details">
                <div class="detail-grid">
                    <div class="detail-item">
                        <div class="detail-label">Nosso Número</div>
                        <div class="detail-value"><?= htmlspecialchars($boleto['nosso_numero'] ?: 'N/A') ?></div>
                    </div>
                    
                    <div class="detail-item">
                        <div class="detail-label">Cliente</div>
                        <div class="detail-value"><?= htmlspecialchars($boleto['cliente_nome']) ?></div>
                    </div>
                    
                    <div class="detail-item">
                        <div class="detail-label">Email</div>
                        <div class="detail-value"><?= htmlspecialchars($boleto['cliente_email']) ?></div>
                    </div>
                    
                    <div class="detail-item">
                        <div class="detail-label">Valor</div>
                        <div class="detail-value"><?= formatarMoeda($boleto['valor']) ?></div>
                    </div>
                    
                    <div class="detail-item">
                        <div class="detail-label">Data de Vencimento</div>
                        <div class="detail-value"><?= formatarData($boleto['data_vencimento']) ?></div>
                    </div>
                    
                    <div class="detail-item">
                        <div class="detail-label">Data de Emissão</div>
                        <div class="detail-value"><?= formatarData($boleto['data_emissao']) ?></div>
                    </div>
                    
                    <div class="detail-item">
                        <div class="detail-label">Status</div>
                        <div class="detail-value">
                            <span class="status-badge <?= getStatusClass($boleto['status']) ?>">
                                <?= getStatusTexto($boleto['status']) ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="detail-item">
                        <div class="detail-label">Tipo</div>
                        <div class="detail-value"><?= ucfirst($boleto['tipo']) ?></div>
                    </div>
                    
                    <?php if ($boleto['plano_nome']): ?>
                    <div class="detail-item">
                        <div class="detail-label">Plano</div>
                        <div class="detail-value"><?= htmlspecialchars($boleto['plano_nome']) ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($boleto['data_pagamento']): ?>
                    <div class="detail-item">
                        <div class="detail-label">Data de Pagamento</div>
                        <div class="detail-value"><?= formatarData($boleto['data_pagamento']) ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="detail-item">
                        <div class="detail-label">Multa</div>
                        <div class="detail-value"><?= $boleto['multa'] ?>%</div>
                    </div>
                    
                    <div class="detail-item">
                        <div class="detail-label">Juros/Dia</div>
                        <div class="detail-value"><?= $boleto['juros_dia'] ?>%</div>
                    </div>
                </div>
                
                <?php if ($boleto['descricao']): ?>
                <div class="detail-item" style="grid-column: 1/-1; margin-top: 20px;">
                    <div class="detail-label">Descrição</div>
                    <div class="detail-value"><?= htmlspecialchars($boleto['descricao']) ?></div>
                </div>
                <?php endif; ?>
                
                <?php if ($boleto['linha_digitavel']): ?>
                <div style="margin: 30px 0; padding: 20px; background: #f8fafc; border-radius: 8px;">
                    <h4 style="margin-bottom: 15px; color: #1e293b;">Linha Digitável:</h4>
                    <div style="font-family: monospace; font-size: 16px; font-weight: 600; color: #1e293b; word-break: break-all;">
                        <?= htmlspecialchars($boleto['linha_digitavel']) ?>
                    </div>
                    <button onclick="copiarTexto('<?= addslashes($boleto['linha_digitavel']) ?>')" class="btn btn-secondary btn-sm" style="margin-top: 10px;">
                        <i class="fas fa-copy"></i> Copiar
                    </button>
                </div>
                <?php endif; ?>
                
                <?php if ($boleto['qr_code']): ?>
                <div class="qr-code-section">
                    <h4 style="margin-bottom: 15px; color: #1e293b;">QR Code PIX:</h4>
                    <div style="font-family: monospace; font-size: 12px; background: white; padding: 15px; border-radius: 8px; word-break: break-all; max-width: 500px; margin: 0 auto;">
                        <?= htmlspecialchars($boleto['qr_code']) ?>
                    </div>
                    <button onclick="copiarTexto('<?= addslashes($boleto['qr_code']) ?>')" class="btn btn-success btn-sm" style="margin-top: 10px;">
                        <i class="fas fa-copy"></i> Copiar PIX
                    </button>
                </div>
                <?php endif; ?>
                
                <div class="actions">
                    <?php if ($boleto['url_boleto']): ?>
                        <a href="<?= htmlspecialchars($boleto['url_boleto']) ?>" target="_blank" class="btn btn-primary">
                            <i class="fas fa-file-pdf"></i> Ver PDF
                        </a>
                    <?php endif; ?>
                    
                    <?php if ($boleto['status'] !== 'pago' && $boleto['status'] !== 'cancelado'): ?>
                        <button onclick="cancelarBoleto(<?= $boleto['id'] ?>)" class="btn btn-danger">
                            <i class="fas fa-ban"></i> Cancelar Boleto
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Modal para cancelar boleto -->
    <div id="modalCancel" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div style="background: white; padding: 30px; border-radius: 12px; max-width: 500px; width: 90%;">
            <h3 style="margin-bottom: 20px; color: #1e293b;">Cancelar Boleto</h3>
            <form id="formCancel" method="POST">
                <input type="hidden" name="boleto_id" id="cancelBoletoId">
                <div class="form-group">
                    <label>Motivo do cancelamento:</label>
                    <textarea name="motivo" class="form-control" rows="3" placeholder="Informe o motivo (opcional)"></textarea>
                </div>
                <div class="actions">
                    <button type="button" onclick="fecharModal()" class="btn btn-secondary">Cancelar</button>
                    <button type="submit" name="action" value="cancel" class="btn btn-danger">
                        <i class="fas fa-ban"></i> Confirmar Cancelamento
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Máscara para valores monetários
        function mascaraMoeda(input) {
            let value = input.value.replace(/\D/g, '');
            value = (value / 100).toFixed(2) + '';
            value = value.replace(".", ",");
            value = value.replace(/(\d)(\d{3})(\d{3}),/g, "$1.$2.$3,");
            value = value.replace(/(\d)(\d{3}),/g, "$1.$2,");
            input.value = 'R$ ' + value;
        }

        // Aplicar máscara nos campos de valor
        document.querySelectorAll('input[name="valor"], input[name="desconto"]').forEach(input => {
            input.addEventListener('input', function() {
                mascaraMoeda(this);
            });
        });

        // Auto-preencher valor quando selecionar plano
        document.querySelector('select[name="plano_id"]')?.addEventListener('change', function() {
            const option = this.options[this.selectedIndex];
            const valor = option.getAttribute('data-valor');
            if (valor && valor > 0) {
                const valorInput = document.getElementById('valorInput');
                if (valorInput) {
                    valorInput.value = parseFloat(valor).toFixed(2).replace('.', ',');
                    mascaraMoeda(valorInput);
                }
            }
        });

        // Função para cancelar boleto
        function cancelarBoleto(boletoId) {
            document.getElementById('cancelBoletoId').value = boletoId;
            document.getElementById('modalCancel').style.display = 'flex';
        }

        // Fechar modal
        function fecharModal() {
            document.getElementById('modalCancel').style.display = 'none';
        }

        // Copiar texto para clipboard
        function copiarTexto(texto) {
            navigator.clipboard.writeText(texto).then(function() {
                alert('✅ Texto copiado para a área de transferência!');
            }).catch(function(err) {
                console.error('Erro ao copiar: ', err);
                prompt('📋 Copie o texto abaixo:', texto);
            });
        }

        // Executar cron manualmente
        async function executarCron() {
            const btn = document.getElementById('btnCron');
            const originalText = btn.innerHTML;
            
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sincronizando...';
            btn.disabled = true;
            
            try {
                const response = await fetch('../cron/daily_tasks.php?manual=1');
                
                if (response.ok) {
                    alert('✅ Sincronização executada com sucesso!');
                    location.reload();
                } else {
                    throw new Error('Erro na sincronização');
                }
            } catch (error) {
                alert('❌ Erro na sincronização: ' + error.message);
            } finally {
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        }

        // Fechar modal clicando fora
        document.getElementById('modalCancel').addEventListener('click', function(e) {
            if (e.target === this) {
                fecharModal();
            }
        });
    </script>
</body>
</html>