<?php
session_start();
require_once '../config/database.php';

// Verificar se está logado
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$db = new Database();
$sucesso = '';
$erro = '';

// Processar salvamento
if ($_POST) {
    try {
        $configs = [
            'mailtrap_token' => $_POST['mailtrap_token'] ?? '',
            'email_remetente' => $_POST['email_remetente'] ?? '',
            'nome_remetente' => $_POST['nome_remetente'] ?? '',
            'dias_lembrete' => $_POST['dias_lembrete'] ?? '',
            'dias_atraso' => $_POST['dias_atraso'] ?? '',
            'whatsapp_numero' => $_POST['whatsapp_numero'] ?? '',
            'empresa_nome' => $_POST['empresa_nome'] ?? '',
            'empresa_descricao' => $_POST['empresa_descricao'] ?? ''
        ];

        foreach ($configs as $chave => $valor) {
            Config::set($chave, $valor);
        }

        $sucesso = 'Configurações salvas com sucesso!';
        Logger::success('Configuracoes', 'Configurações atualizadas', ['usuario' => $_SESSION['admin_nome']]);

    } catch (Exception $e) {
        $erro = 'Erro ao salvar: ' . $e->getMessage();
        Logger::error('Configuracoes', 'Erro ao salvar configurações', ['error' => $e->getMessage()]);
    }
}

// Buscar configurações atuais
$configuracoes = Config::getAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurações - Tray Sistemas</title>
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
            max-width: 1000px;
            margin: 0 auto;
            padding: 30px;
        }

        .page-header {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            margin-bottom: 30px;
            text-align: center;
        }

        .page-title {
            font-size: 28px;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 12px;
            justify-content: center;
        }

        .config-section {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            margin-bottom: 25px;
        }

        .section-title {
            font-size: 20px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e5e7eb;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
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

        .form-help {
            font-size: 12px;
            color: #6b7280;
            margin-top: 5px;
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

        .btn-success {
            background: #10b981;
            color: white;
        }

        .btn-secondary {
            background: #6b7280;
            color: white;
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

        .actions {
            text-align: center;
            padding-top: 30px;
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
    </style>
</head>
<body>
    <div class="admin-header">
        <div class="header-content">
            <div class="logo-section">
                <img src="https://rricaria.sirv.com/traysistemas/traysistemas.webp" alt="Tray Sistemas" class="logo-img">
                <h1><i class="fas fa-cog"></i> Configurações</h1>
            </div>
            
            <div class="admin-nav">
                <a href="dashboard.php"><i class="fas fa-chart-line"></i> Dashboard</a>
                <a href="clientes.php"><i class="fas fa-users"></i> Clientes</a>
                <a href="boletos.php"><i class="fas fa-file-invoice"></i> Boletos</a>
                <a href="configuracoes.php" class="active"><i class="fas fa-cog"></i> Config</a>
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

        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-cog"></i>
                Configurações do Sistema
            </h1>
        </div>

        <form method="POST">
            <!-- Configurações de Email -->
            <div class="config-section">
                <h3 class="section-title">
                    <i class="fas fa-envelope"></i> Configurações de Email
                </h3>
                
                <div class="info-card">
                    <h4><i class="fas fa-info-circle"></i> Mailtrap</h4>
                    <p>Token atualizado da API do Mailtrap para envio de notificações por email.</p>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Token Mailtrap</label>
                        <input 
                            type="text" 
                            name="mailtrap_token" 
                            class="form-control" 
                            value="<?= htmlspecialchars($configuracoes['mailtrap_token'] ?? '') ?>"
                            required
                        >
                        <div class="form-help">Token da API Mailtrap Transactional Stream</div>
                    </div>

                    <div class="form-group">
                        <label>Email Remetente</label>
                        <input 
                            type="email" 
                            name="email_remetente" 
                            class="form-control" 
                            value="<?= htmlspecialchars($configuracoes['email_remetente'] ?? '') ?>"
                            required
                        >
                        <div class="form-help">Email verificado no Mailtrap</div>
                    </div>

                    <div class="form-group">
                        <label>Nome do Remetente</label>
                        <input 
                            type="text" 
                            name="nome_remetente" 
                            class="form-control" 
                            value="<?= htmlspecialchars($configuracoes['nome_remetente'] ?? '') ?>"
                            required
                        >
                    </div>
                </div>
            </div>

            <!-- Configurações de Notificações -->
            <div class="config-section">
                <h3 class="section-title">
                    <i class="fas fa-bell"></i> Notificações Automáticas
                </h3>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Dias para Lembrete</label>
                        <input 
                            type="text" 
                            name="dias_lembrete" 
                            class="form-control" 
                            value="<?= htmlspecialchars($configuracoes['dias_lembrete'] ?? '') ?>"
                            placeholder="7,3,1"
                            required
                        >
                        <div class="form-help">Dias antes do vencimento (separados por vírgula)</div>
                    </div>

                    <div class="form-group">
                        <label>Dias para Cobrança</label>
                        <input 
                            type="text" 
                            name="dias_atraso" 
                            class="form-control" 
                            value="<?= htmlspecialchars($configuracoes['dias_atraso'] ?? '') ?>"
                            placeholder="5,10,15,30"
                            required
                        >
                        <div class="form-help">Dias após vencimento (separados por vírgula)</div>
                    </div>
                </div>
            </div>

            <!-- Configurações da Empresa -->
            <div class="config-section">
                <h3 class="section-title">
                    <i class="fas fa-building"></i> Informações da Empresa
                </h3>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Nome da Empresa</label>
                        <input 
                            type="text" 
                            name="empresa_nome" 
                            class="form-control" 
                            value="<?= htmlspecialchars($configuracoes['empresa_nome'] ?? '') ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label>Descrição da Empresa</label>
                        <input 
                            type="text" 
                            name="empresa_descricao" 
                            class="form-control" 
                            value="<?= htmlspecialchars($configuracoes['empresa_descricao'] ?? '') ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label>WhatsApp</label>
                        <input 
                            type="text" 
                            name="whatsapp_numero" 
                            class="form-control" 
                            value="<?= htmlspecialchars($configuracoes['whatsapp_numero'] ?? '') ?>"
                            placeholder="554531323952"
                            required
                        >
                        <div class="form-help">Número completo com código do país e área</div>
                    </div>
                </div>
            </div>

            <!-- Informações da API Cora -->
            <div class="config-section">
                <h3 class="section-title">
                    <i class="fas fa-credit-card"></i> API Cora (Somente Leitura)
                </h3>
                
                <div class="info-card">
                    <h4><i class="fas fa-shield-alt"></i> Configurações de Segurança</h4>
                    <p>
                        <strong>Client ID:</strong> <?= htmlspecialchars($configuracoes['cora_client_id'] ?? 'Não configurado') ?><br>
                        <strong>Certificados:</strong> certificate.pem e private-key.key<br>
                        <strong>Status:</strong> Configurado via arquivos físicos
                    </p>
                </div>
            </div>

            <div class="actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Salvar Configurações
                </button>
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Voltar ao Dashboard
                </a>
            </div>
        </form>
    </div>

    <script>
        // Validação de formato para WhatsApp
        document.querySelector('input[name="whatsapp_numero"]').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            e.target.value = value;
        });

        // Feedback visual no salvamento
        document.querySelector('form').addEventListener('submit', function(e) {
            const btn = document.querySelector('button[type="submit"]');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
            btn.disabled = true;
        });
    </script>
</body>
</html>