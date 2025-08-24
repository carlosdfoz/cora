<?php
session_start();
require_once 'config/database.php';

$erro = '';
$boletos = [];

if ($_POST) {
    $documento = preg_replace('/[^0-9]/', '', trim($_POST['documento'] ?? ''));
    
    if (empty($documento)) {
        $erro = 'Preencha o CPF ou CNPJ';
    } elseif (strlen($documento) != 11 && strlen($documento) != 14) {
        $erro = 'CPF deve ter 11 dígitos ou CNPJ deve ter 14 dígitos';
    } else {
        try {
            $db = new Database();
            
            // Buscar cliente
            $cliente = $db->fetch(
                "SELECT * FROM clientes WHERE cpf_cnpj = ? AND status = 'ativo'", 
                [$documento]
            );
            
            if ($cliente) {
                // Buscar boletos do cliente
                $boletos = $db->fetchAll("
                    SELECT b.*, p.nome as plano_nome
                    FROM boletos b
                    LEFT JOIN planos p ON b.plano_id = p.id
                    WHERE b.cliente_id = ? 
                    AND b.status IN ('pendente', 'vencido', 'pago')
                    ORDER BY b.data_vencimento DESC
                    LIMIT 20
                ", [$cliente['id']]);
                
                $_SESSION['cliente_id'] = $cliente['id'];
                $_SESSION['cliente_nome'] = $cliente['nome'];
                
                Logger::info('ClienteLogin', 'Cliente acessou área de boletos', [
                    'cliente_id' => $cliente['id'],
                    'documento' => $documento
                ]);
            } else {
                $erro = 'CPF/CNPJ não encontrado ou inativo';
                Logger::warning('ClienteLogin', 'Tentativa de acesso com documento inválido', [
                    'documento' => $documento
                ]);
            }
        } catch (Exception $e) {
            $erro = 'Erro interno. Tente novamente.';
            Logger::error('ClienteLogin', 'Erro no acesso do cliente', ['error' => $e->getMessage()]);
        }
    }
}

/**
 * Formatar documento
 */
function formatarDocumento($documento) {
    $documento = preg_replace('/[^0-9]/', '', $documento);
    if (strlen($documento) == 11) {
        return substr($documento, 0, 3) . '.***.**' . substr($documento, -2);
    } elseif (strlen($documento) == 14) {
        return substr($documento, 0, 2) . '.***.***/****-' . substr($documento, -2);
    }
    return $documento;
}

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
        case 'pago': return 'status-pago';
        case 'pendente': return 'status-pendente';
        case 'vencido': return 'status-vencido';
        default: return 'status-cancelado';
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
    <title>Área do Cliente - Tray Sistemas</title>
    <link href="https://fonts.googleapis.com/css2?family=Segoe+UI:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            background: white;
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            text-align: center;
        }

        .header h1 {
            color: #1e3a8a;
            font-size: 32px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 5px;
        }

        .header p {
            color: #64748b;
            font-size: 16px;
            font-weight: 500;
        }

        .login-section {
            background: white;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }

        .login-section h2 {
            color: #1e293b;
            margin-bottom: 20px;
            font-size: 24px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            color: #374151;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .form-group input {
            width: 100%;
            max-width: 400px;
            padding: 15px 20px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-size: 16px;
            transition: all 0.3s ease;
        }

        .form-group input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .btn {
            background: linear-gradient(135deg, #3b82f6 0%, #1e40af 100%);
            color: white;
            padding: 15px 30px;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(59, 130, 246, 0.3);
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
            padding: 15px;
            border-radius: 12px;
            border: 1px solid #fecaca;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .error::before {
            content: '⚠️';
        }

        .boletos-section {
            background: white;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
        }

        .client-info {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border: 2px solid #0ea5e9;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
        }

        .client-info h3 {
            color: #0369a1;
            margin-bottom: 10px;
        }

        .boletos-grid {
            display: grid;
            gap: 20px;
        }

        .boleto-card {
            border: 2px solid #e5e7eb;
            border-radius: 15px;
            padding: 25px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .boleto-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #3b82f6, #1e40af);
        }

        .boleto-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        }

        .boleto-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .boleto-info {
            flex: 1;
        }

        .boleto-info h4 {
            color: #1e293b;
            font-size: 18px;
            margin-bottom: 5px;
        }

        .boleto-info .plano {
            color: #64748b;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .valor-destaque {
            font-size: 24px;
            font-weight: 700;
            color: #1e293b;
        }

        .boleto-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .detail-item {
            text-align: center;
            padding: 15px;
            background: #f8fafc;
            border-radius: 10px;
        }

        .detail-label {
            font-size: 12px;
            color: #64748b;
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .detail-value {
            font-size: 14px;
            color: #1e293b;
            font-weight: 600;
        }

        .status {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .status-pago {
            background: #dcfce7;
            color: #166534;
        }

        .status-pendente {
            background: #fef3c7;
            color: #92400e;
        }

        .status-vencido {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-cancelado {
            background: #f3f4f6;
            color: #6b7280;
        }

        .boleto-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn-secondary {
            background: #6b7280;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
        }

        .btn-secondary:hover {
            background: #4b5563;
            transform: translateY(-2px);
        }

        .btn-whatsapp {
            background: #25d366;
            color: white;
            padding: 15px 25px;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
            margin-top: 20px;
        }

        .btn-whatsapp:hover {
            background: #20ba5a;
            transform: translateY(-2px);
        }

        .no-boletos {
            text-align: center;
            padding: 60px 20px;
            color: #64748b;
        }

        .no-boletos .emoji {
            font-size: 64px;
            margin-bottom: 20px;
        }

        .logout {
            text-align: center;
            margin-top: 30px;
        }

        .logout a {
            color: #64748b;
            text-decoration: none;
            font-size: 14px;
        }

        .logout a:hover {
            color: #1e293b;
        }

        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            
            .header, .login-section, .boletos-section {
                padding: 25px 20px;
            }
            
            .header h1 {
                font-size: 24px;
            }
            
            .boleto-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .boleto-details {
                grid-template-columns: 1fr;
            }
        }

        /* Animações */
        .boletos-section {
            animation: slideUp 0.6s ease-out;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .boleto-card {
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
    <div class="container">
        <div class="header">
            <h1>Tray Sistemas</h1>
            <p>Área do Cliente - Consulte seus boletos</p>
        </div>

        <?php if (empty($boletos) && !$erro): ?>
        <div class="login-section">
            <h2>🔐 Acesse sua conta</h2>
            <p style="margin-bottom: 25px; color: #64748b;">Digite seu CPF ou CNPJ para visualizar seus boletos:</p>

            <?php if ($erro): ?>
                <div class="error"><?= htmlspecialchars($erro) ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label for="documento">CPF ou CNPJ</label>
                    <input 
                        type="text" 
                        id="documento" 
                        name="documento" 
                        placeholder="Digite apenas os números"
                        maxlength="18"
                        required
                    >
                </div>

                <button type="submit" class="btn">🔍 Consultar Boletos</button>
            </form>

            <a href="https://wa.me/554531323952" class="btn-whatsapp" target="_blank">
                📱 Fale conosco no WhatsApp
            </a>
        </div>
        <?php endif; ?>

        <?php if ($erro && empty($boletos)): ?>
        <div class="login-section">
            <div class="error"><?= htmlspecialchars($erro) ?></div>
            
            <form method="POST">
                <div class="form-group">
                    <label for="documento">CPF ou CNPJ</label>
                    <input 
                        type="text" 
                        id="documento" 
                        name="documento" 
                        placeholder="Digite apenas os números"
                        maxlength="18"
                        required
                    >
                </div>

                <button type="submit" class="btn">🔍 Consultar Boletos</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if (!empty($boletos)): ?>
        <div class="boletos-section">
            <div class="client-info">
                <h3>👋 Olá, <?= htmlspecialchars($_SESSION['cliente_nome']) ?>!</h3>
                <p>Documento: <?= formatarDocumento($_POST['documento']) ?></p>
            </div>

            <h2 style="margin-bottom: 30px; color: #1e293b;">📄 Seus Boletos</h2>

            <div class="boletos-grid">
                <?php foreach ($boletos as $index => $boleto): ?>
                <div class="boleto-card" style="animation-delay: <?= $index * 0.1 ?>s">
                    <div class="boleto-header">
                        <div class="boleto-info">
                            <h4><?= htmlspecialchars($boleto['descricao']) ?></h4>
                            <?php if ($boleto['plano_nome']): ?>
                                <div class="plano">📋 <?= htmlspecialchars($boleto['plano_nome']) ?></div>
                            <?php endif; ?>
                            <div class="valor-destaque"><?= formatarMoeda($boleto['valor']) ?></div>
                        </div>
                        <div class="status <?= getStatusClass($boleto['status']) ?>">
                            <?= getStatusTexto($boleto['status']) ?>
                        </div>
                    </div>

                    <div class="boleto-details">
                        <div class="detail-item">
                            <div class="detail-label">Data Vencimento</div>
                            <div class="detail-value"><?= formatarData($boleto['data_vencimento']) ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Data Emissão</div>
                            <div class="detail-value"><?= formatarData($boleto['data_emissao']) ?></div>
                        </div>
                        <?php if ($boleto['data_pagamento']): ?>
                        <div class="detail-item">
                            <div class="detail-label">Data Pagamento</div>
                            <div class="detail-value"><?= formatarData($boleto['data_pagamento']) ?></div>
                        </div>
                        <?php endif; ?>
                        <div class="detail-item">
                            <div class="detail-label">Nosso Número</div>
                            <div class="detail-value"><?= htmlspecialchars($boleto['nosso_numero'] ?? 'N/A') ?></div>
                        </div>
                    </div>

                    <?php if ($boleto['status'] != 'pago' && $boleto['status'] != 'cancelado'): ?>
                    <div class="boleto-actions">
                        <?php if ($boleto['url_boleto']): ?>
                            <a href="<?= htmlspecialchars($boleto['url_boleto']) ?>" target="_blank" class="btn-secondary">
                                📄 Ver Boleto PDF
                            </a>
                        <?php endif; ?>
                        <?php if ($boleto['linha_digitavel']): ?>
                            <button onclick="copiarCodigo('<?= $boleto['linha_digitavel'] ?>')" class="btn-secondary">
                                📋 Copiar Código
                            </button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="logout">
                <a href="?logout=1">🚪 Consultar outro documento</a>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        // Máscara para CPF/CNPJ
        document.getElementById('documento')?.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            e.target.value = value;
        });

        // Função para copiar código
        function copiarCodigo(codigo) {
            navigator.clipboard.writeText(codigo).then(function() {
                alert('✅ Código copiado para a área de transferência!');
            }).catch(function(err) {
                console.error('Erro ao copiar código: ', err);
                prompt('📋 Copie o código abaixo:', codigo);
            });
        }

        // Animação dos cards ao carregar
        window.addEventListener('load', function() {
            const cards = document.querySelectorAll('.boleto-card');
            cards.forEach((card, index) => {
                setTimeout(() => {
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });
        });
    </script>

    <?php if (isset($_GET['logout'])): ?>
    <script>
        // Limpar sessão e recarregar página
        <?php 
        session_destroy(); 
        header('Location: index.php');
        exit;
        ?>
    </script>
    <?php endif; ?>
</body>
</html>