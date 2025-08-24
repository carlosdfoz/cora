<?php
session_start();
require_once '../config/database.php';
require_once '../services/BoletoService.php';

// Verificar se está logado
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$boletoService = new BoletoService();
$db = new Database();

// Obter estatísticas
$stats = $boletoService->obterEstatisticas(30);

// Boletos recentes
$boletosRecentes = $boletoService->listarBoletos(['limit' => 10]);

// Clientes ativos
$clientesAtivos = $db->fetch("SELECT COUNT(*) as total FROM clientes WHERE status = 'ativo'")['total'];

// Assinaturas ativas
$assinaturasAtivas = $db->fetch("SELECT COUNT(*) as total FROM assinaturas WHERE status = 'ativa'")['total'];

// Notificações dos últimos 7 dias
$notificacoesRecentes = $db->fetchAll("
    SELECT DATE(data_envio) as data, COUNT(*) as total
    FROM notificacoes 
    WHERE data_envio >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY DATE(data_envio)
    ORDER BY data DESC
    LIMIT 7
");

/**
 * Formatar valor monetário
 */
function formatarMoeda($valor) {
    return 'R$ ' . number_format($valor, 2, ',', '.');
}

/**
 * Formatar data
 */
function formatarData($data) {
    return date('d/m/Y', strtotime($data));
}

/**
 * Obter classe CSS do status
 */
function getStatusClass($status) {
    switch ($status) {
        case 'pago': return 'status-success';
        case 'pendente': return 'status-warning';
        case 'vencido': return 'status-danger';
        default: return 'status-secondary';
    }
}

/**
 * Obter texto do status
 */
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
    <title>Dashboard - Tray Sistemas</title>
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
            line-height: 1.6;
        }

        .admin-header {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            padding: 20px 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }

        .admin-header .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1400px;
            margin: 0 auto;
        }

        .admin-header h1 {
            font-size: 24px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .admin-nav {
            display: flex;
            gap: 30px;
            align-items: center;
        }

        .admin-nav a {
            color: white;
            text-decoration: none;
            font-weight: 500;
            padding: 10px 15px;
            border-radius: 8px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .admin-nav a:hover {
            background: rgba(255,255,255,0.2);
            transform: translateY(-2px);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
            background: rgba(255,255,255,0.1);
            padding: 10px 20px;
            border-radius: 12px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: white;
            padding: 30px;
            border-radius: 16px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--gradient-start), var(--gradient-end));
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0,0,0,0.12);
        }

        .stat-card.blue {
            --gradient-start: #3b82f6;
            --gradient-end: #1e40af;
        }

        .stat-card.green {
            --gradient-start: #10b981;
            --gradient-end: #059669;
        }

        .stat-card.yellow {
            --gradient-start: #f59e0b;
            --gradient-end: #d97706;
        }

        .stat-card.red {
            --gradient-start: #ef4444;
            --gradient-end: #dc2626;
        }

        .stat-card.purple {
            --gradient-start: #8b5cf6;
            --gradient-end: #7c3aed;
        }

        .stat-card.teal {
            --gradient-start: #14b8a6;
            --gradient-end: #0d9488;
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            background: linear-gradient(135deg, var(--gradient-start), var(--gradient-end));
        }

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
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .stat-trend {
            font-size: 12px;
            padding: 4px 8px;
            border-radius: 6px;
            font-weight: 600;
        }

        .trend-up {
            background: #dcfce7;
            color: #166534;
        }

        .trend-down {
            background: #fee2e2;
            color: #991b1b;
        }

        .recent-section {
            background: white;
            border-radius: 16px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-bottom: 30px;
        }

        .section-header {
            padding: 25px 30px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .section-header h2 {
            color: #1e293b;
            font-size: 20px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn {
            padding: 10px 20px;
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

        .table-responsive {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th,
        .table td {
            padding: 15px 20px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }

        .table th {
            background: #f8fafc;
            font-weight: 600;
            color: #475569;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .table td {
            color: #1e293b;
        }

        .table tbody tr:hover {
            background: #f8fafc;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
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

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }

        .action-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            text-align: center;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }

        .action-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            border-color: #3b82f6;
        }

        .action-card .icon {
            width: 50px;
            height: 50px;
            margin: 0 auto 15px;
            background: linear-gradient(135deg, #3b82f6, #1e40af);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
        }

        .action-card h3 {
            color: #1e293b;
            margin-bottom: 10px;
            font-size: 16px;
        }

        .action-card p {
            color: #64748b;
            font-size: 14px;
            margin-bottom: 20px;
        }

        @media (max-width: 768px) {
            .container {
                padding: 20px;
            }

            .admin-header .header-content {
                flex-direction: column;
                gap: 20px;
                text-align: center;
            }

            .admin-nav {
                flex-wrap: wrap;
                justify-content: center;
            }

            .dashboard-grid {
                grid-template-columns: 1fr;
            }

            .stat-card {
                padding: 20px;
            }

            .section-header {
                padding: 20px;
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
        }

        /* Animações */
        .stat-card {
            animation: fadeInUp 0.6s ease-out;
            animation-fill-mode: both;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>
<body>
    <div class="admin-header">
        <div class="header-content">
            <h1><i class="fas fa-chart-line"></i> Dashboard</h1>
            
            <div class="admin-nav">
                <a href="clientes.php"><i class="fas fa-users"></i> Clientes</a>
                <a href="boletos.php"><i class="fas fa-file-invoice"></i> Boletos</a>
                <a href="assinaturas.php"><i class="fas fa-sync"></i> Assinaturas</a>
                <a href="planos.php"><i class="fas fa-box"></i> Planos</a>
                <a href="configuracoes.php"><i class="fas fa-cog"></i> Config</a>
            </div>
            
            <div class="user-info">
                <div>
                    <div style="font-weight: 600;"><?= htmlspecialchars($_SESSION['admin_nome']) ?></div>
                    <div style="font-size: 12px; opacity: 0.8;"><?= ucfirst($_SESSION['admin_perfil']) ?></div>
                </div>
                <a href="logout.php" style="color: white; font-size: 18px;" title="Sair">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- Estatísticas principais -->
        <div class="dashboard-grid">
            <div class="stat-card blue" style="animation-delay: 0.1s">
                <div class="stat-header">
                    <div>
                        <div class="stat-value"><?= $stats['total_boletos'] ?></div>
                        <div class="stat-label">Total de Boletos</div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-file-invoice"></i></div>
                </div>
            </div>

            <div class="stat-card green" style="animation-delay: 0.2s">
                <div class="stat-header">
                    <div>
                        <div class="stat-value"><?= $stats['boletos_pagos'] ?></div>
                        <div class="stat-label">Boletos Pagos</div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                </div>
                <?php if ($stats['total_boletos'] > 0): ?>
                <div class="stat-trend trend-up">
                    <?= $stats['taxa_pagamento'] ?>% Taxa de Pagamento
                </div>
                <?php endif; ?>
            </div>

            <div class="stat-card yellow" style="animation-delay: 0.3s">
                <div class="stat-header">
                    <div>
                        <div class="stat-value"><?= $stats['boletos_pendentes'] ?></div>
                        <div class="stat-label">Pendentes</div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-clock"></i></div>
                </div>
            </div>

            <div class="stat-card red" style="animation-delay: 0.4s">
                <div class="stat-header">
                    <div>
                        <div class="stat-value"><?= $stats['boletos_vencidos'] ?></div>
                        <div class="stat-label">Vencidos</div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                </div>
            </div>

            <div class="stat-card purple" style="animation-delay: 0.5s">
                <div class="stat-header">
                    <div>
                        <div class="stat-value"><?= $clientesAtivos ?></div>
                        <div class="stat-label">Clientes Ativos</div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                </div>
            </div>

            <div class="stat-card teal" style="animation-delay: 0.6s">
                <div class="stat-header">
                    <div>
                        <div class="stat-value"><?= $assinaturasAtivas ?></div>
                        <div class="stat-label">Assinaturas Ativas</div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-sync"></i></div>
                </div>
            </div>
        </div>

        <!-- Valores -->
        <div class="dashboard-grid">
            <div class="stat-card blue">
                <div class="stat-header">
                    <div>
                        <div class="stat-value"><?= formatarMoeda($stats['valor_total']) ?></div>
                        <div class="stat-label">Valor Total</div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-dollar-sign"></i></div>
                </div>
            </div>

            <div class="stat-card green">
                <div class="stat-header">
                    <div>
                        <div class="stat-value"><?= formatarMoeda($stats['valor_recebido']) ?></div>
                        <div class="stat-label">Valor Recebido</div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-hand-holding-usd"></i></div>
                </div>
            </div>

            <div class="stat-card yellow">
                <div class="stat-header">
                    <div>
                        <div class="stat-value"><?= formatarMoeda($stats['valor_pendente']) ?></div>
                        <div class="stat-label">Valor Pendente</div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-hourglass-half"></i></div>
                </div>
            </div>
        </div>

        <!-- Boletos Recentes -->
        <div class="recent-section">
            <div class="section-header">
                <h2><i class="fas fa-file-invoice"></i> Boletos Recentes</h2>
                <a href="boletos.php" class="btn btn-primary">
                    <i class="fas fa-eye"></i> Ver Todos
                </a>
            </div>
            
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Cliente</th>
                            <th>Valor</th>
                            <th>Vencimento</th>
                            <th>Status</th>
                            <th>Plano</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($boletosRecentes)): ?>
                            <?php foreach ($boletosRecentes as $boleto): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 600;"><?= htmlspecialchars($boleto['cliente_nome']) ?></div>
                                    <div style="font-size: 12px; color: #64748b;"><?= htmlspecialchars($boleto['cliente_email']) ?></div>
                                </td>
                                <td>
                                    <div style="font-weight: 600; color: #1e293b;"><?= formatarMoeda($boleto['valor']) ?></div>
                                </td>
                                <td><?= formatarData($boleto['data_vencimento']) ?></td>
                                <td>
                                    <span class="status-badge <?= getStatusClass($boleto['status']) ?>">
                                        <?= getStatusTexto($boleto['status']) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($boleto['plano_nome'] ?: 'Avulso') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 40px; color: #64748b;">
                                    <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;"></i>
                                    <div>Nenhum boleto encontrado</div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Ações Rápidas -->
        <div class="recent-section">
            <div class="section-header">
                <h2><i class="fas fa-rocket"></i> Ações Rápidas</h2>
            </div>
            
            <div class="quick-actions">
                <div class="action-card">
                    <div class="icon"><i class="fas fa-plus"></i></div>
                    <h3>Novo Cliente</h3>
                    <p>Cadastrar um novo cliente no sistema</p>
                    <a href="clientes.php?action=new" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Cadastrar
                    </a>
                </div>
                
                <div class="action-card">
                    <div class="icon"><i class="fas fa-file-invoice"></i></div>
                    <h3>Boleto Avulso</h3>
                    <p>Gerar um boleto avulso para cliente</p>
                    <a href="boletos.php?action=new" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Gerar
                    </a>
                </div>
                
                <div class="action-card">
                    <div class="icon"><i class="fas fa-sync"></i></div>
                    <h3>Nova Assinatura</h3>
                    <p>Criar assinatura recorrente</p>
                    <a href="assinaturas.php?action=new" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Criar
                    </a>
                </div>
                
                <div class="action-card">
                    <div class="icon"><i class="fas fa-chart-bar"></i></div>
                    <h3>Relatórios</h3>
                    <p>Visualizar relatórios detalhados</p>
                    <a href="relatorios.php" class="btn btn-primary">
                        <i class="fas fa-chart-bar"></i> Ver
                    </a>
                </div>
                
                <div class="action-card">
                    <div class="icon"><i class="fas fa-cog"></i></div>
                    <h3>Executar Cron</h3>
                    <p>Executar tarefas automáticas manualmente</p>
                    <button onclick="executarCron()" class="btn btn-primary" id="btnCron">
                        <i class="fas fa-play"></i> Executar
                    </button>
                </div>
                
                <div class="action-card">
                    <div class="icon"><i class="fas fa-envelope"></i></div>
                    <h3>Teste Email</h3>
                    <p>Testar configurações de email</p>
                    <button onclick="testarEmail()" class="btn btn-primary" id="btnEmail">
                        <i class="fas fa-paper-plane"></i> Testar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Animação dos cards ao carregar
        window.addEventListener('load', function() {
            const cards = document.querySelectorAll('.stat-card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
            });
        });

        // Executar cron manualmente
        async function executarCron() {
            const btn = document.getElementById('btnCron');
            const originalText = btn.innerHTML;
            
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Executando...';
            btn.disabled = true;
            
            try {
                const response = await fetch('../cron/daily_tasks.php?manual=1');
                const result = await response.text();
                
                if (response.ok) {
                    alert('✅ Cron executado com sucesso!\n\nVerifique os logs para mais detalhes.');
                } else {
                    throw new Error('Erro na execução');
                }
            } catch (error) {
                alert('❌ Erro ao executar cron:\n' + error.message);
            } finally {
                btn.innerHTML = originalText;
                btn.disabled = false;
                
                // Recarregar página após 2 segundos
                setTimeout(() => {
                    location.reload();
                }, 2000);
            }
        }

        // Testar email
        async function testarEmail() {
            const btn = document.getElementById('btnEmail');
            const originalText = btn.innerHTML;
            
            const email = prompt('Digite o email para teste:', '<?= $_SESSION['admin_email'] ?>');
            if (!email) return;
            
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...';
            btn.disabled = true;
            
            try {
                const response = await fetch('ajax/test_email.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ email: email })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('✅ Email de teste enviado com sucesso!');
                } else {
                    alert('❌ Erro ao enviar email:\n' + result.error);
                }
            } catch (error) {
                alert('❌ Erro na requisição:\n' + error.message);
            } finally {
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        }

        // Atualizar dados a cada 5 minutos
        setInterval(function() {
            location.reload();
        }, 300000);

        // Adicionar efeitos visuais nos cards
        document.querySelectorAll('.stat-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-8px) scale(1.02)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0) scale(1)';
            });
        });

        // Tooltip para valores
        document.querySelectorAll('.stat-value').forEach(element => {
            element.title = 'Últimos 30 dias';
        });
    </script>
</body>
</html>