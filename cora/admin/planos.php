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
        $nome = trim($_POST['nome'] ?? '');
        $descricao = trim($_POST['descricao'] ?? '');
        $valor = str_replace(['R$', '.', ','], ['', '', '.'], $_POST['valor'] ?? '0');
        $periodicidade = $_POST['periodicidade'] ?? '';
        $status = $_POST['status'] ?? 'ativo';
        
        if (empty($nome) || empty($valor) || empty($periodicidade)) {
            $erro = 'Preencha os campos obrigatórios';
        } elseif (!is_numeric($valor) || $valor <= 0) {
            $erro = 'Valor deve ser um número maior que zero';
        } else {
            try {
                if ($action === 'new') {
                    $sql = "INSERT INTO planos (nome, descricao, valor, periodicidade, status) VALUES (?, ?, ?, ?, ?)";
                    $db->execute($sql, [$nome, $descricao, floatval($valor), $periodicidade, $status]);
                    $sucesso = 'Plano cadastrado com sucesso!';
                    Logger::success('Planos', 'Plano criado', ['nome' => $nome, 'valor' => $valor]);
                } else {
                    $id = $_POST['id'];
                    $sql = "UPDATE planos SET nome = ?, descricao = ?, valor = ?, periodicidade = ?, status = ? WHERE id = ?";
                    $db->execute($sql, [$nome, $descricao, floatval($valor), $periodicidade, $status, $id]);
                    $sucesso = 'Plano atualizado com sucesso!';
                    Logger::success('Planos', 'Plano atualizado', ['id' => $id, 'nome' => $nome]);
                }
            } catch (Exception $e) {
                $erro = 'Erro ao salvar: ' . $e->getMessage();
                Logger::error('Planos', 'Erro ao salvar plano', ['error' => $e->getMessage()]);
            }
        }
    } elseif ($action === 'delete' && isset($_POST['plano_id'])) {
        try {
            // Verificar se plano tem assinaturas
            $temAssinaturas = $db->fetch("SELECT COUNT(*) as total FROM assinaturas WHERE plano_id = ?", [$_POST['plano_id']]);
            
            if ($temAssinaturas['total'] > 0) {
                $erro = 'Não é possível excluir plano que possui assinaturas ativas';
            } else {
                $db->execute("DELETE FROM planos WHERE id = ?", [$_POST['plano_id']]);
                $sucesso = 'Plano excluído com sucesso!';
                Logger::success('Planos', 'Plano excluído', ['id' => $_POST['plano_id']]);
            }
        } catch (Exception $e) {
            $erro = 'Erro ao excluir: ' . $e->getMessage();
        }
    }
}

// Buscar planos
$planos = $db->fetchAll("
    SELECT p.*, 
           COUNT(a.id) as total_assinaturas,
           COUNT(CASE WHEN a.status = 'ativa' THEN 1 END) as assinaturas_ativas
    FROM planos p
    LEFT JOIN assinaturas a ON p.id = a.plano_id
    GROUP BY p.id
    ORDER BY p.nome
");

// Buscar plano específico para edição
$plano = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $plano = $db->fetch("SELECT * FROM planos WHERE id = ?", [$_GET['id']]);
    if (!$plano) {
        $erro = 'Plano não encontrado';
        $action = 'list';
    }
}

