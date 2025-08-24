<?php
session_start();
require_once '../../config/database.php';
require_once '../../services/EmailService.php';

// Verificar se está logado
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

// Verificar se é POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

// Obter dados JSON
$input = json_decode(file_get_contents('php://input'), true);
$email = $input['email'] ?? '';

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'Email inválido']);
    exit;
}

try {
    $emailService = new EmailService();
    
    // HTML do email de teste
    $conteudoHtml = '
    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
        <div style="background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
            <img src="https://rricaria.sirv.com/traysistemas/traysistemas.webp" alt="Tray Sistemas" style="max-height: 60px; margin-bottom: 10px;">
            <h1 style="color: white; margin: 0; font-size: 24px;">Tray Sistemas</h1>
            <p style="color: rgba(255,255,255,0.9); margin: 5px 0 0 0;">Sistemas de Segurança Eletrônica</p>
        </div>
        
        <div style="background: white; padding: 40px; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 10px 10px;">
            <h2 style="color: #1e3a8a; margin-top: 0;">🧪 Email de Teste</h2>
            <p>Este é um email de teste do sistema de boletos da <strong>Tray Sistemas</strong>.</p>
            
            <div style="background: #f0f9ff; padding: 20px; border-radius: 8px; border-left: 4px solid #3b82f6; margin: 20px 0;">
                <p style="margin: 0; color: #1e40af;"><strong>✅ Configurações funcionando corretamente!</strong></p>
                <p style="margin: 5px 0 0 0; color: #64748b; font-size: 14px;">
                    Data/Hora: ' . date('d/m/Y H:i:s') . '<br>
                    Sistema: Gestão de Boletos v1.0<br>
                    Servidor: ' . $_SERVER['SERVER_NAME'] . '
                </p>
            </div>
            
            <p>Se você recebeu este email, significa que:</p>
            <ul>
                <li>✅ Integração com Mailtrap está funcionando</li>
                <li>✅ Configurações de email estão corretas</li>
                <li>✅ Sistema pronto para envio de notificações</li>
            </ul>
            
            <p>Em caso de dúvidas, entre em contato pelo WhatsApp: <a href="https://wa.me/554531323952" style="color: #25d366; text-decoration: none;">(45) 3132-3952</a></p>
        </div>
        
        <div style="text-align: center; padding: 20px; color: #6b7280; font-size: 12px;">
            <p>Este é um email automático de teste - Tray Sistemas</p>
        </div>
    </div>';
    
    $resultado = $emailService->enviarEmail(
        $email,
        '🧪 Teste - Sistema de Boletos Tray Sistemas',
        $conteudoHtml
    );
    
    if ($resultado['success']) {
        // Registrar no log
        Logger::success('TestEmail', 'Email de teste enviado com sucesso', [
            'destinatario' => $email,
            'usuario_admin' => $_SESSION['admin_nome']
        ]);
    }
    
    echo json_encode($resultado);
    
} catch (Exception $e) {
    Logger::error('TestEmail', 'Erro no teste de email', [
        'error' => $e->getMessage(),
        'destinatario' => $email
    ]);
    
    echo json_encode([
        'success' => false,
        'error' => 'Erro interno: ' . $e->getMessage()
    ]);
}
?>