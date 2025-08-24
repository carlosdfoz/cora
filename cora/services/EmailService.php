<?php
/**
 * Serviço de Email com Mailtrap
 * Sistema de Gestão de Boletos - Tray Sistemas
 */

require_once __DIR__ . '/../config/database.php';

class EmailService {
    private $api_token;
    private $api_endpoint;
    private $from_email;
    private $from_name;
    
    public function __construct() {
        $this->api_token = Config::get('mailtrap_token', '562c20cd3decd47e8b6979ed98621481');
        $this->api_endpoint = 'https://send.api.mailtrap.io/api/send';
        $this->from_email = Config::get('email_remetente', 'contato@traysistemas.com');
        $this->from_name = Config::get('nome_remetente', 'Carlos Alves');
    }

    /**
     * Envia email usando Mailtrap
     */
    public function enviarEmail($destinatario, $assunto, $conteudoHtml, $conteudoTexto = null) {
        try {
            $payload = [
                'from' => [
                    'email' => $this->from_email,
                    'name' => $this->from_name
                ],
                'to' => [
                    [
                        'email' => $destinatario
                    ]
                ],
                'subject' => $assunto,
                'html' => $conteudoHtml,
                'text' => $conteudoTexto ?? strip_tags($conteudoHtml)
            ];

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $this->api_endpoint,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->api_token
                ],
                CURLOPT_TIMEOUT => 30
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if (curl_error($ch)) {
                throw new Exception('Erro cURL: ' . curl_error($ch));
            }
            
            curl_close($ch);

            if ($http_code !== 200) {
                throw new Exception("Erro no envio: HTTP {$http_code} - {$response}");
            }

            Logger::success('EmailService', 'Email enviado com sucesso', [
                'destinatario' => $destinatario,
                'assunto' => $assunto
            ]);

            return [
                'success' => true,
                'message' => 'Email enviado com sucesso'
            ];
            
        } catch (Exception $e) {
            Logger::error('EmailService', 'Erro ao enviar email', [
                'error' => $e->getMessage(),
                'destinatario' => $destinatario
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Envia lembrete de vencimento
     */
    public function enviarLembrete($dadosBoleto, $diasRestantes) {
        $template = $this->carregarTemplate('lembrete');
        
        $variaveis = [
            '[Tipo de Lembrete]' => "Lembrete de Vencimento - {$diasRestantes} " . ($diasRestantes == 1 ? 'dia' : 'dias') . " restante" . ($diasRestantes == 1 ? '' : 's'),
            '[Nome do Cliente]' => $dadosBoleto['cliente_nome'],
            '[Data de Vencimento do Boleto]' => date('d/m/Y', strtotime($dadosBoleto['data_vencimento'])),
            '[Valor do Boleto]' => 'R$ ' . number_format($dadosBoleto['valor'], 2, ',', '.'),
            '[MAIN_CONTENT_BLOCK]' => $this->gerarConteudoPrincipal('lembrete', $dadosBoleto, $diasRestantes),
            '[ATTENTION_BLOCK]' => $this->gerarBlocoAtencao('lembrete')
        ];

        $conteudoHtml = str_replace(array_keys($variaveis), array_values($variaveis), $template);
        $assunto = "Lembrete: Boleto vence em {$diasRestantes} " . ($diasRestantes == 1 ? 'dia' : 'dias') . " - Tray Sistemas";

        return $this->enviarEmail($dadosBoleto['cliente_email'], $assunto, $conteudoHtml);
    }

    /**
     * Envia notificação de vencimento
     */
    public function enviarVencimento($dadosBoleto) {
        $template = $this->carregarTemplate('vencimento');
        
        $variaveis = [
            '[Tipo de Lembrete]' => 'Boleto Vence Hoje',
            '[Nome do Cliente]' => $dadosBoleto['cliente_nome'],
            '[Data de Vencimento do Boleto]' => date('d/m/Y', strtotime($dadosBoleto['data_vencimento'])),
            '[Valor do Boleto]' => 'R$ ' . number_format($dadosBoleto['valor'], 2, ',', '.'),
            '[MAIN_CONTENT_BLOCK]' => $this->gerarConteudoPrincipal('vencimento', $dadosBoleto),
            '[ATTENTION_BLOCK]' => $this->gerarBlocoAtencao('vencimento')
        ];

        $conteudoHtml = str_replace(array_keys($variaveis), array_values($variaveis), $template);
        $assunto = "URGENTE: Boleto vence hoje - Tray Sistemas";

        return $this->enviarEmail($dadosBoleto['cliente_email'], $assunto, $conteudoHtml);
    }

    /**
     * Envia cobrança por atraso
     */
    public function enviarCobranca($dadosBoleto, $diasAtraso) {
        $template = $this->carregarTemplate('cobranca');
        
        // Calcular valor com juros e multa
        $valorOriginal = $dadosBoleto['valor'];
        $multa = $valorOriginal * ($dadosBoleto['multa'] / 100);
        $juros = $valorOriginal * ($dadosBoleto['juros_dia'] / 100) * $diasAtraso;
        $valorAtualizado = $valorOriginal + $multa + $juros;
        
        $variaveis = [
            '[Tipo de Lembrete]' => "Cobrança - {$diasAtraso} " . ($diasAtraso == 1 ? 'dia' : 'dias') . " em atraso",
            '[Nome do Cliente]' => $dadosBoleto['cliente_nome'],
            '[Data de Vencimento do Boleto]' => date('d/m/Y', strtotime($dadosBoleto['data_vencimento'])),
            '[Valor do Boleto]' => 'R$ ' . number_format($valorAtualizado, 2, ',', '.') . ' (original: R$ ' . number_format($valorOriginal, 2, ',', '.') . ')',
            '[MAIN_CONTENT_BLOCK]' => $this->gerarConteudoPrincipal('cobranca', $dadosBoleto, $diasAtraso, $valorAtualizado),
            '[ATTENTION_BLOCK]' => $this->gerarBlocoAtencao('cobranca')
        ];

        $conteudoHtml = str_replace(array_keys($variaveis), array_values($variaveis), $template);
        $assunto = "COBRANÇA: Boleto em atraso há {$diasAtraso} " . ($diasAtraso == 1 ? 'dia' : 'dias') . " - Tray Sistemas";

        return $this->enviarEmail($dadosBoleto['cliente_email'], $assunto, $conteudoHtml);
    }

    /**
     * Envia confirmação de pagamento
     */
    public function enviarConfirmacaoPagamento($dadosBoleto) {
        $template = $this->carregarTemplate('pagamento');
        
        $variaveis = [
            '[Tipo de Lembrete]' => 'Pagamento Confirmado',
            '[Nome do Cliente]' => $dadosBoleto['cliente_nome'],
            '[Data de Vencimento do Boleto]' => date('d/m/Y', strtotime($dadosBoleto['data_vencimento'])),
            '[Valor do Boleto]' => 'R$ ' . number_format($dadosBoleto['valor'], 2, ',', '.'),
            '[MAIN_CONTENT_BLOCK]' => $this->gerarConteudoPrincipal('pagamento', $dadosBoleto),
            '[ATTENTION_BLOCK]' => $this->gerarBlocoAtencao('pagamento')
        ];

        $conteudoHtml = str_replace(array_keys($variaveis), array_values($variaveis), $template);
        $assunto = "Pagamento Confirmado - Obrigado! - Tray Sistemas";

        return $this->enviarEmail($dadosBoleto['cliente_email'], $assunto, $conteudoHtml);
    }

    /**
     * Carrega template de email
     */
    private function carregarTemplate($tipo) {
        $templatePath = __DIR__ . '/../templates/email_base.html';
        
        if (file_exists($templatePath)) {
            return file_get_contents($templatePath);
        }
        
        // Template inline caso arquivo não exista
        return $this->getTemplateInline();
    }

    /**
     * Gera conteúdo principal baseado no tipo
     */
    private function gerarConteudoPrincipal($tipo, $dadosBoleto, $diasRestantesOuAtraso = 0, $valorAtualizado = null) {
        switch ($tipo) {
            case 'lembrete':
                return '
                <div style="background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%); border: 2px solid #0ea5e9; border-radius: 12px; padding: 25px; margin: 20px 0; text-align: center;">
                    <div style="font-size: 48px; margin-bottom: 15px;">📅</div>
                    <h2 style="color: #0369a1; font-size: 24px; margin: 0 0 15px 0; font-weight: 600;">Seu boleto vence em breve!</h2>
                    <p style="color: #0c4a6e; font-size: 16px; margin: 0 0 20px 0;">Não esqueça de efetuar o pagamento até a data de vencimento para evitar juros e multa.</p>
                    <div style="background-color: #ffffff; border-radius: 8px; padding: 15px; margin: 15px 0;">
                        <p style="color: #1e293b; font-size: 14px; margin: 0;"><strong>Forma de pagamento:</strong> PIX, débito, cartão ou pelo código de barras</p>
                    </div>
                </div>';
                
            case 'vencimento':
                return '
                <div style="background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border: 2px solid #f59e0b; border-radius: 12px; padding: 25px; margin: 20px 0; text-align: center;">
                    <div style="font-size: 48px; margin-bottom: 15px;">⚠️</div>
                    <h2 style="color: #92400e; font-size: 24px; margin: 0 0 15px 0; font-weight: 600;">Boleto vence hoje!</h2>
                    <p style="color: #78350f; font-size: 16px; margin: 0 0 20px 0;">Efetue o pagamento hoje mesmo para evitar juros e multa por atraso.</p>
                    <div style="background-color: #ffffff; border-radius: 8px; padding: 15px; margin: 15px 0;">
                        <p style="color: #1e293b; font-size: 14px; margin: 0;"><strong>Pagamento até 23:59h de hoje</strong></p>
                    </div>
                </div>';
                
            case 'cobranca':
                $valorOriginal = $dadosBoleto['valor'];
                $multa = $valorOriginal * ($dadosBoleto['multa'] / 100);
                $juros = $valorOriginal * ($dadosBoleto['juros_dia'] / 100) * $diasRestantesOuAtraso;
                
                return '
                <div style="background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); border: 2px solid #ef4444; border-radius: 12px; padding: 25px; margin: 20px 0; text-align: center;">
                    <div style="font-size: 48px; margin-bottom: 15px;">🚨</div>
                    <h2 style="color: #991b1b; font-size: 24px; margin: 0 0 15px 0; font-weight: 600;">Boleto em atraso!</h2>
                    <p style="color: #7f1d1d; font-size: 16px; margin: 0 0 20px 0;">Seu boleto está em atraso há ' . $diasRestantesOuAtraso . ' ' . ($diasRestantesOuAtraso == 1 ? 'dia' : 'dias') . '. Regularize sua situação o quanto antes.</p>
                    <div style="background-color: #ffffff; border-radius: 8px; padding: 15px; margin: 15px 0;">
                        <p style="color: #1e293b; font-size: 14px; margin: 0 0 10px 0;"><strong>Valor original:</strong> R$ ' . number_format($valorOriginal, 2, ',', '.') . '</p>
                        <p style="color: #1e293b; font-size: 14px; margin: 0 0 10px 0;"><strong>Multa (' . $dadosBoleto['multa'] . '%):</strong> R$ ' . number_format($multa, 2, ',', '.') . '</p>
                        <p style="color: #1e293b; font-size: 14px; margin: 0 0 10px 0;"><strong>Juros (' . $diasRestantesOuAtraso . ' dias):</strong> R$ ' . number_format($juros, 2, ',', '.') . '</p>
                        <hr style="margin: 10px 0; border: 1px solid #e2e8f0;">
                        <p style="color: #991b1b; font-size: 16px; margin: 0; font-weight: 600;"><strong>Valor atualizado:</strong> R$ ' . number_format($valorAtualizado, 2, ',', '.') . '</p>
                    </div>
                </div>';
                
            case 'pagamento':
                return '
                <div style="background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%); border: 2px solid #22c55e; border-radius: 12px; padding: 25px; margin: 20px 0; text-align: center;">
                    <div style="font-size: 48px; margin-bottom: 15px;">✅</div>
                    <h2 style="color: #15803d; font-size: 24px; margin: 0 0 15px 0; font-weight: 600;">Pagamento Confirmado!</h2>
                    <p style="color: #166534; font-size: 16px; margin: 0 0 20px 0;">Obrigado! Seu pagamento foi confirmado e processado com sucesso.</p>
                    <div style="background-color: #ffffff; border-radius: 8px; padding: 15px; margin: 15px 0;">
                        <p style="color: #1e293b; font-size: 14px; margin: 0;"><strong>Data do pagamento:</strong> ' . date('d/m/Y H:i') . '</p>
                    </div>
                </div>';
                
            default:
                return '';
        }
    }

    /**
     * Gera bloco de atenção baseado no tipo
     */
    private function gerarBlocoAtencao($tipo) {
        switch ($tipo) {
            case 'lembrete':
                return '
                <div style="background-color: #f1f5f9; border-left: 4px solid #3b82f6; padding: 20px; margin: 20px 0; border-radius: 0 8px 8px 0;">
                    <p style="color: #475569; font-size: 14px; margin: 0; line-height: 1.6;">
                        <strong>💡 Dica:</strong> Você pode pagar via PIX para maior agilidade ou usar o código de barras em qualquer banco. 
                        Em caso de dúvidas, entre em contato conosco pelo WhatsApp.
                    </p>
                </div>';
                
            case 'vencimento':
                return '
                <div style="background-color: #fffbeb; border-left: 4px solid #f59e0b; padding: 20px; margin: 20px 0; border-radius: 0 8px 8px 0;">
                    <p style="color: #78350f; font-size: 14px; margin: 0; line-height: 1.6;">
                        <strong>⏰ Atenção:</strong> A partir de amanhã, será cobrada multa de 2% + juros de 1% ao mês. 
                        Evite custos extras pagando hoje mesmo!
                    </p>
                </div>';
                
            case 'cobranca':
                return '
                <div style="background-color: #fef2f2; border-left: 4px solid #ef4444; padding: 20px; margin: 20px 0; border-radius: 0 8px 8px 0;">
                    <p style="color: #7f1d1d; font-size: 14px; margin: 0; line-height: 1.6;">
                        <strong>🚨 Importante:</strong> Para evitar a suspensão dos serviços, regularize sua situação o quanto antes. 
                        Entre em contato se precisar negociar condições de pagamento.
                    </p>
                </div>';
                
            case 'pagamento':
                return '
                <div style="background-color: #f0fdf4; border-left: 4px solid #22c55e; padding: 20px; margin: 20px 0; border-radius: 0 8px 8px 0;">
                    <p style="color: #166534; font-size: 14px; margin: 0; line-height: 1.6;">
                        <strong>🎉 Obrigado!</strong> Seu pagamento garante a continuidade dos nossos serviços de monitoramento. 
                        Conte sempre conosco para sua segurança!
                    </p>
                </div>';
                
            default:
                return '';
        }
    }

    /**
     * Template inline caso não exista arquivo
     */
    private function getTemplateInline() {
        return '<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>FATURA</title>
    <link href="https://fonts.googleapis.com/css2?family=Segoe+UI:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
        table { border-collapse: collapse !important; }
        body { height: 100% !important; margin: 0 !important; padding: 0 !important; width: 100% !important; background-color: #f5f5f5; }
        body, p, a, li, td, blockquote { font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif; }
        @media screen and (max-width: 600px) {
            .email-container { width: 100% !important; max-width: 100% !important; }
            .header-padding, .content-padding, .footer-padding { padding-left: 20px !important; padding-right: 20px !important; }
            .invoice-details-table td { display: block !important; width: 100% !important; padding-bottom: 20px !important; }
            .invoice-details-table .detail-group { padding-bottom: 0 !important; }
        }
    </style>
</head>
<body style="margin: 0 !important; padding: 0 !important; background-color: #f5f5f5;">
    <table border="0" cellpadding="0" cellspacing="0" width="100%">
        <tr>
            <td align="center" bgcolor="#f5f5f5">
                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 600px;" class="email-container">
                    <tr>
                        <td align="center" valign="top" style="background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);" bgcolor="#1e3a8a">
                             <div class="header-padding" style="padding: 30px;">
                                 <div style="font-family: \'Segoe UI\', sans-serif; font-size: 28px; font-weight: bold; text-transform: uppercase; letter-spacing: 2px; color: #ffffff; margin-bottom: 5px;">
                                     Tray Sistemas
                                 </div>
                                 <div style="font-family: \'Segoe UI\', sans-serif; font-size: 14px; color: #ffffff; opacity: 0.9;">
                                     Sistemas de Segurança Eletrônica
                                 </div>
                             </div>
                        </td>
                    </tr>
                    <tr>
                        <td bgcolor="#ffffff" style="padding: 0 30px;" class="content-padding">
                            <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                <tr>
                                    <td style="padding: 25px 0; border-left: 4px solid #3b82f6; background-color: #f8fafc; padding-left: 20px;">
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                            <tr>
                                                <td style="font-family: \'Segoe UI\', sans-serif; font-size: 24px; color: #1e3a8a; font-weight: 600; padding-bottom: 20px;">
                                                    [Tipo de Lembrete]
                                                </td>
                                            </tr>
                                            <tr>
                                                <td>
                                                    <table border="0" cellpadding="0" cellspacing="0" width="100%" class="invoice-details-table">
                                                        <tr>
                                                            <td align="left" valign="top" class="detail-group" style="padding-bottom: 15px; width: 50%;">
                                                                <p style="font-family: \'Segoe UI\', sans-serif; font-weight: 600; color: #64748b; font-size: 12px; text-transform: uppercase; margin: 0 0 5px 0;">Cliente</p>
                                                                <p style="font-family: \'Segoe UI\', sans-serif; font-size: 16px; color: #1e293b; margin: 0;">[Nome do Cliente]</p>
                                                            </td>
                                                            <td align="left" valign="top" class="detail-group" style="padding-bottom: 15px; width: 50%;">
                                                                <p style="font-family: \'Segoe UI\', sans-serif; font-weight: 600; color: #64748b; font-size: 12px; text-transform: uppercase; margin: 0 0 5px 0;">Data de Vencimento</p>
                                                                <p style="font-family: \'Segoe UI\', sans-serif; font-size: 16px; color: #1e293b; margin: 0;">[Data de Vencimento do Boleto]</p>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                             <td align="left" valign="top" class="detail-group" colspan="2" style="padding-bottom: 15px;">
                                                                <p style="font-family: \'Segoe UI\', sans-serif; font-weight: 600; color: #64748b; font-size: 12px; text-transform: uppercase; margin: 0 0 5px 0;">Valor do Boleto</p>
                                                                <p style="font-family: \'Segoe UI\', sans-serif; font-size: 16px; color: #1e293b; margin: 0;">[Valor do Boleto]</p>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td bgcolor="#ffffff" class="content-padding" style="padding: 0 30px;">
                            <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                <tr><td align="center" style="padding: 30px 0 10px 0;">[MAIN_CONTENT_BLOCK]</td></tr>
                                <tr><td align="center" style="padding: 0 0 30px 0;">[ATTENTION_BLOCK]</td></tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td bgcolor="#f8fafc" style="border-top: 1px solid #e2e8f0; padding: 30px;" class="content-padding">
                             <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                 <tr>
                                     <td align="center" style="color: #1e3a8a; font-family: \'Segoe UI\', sans-serif; font-size: 18px; font-weight: 600; padding-bottom: 20px;">Precisa de Ajuda?</td>
                                 </tr>
                                 <tr>
                                     <td align="center">
                                         <table border="0" cellspacing="0" cellpadding="0">
                                             <tr><td align="center" style="border-radius: 30px;" bgcolor="#25d366"><a href="https://wa.me/554531323952" target="_blank" style="font-size: 16px; font-family: \'Segoe UI\', sans-serif; color: #ffffff; text-decoration: none; border-radius: 30px; padding: 15px 25px; display: inline-block;">WhatsApp: (45) 3132-3952</a></td></tr>
                                         </table>
                                     </td>
                                 </tr>
                             </table>
                        </td>
                    </tr>
                    <tr>
                         <td bgcolor="#1e293b" style="padding: 25px;" class="footer-padding">
                            <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                <tr>
                                    <td align="center" style="font-family: \'Segoe UI\', sans-serif; font-size: 14px; color: #94a3b8;">
                                        <p style="margin: 0; color: #ffffff; font-weight: 600; margin-bottom: 5px;">Tray Sistemas</p>
                                        <p style="margin: 0; color: #ffffff; font-size: 12px;">Sistemas de Segurança Eletrônica</p>
                                        <p style="margin: 15px 0 0 0; font-size: 12px; color: #94a3b8;">Este é um e-mail automático, não responda esta mensagem.</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    }

    /**
     * Registra notificação no banco
     */
    public function registrarNotificacao($boletoId, $tipo, $destinatario, $assunto, $status = 'enviado') {
        try {
            $db = new Database();
            $sql = "INSERT INTO notificacoes (boleto_id, tipo, meio, destinatario, assunto, status, data_envio) 
                    VALUES (?, ?, 'email', ?, ?, ?, NOW())";
            
            $db->execute($sql, [$boletoId, $tipo, $destinatario, $assunto, $status]);
            
        } catch (Exception $e) {
            Logger::error('EmailService', 'Erro ao registrar notificação', ['error' => $e->getMessage()]);
        }
    }
}