function formatarMoeda($valor) {
    return 'R$ ' . number_format($valor, 2, ',', '.');
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

function getStatusClass($status) {
    return $status === 'ativo' ? 'status-success' : 'status-secondary';
}

function calcularValorAnual($valor, $periodicidade) {
    switch ($periodicidade) {
        case 'mensal': return $valor * 12;
        case 'bimestral': return $valor * 6;
        case 'trimestral': return $valor * 4;
        case 'semestral': return $valor * 2;
        case 'anual': return $valor;
        default: return $valor;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Planos - Tray Sistemas</title>
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

        .btn-danger {
            background: #ef4444;
            color: white;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }

        .planos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .plano-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            position: relative;
        }

        .plano-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.12);
        }

        .plano-header {
            padding: 25px;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            border-bottom: 1px solid #e5e7eb;
        }

        .plano-nome {
            font-size: 20px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 8px;
        }

        .plano-descricao {
            color: #64748b;
            font-size: 14px;
            line-height: 1.5;
        }

        .plano-valor {
            padding: 25px;
            text-align: center;
            border-bottom: 1px solid #e5e7eb;
        }

        .valor-principal {
            font-size: 36px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 5px;
        }

        .valor-periodicidade {
            color: #64748b;
            font-size: 14px;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 1px;
        }

        .valor-anual {
            color: #10b981;
            font-size: 12px;
            font-weight: 600;
            margin-top: 8px;
        }

        .plano-stats {
            padding: 20px 25px;
            background: #f8fafc;
        }

        .stat-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #e5e7eb;
        }

        .stat-item:last-child {
            border-bottom: none;
        }

        .stat-label {
            font-size: 12px;
            color: #64748b;
            text-transform: uppercase;
            font-weight: 600;
        }

        .stat-value {
            font-size: 14px;
            font-weight: 600;
            color: #1e293b;
        }

        .plano-actions {
            padding: 20px 25px;
            display: flex;
            gap: 10px;
            justify-content: center;
        }

        .status-badge {
            position: absolute;
            top: 15px;
            right: 15px;
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
            font-size: 16px;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }

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

        .no-planos {
            text-align: center;
            padding: 60px 20px;
            color: #64748b;
        }

        .no-planos .emoji {
            font-size: 64px;
            margin-bottom: 20px;
        }

        @media (max-width: 768px) {
            .container {
                padding: 20px;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .planos-grid {
                grid-template-columns: 1fr;
            }
            
            .form-grid {
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
                <h1><i class="fas fa-box"></i> Planos</h1>
            </div>
            
            <div class="admin-nav">
                <a href="dashboard.php"><i class="fas fa-chart-line"></i> Dashboard</a>
                <a href="clientes.php"><i class="fas fa-users"></i> Clientes</a>
                <a href="boletos.php"><i class="fas fa-file-invoice"></i> Boletos</a>
                <a href="assinaturas.php"><i class="fas fa-sync"></i> Assinaturas</a>
                <a href="planos.php" class="active"><i class="fas fa-box"></i> Planos</a>
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
                    <i class="fas fa-box"></i>
                    Gestão de Planos
                </h1>
                <a href="?action=new" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Novo Plano
                </a>
            </div>

            <?php if (empty($planos)): ?>
                <div class="no-planos">
                    <div class="emoji">📦</div>
                    <h3>Nenhum plano cadastrado</h3>
                    <p>Comece criando seu primeiro plano de monitoramento.</p>
                    <a href="?action=new" class="btn btn-primary" style="margin-top: 20px;">
                        <i class="fas fa-plus"></i> Criar Primeiro Plano
                    </a>
                </div>
            <?php else: ?>
                <div class="planos-grid">
                    <?php foreach ($planos as $plan): ?>
                        <div class="plano-card">
                            <div class="status-badge <?= getStatusClass($plan['status']) ?>">
                                <?= ucfirst($plan['status']) ?>
                            </div>
                            
                            <div class="plano-header">
                                <div class="plano-nome"><?= htmlspecialchars($plan['nome']) ?></div>
                                <?php if ($plan['descricao']): ?>
                                    <div class="plano-descricao"><?= htmlspecialchars($plan['descricao']) ?></div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="plano-valor">
                                <div class="valor-principal"><?= formatarMoeda($plan['valor']) ?></div>
                                <div class="valor-periodicidade"><?= getPeriodicidadeTexto($plan['periodicidade']) ?></div>
                                <?php if ($plan['periodicidade'] !== 'anual'): ?>
                                    <div class="valor-anual">
                                        <?= formatarMoeda(calcularValorAnual($plan['valor'], $plan['periodicidade'])) ?> / ano
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="plano-stats">
                                <div class="stat-item">
                                    <span class="stat-label">Total Assinaturas</span>
                                    <span class="stat-value"><?= $plan['total_assinaturas'] ?></span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-label">Assinaturas Ativas</span>
                                    <span class="stat-value" style="color: #10b981;"><?= $plan['assinaturas_ativas'] ?></span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-label">Receita Mensal</span>
                                    <span class="stat-value" style="color: #3b82f6;">
                                        <?php
                                        $receitaMensal = 0;
                                        switch ($plan['periodicidade']) {
                                            case 'mensal': $receitaMensal = $plan['valor'] * $plan['assinaturas_ativas']; break;
                                            case 'bimestral': $receitaMensal = ($plan['valor'] * $plan['assinaturas_ativas']) / 2; break;
                                            case 'trimestral': $receitaMensal = ($plan['valor'] * $plan['assinaturas_ativas']) / 3; break;
                                            case 'semestral': $receitaMensal = ($plan['valor'] * $plan['assinaturas_ativas']) / 6; break;
                                            case 'anual': $receitaMensal = ($plan['valor'] * $plan['assinaturas_ativas']) / 12; break;
                                        }
                                        echo formatarMoeda($receitaMensal);
                                        ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="plano-actions">
                                <a href="?action=edit&id=<?= $plan['id'] ?>" class="btn btn-secondary btn-sm">
                                    <i class="fas fa-edit"></i> Editar
                                </a>
                                <a href="assinaturas.php?plano_id=<?= $plan['id'] ?>" class="btn btn-primary btn-sm">
                                    <i class="fas fa-users"></i> Assinaturas
                                </a>
                                <?php if ($plan['total_assinaturas'] == 0): ?>
                                    <button onclick="excluirPlano(<?= $plan['id'] ?>)" class="btn btn-danger btn-sm">
                                        <i class="fas fa-trash"></i> Excluir
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php elseif ($action === 'new' || $action === 'edit'): ?>
            <div class="page-header">
                <h1 class="page-title">
                    <i class="fas fa-<?= $action === 'new' ? 'plus' : 'edit' ?>"></i>
                    <?= $action === 'new' ? 'Novo Plano' : 'Editar Plano' ?>
                </h1>
                <a href="?" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>

            <div class="form-section">
                <form method="POST">
                    <?php if ($action === 'edit'): ?>
                        <input type="hidden" name="id" value="<?= $plano['id'] ?>">
                    <?php endif; ?>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Nome do Plano *</label>
                            <input 
                                type="text" 
                                name="nome" 
                                class="form-control" 
                                value="<?= htmlspecialchars($plano['nome'] ?? '') ?>"
                                placeholder="Ex: Plano Básico, Premium, etc."
                                required
                            >
                        </div>
                        
                        <div class="form-group">
                            <label>Valor *</label>
                            <input 
                                type="text" 
                                name="valor" 
                                class="form-control" 
                                value="<?= $plano ? formatarMoeda($plano['valor']) : '' ?>"
                                placeholder="R$ 0,00"
                                id="valorInput"
                                required
                            >
                        </div>
                        
                        <div class="form-group">
                            <label>Periodicidade *</label>
                            <select name="periodicidade" class="form-control" required>
                                <option value="">Selecione a periodicidade</option>
                                <option value="mensal" <?= ($plano['periodicidade'] ?? '') === 'mensal' ? 'selected' : '' ?>>Mensal</option>
                                <option value="bimestral" <?= ($plano['periodicidade'] ?? '') === 'bimestral' ? 'selected' : '' ?>>Bimestral (2 meses)</option>
                                <option value="trimestral" <?= ($plano['periodicidade'] ?? '') === 'trimestral' ? 'selected' : '' ?>>Trimestral (3 meses)</option>
                                <option value="semestral" <?= ($plano['periodicidade'] ?? '') === 'semestral' ? 'selected' : '' ?>>Semestral (6 meses)</option>
                                <option value="anual" <?= ($plano['periodicidade'] ?? '') === 'anual' ? 'selected' : '' ?>>Anual (12 meses)</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Status *</label>
                            <select name="status" class="form-control" required>
                                <option value="ativo" <?= ($plano['status'] ?? 'ativo') === 'ativo' ? 'selected' : '' ?>>Ativo</option>
                                <option value="inativo" <?= ($plano['status'] ?? '') === 'inativo' ? 'selected' : '' ?>>Inativo</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Descrição</label>
                        <textarea 
                            name="descricao" 
                            class="form-control" 
                            rows="4"
                            placeholder="Descreva os benefícios e características do plano (opcional)"
                        ><?= htmlspecialchars($plano['descricao'] ?? '') ?></textarea>
                    </div>
                    
                    <div class="actions">
                        <a href="?" class="btn btn-secondary">Cancelar</a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i>
                            <?= $action === 'new' ? 'Cadastrar Plano' : 'Salvar Alterações' ?>
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <!-- Modal para excluir plano -->
    <div id="modalDelete" class="modal">
        <div class="modal-content">
            <h3 style="margin-bottom: 20px; color: #ef4444;">Excluir Plano</h3>
            <p><strong>ATENÇÃO:</strong> Esta ação não pode ser desfeita!</p>
            <p>Tem certeza que deseja excluir permanentemente este plano?</p>
            <form id="formDelete" method="POST">
                <input type="hidden" name="plano_id" id="deletePlanoId">
                <div class="actions">
                    <button type="button" onclick="fecharModal()" class="btn btn-secondary">Cancelar</button>
                    <button type="submit" name="action" value="delete" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Confirmar Exclusão
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

        // Aplicar máscara no valor
        document.getElementById('valorInput')?.addEventListener('input', function() {
            mascaraMoeda(this);
        });

        // Função para excluir plano
        function excluirPlano(planoId) {
            document.getElementById('deletePlanoId').value = planoId;
            document.getElementById('modalDelete').style.display = 'flex';
        }

        // Fechar modal
        function fecharModal() {
            document.getElementById('modalDelete').style.display = 'none';
        }

        // Fechar modal clicando fora
        document.getElementById('modalDelete').addEventListener('click', function(e) {
            if (e.target === this) {
                fecharModal();
            }
        });

        // ESC para fechar modal
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                fecharModal();
            }
        });

        // Animações nos cards
        document.querySelectorAll('.plano-card').forEach((card, index) => {
            card.style.animationDelay = `${index * 0.1}s`;
            card.style.animation = 'fadeInUp 0.6s ease-out forwards';
        });

        // CSS para animação
        const style = document.createElement('style');
        style.textContent = `
            @keyframes fadeInUp {
                from {
                    opacity: 0;
                    transform: translateY(30px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
            
            .plano-card {
                opacity: 0;
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>