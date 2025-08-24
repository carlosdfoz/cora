<?php
/**
 * API da Cora para geração de boletos
 * Sistema de Gestão de Boletos - Tray Sistemas
 */

require_once __DIR__ . '/../config/database.php';

class CoraAPI {
    private $client_id;
    private $certificate_path;
    private $private_key_path;
    private $base_url = 'https://matls-clients.api.cora.com.br'; // URL de PRODUÇÃO
    
    public function __construct() {
        $this->client_id = Config::get('cora_client_id', 'int-6BzCd5SjaNQZIecvRzbHxR');
        // Usar os nomes exatos dos arquivos enviados pela Cora
        $this->certificate_path = '/home/traysist/public_html/boletos/certificates/certificate.pem';
        $this->private_key_path = '/home/traysist/public_html/boletos/certificates/private-key.key';
    }

    /**
     * Gera token de acesso OAuth2
     */
    private function getAccessToken() {
        try {
            $url = $this->base_url . '/token';
            
            $data = [
                'grant_type' => 'client_credentials',
                'client_id' => $this->client_id
            ];

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($data),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Accept: application/json'
                ],
                CURLOPT_SSLCERT => $this->certificate_path,
                CURLOPT_SSLKEY => $this->private_key_path,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_USERAGENT => 'TraySystemsAPI/1.0'
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            
            curl_close($ch);
            
            if ($curl_error) {
                throw new Exception('Erro cURL: ' . $curl_error);
            }

            if ($http_code !== 200) {
                // Log detalhado do erro
                Logger::error('CoraAPI', 'Erro na autenticação', [
                    'http_code' => $http_code,
                    'response' => $response,
                    'certificate_exists' => file_exists($this->certificate_path),
                    'private_key_exists' => file_exists($this->private_key_path),
                    'client_id' => $this->client_id
                ]);
                throw new Exception("Erro na autenticação: HTTP {$http_code} - {$response}");
            }

            $result = json_decode($response, true);
            
            if (!isset($result['access_token'])) {
                throw new Exception('Token de acesso não retornado pela API');
            }

            Logger::success('CoraAPI', 'Token de acesso obtido com sucesso');
            return $result['access_token'];
            
        } catch (Exception $e) {
            Logger::error('CoraAPI', 'Erro ao obter token de acesso', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Cria um boleto na API da Cora
     */
    public function criarBoleto($dadosBoleto) {
        try {
            $token = $this->getAccessToken();
            $url = $this->base_url . '/invoices';

            // Preparar dados do boleto conforme API da Cora
            $payload = [
                'invoice_id' => $dadosBoleto['nosso_numero'],
                'amount' => (int)($dadosBoleto['valor'] * 100), // Valor em centavos
                'due_date' => $dadosBoleto['data_vencimento'],
                'customer' => [
                    'name' => $dadosBoleto['cliente_nome'],
                    'document' => $this->limparDocumento($dadosBoleto['cliente_documento']),
                    'email' => $dadosBoleto['cliente_email'] ?? null,
                    'phone' => $dadosBoleto['cliente_telefone'] ?? null,
                    'address' => [
                        'street' => $dadosBoleto['cliente_endereco'] ?? '',
                        'city' => $dadosBoleto['cliente_cidade'] ?? '',
                        'state' => $dadosBoleto['cliente_estado'] ?? '',
                        'zip_code' => $dadosBoleto['cliente_cep'] ?? ''
                    ]
                ],
                'description' => $dadosBoleto['descricao'] ?? 'Serviços de Monitoramento - Tray Sistemas',
                'instructions' => 'Pagamento referente aos serviços de monitoramento eletrônico.',
                'fine_percentage' => $dadosBoleto['multa'] ?? 2.00,
                'interest_per_day' => $dadosBoleto['juros_dia'] ?? 0.0333,
                'discount_amount' => isset($dadosBoleto['desconto']) ? (int)($dadosBoleto['desconto'] * 100) : 0,
                'discount_due_date' => $dadosBoleto['data_limite_desconto'] ?? null
            ];

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Authorization: Bearer ' . $token
                ],
                CURLOPT_SSLCERT => $this->certificate_path,
                CURLOPT_SSLKEY => $this->private_key_path,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_TIMEOUT => 30
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            
            curl_close($ch);
            
            if ($curl_error) {
                throw new Exception('Erro cURL: ' . $curl_error);
            }

            if ($http_code !== 201 && $http_code !== 200) {
                throw new Exception("Erro ao criar boleto: HTTP {$http_code} - {$response}");
            }

            $result = json_decode($response, true);
            
            if (!$result) {
                throw new Exception('Resposta inválida da API Cora');
            }

            Logger::success('CoraAPI', 'Boleto criado com sucesso', [
                'nosso_numero' => $dadosBoleto['nosso_numero'],
                'valor' => $dadosBoleto['valor']
            ]);

            return [
                'success' => true,
                'data' => $result,
                'codigo_barras' => $result['bar_code'] ?? '',
                'linha_digitavel' => $result['digitable_line'] ?? '',
                'qr_code' => $result['qr_code'] ?? '',
                'url_boleto' => $result['pdf_url'] ?? ''
            ];
            
        } catch (Exception $e) {
            Logger::error('CoraAPI', 'Erro ao criar boleto', [
                'error' => $e->getMessage(),
                'nosso_numero' => $dadosBoleto['nosso_numero'] ?? 'N/A'
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Consulta status de um boleto
     */
    public function consultarBoleto($nossoNumero) {
        try {
            $token = $this->getAccessToken();
            $url = $this->base_url . "/invoices/{$nossoNumero}";

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Authorization: Bearer ' . $token
                ],
                CURLOPT_SSLCERT => $this->certificate_path,
                CURLOPT_SSLKEY => $this->private_key_path,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_TIMEOUT => 30
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            
            curl_close($ch);
            
            if ($curl_error) {
                throw new Exception('Erro cURL: ' . $curl_error);
            }

            if ($http_code !== 200) {
                throw new Exception("Erro ao consultar boleto: HTTP {$http_code} - {$response}");
            }

            $result = json_decode($response, true);
            
            return [
                'success' => true,
                'data' => $result,
                'status' => $this->mapearStatusBoleto($result['status'] ?? 'pending')
            ];
            
        } catch (Exception $e) {
            Logger::error('CoraAPI', 'Erro ao consultar boleto', [
                'error' => $e->getMessage(),
                'nosso_numero' => $nossoNumero
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Cancela um boleto
     */
    public function cancelarBoleto($nossoNumero) {
        try {
            $token = $this->getAccessToken();
            $url = $this->base_url . "/invoices/{$nossoNumero}/cancel";

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Authorization: Bearer ' . $token
                ],
                CURLOPT_SSLCERT => $this->certificate_path,
                CURLOPT_SSLKEY => $this->private_key_path,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_TIMEOUT => 30
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            
            curl_close($ch);
            
            if ($curl_error) {
                throw new Exception('Erro cURL: ' . $curl_error);
            }

            if ($http_code !== 200) {
                throw new Exception("Erro ao cancelar boleto: HTTP {$http_code} - {$response}");
            }

            Logger::success('CoraAPI', 'Boleto cancelado com sucesso', ['nosso_numero' => $nossoNumero]);
            
            return [
                'success' => true,
                'message' => 'Boleto cancelado com sucesso'
            ];
            
        } catch (Exception $e) {
            Logger::error('CoraAPI', 'Erro ao cancelar boleto', [
                'error' => $e->getMessage(),
                'nosso_numero' => $nossoNumero
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Mapear status da Cora para status interno
     */
    private function mapearStatusBoleto($statusCora) {
        $statusMap = [
            'pending' => 'pendente',
            'paid' => 'pago',
            'overdue' => 'vencido',
            'cancelled' => 'cancelado'
        ];

        return $statusMap[$statusCora] ?? 'pendente';
    }

    /**
     * Remove pontuação do documento
     */
    private function limparDocumento($documento) {
        return preg_replace('/[^0-9]/', '', $documento);
    }

    /**
     * Gera nosso número único
     */
    public static function gerarNossoNumero() {
        return 'TS' . date('Ymd') . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Valida se os certificados existem
     */
    public function validarCertificados() {
        $errors = [];
        
        if (!file_exists($this->certificate_path)) {
            $errors[] = "Certificado não encontrado: {$this->certificate_path}";
        }
        
        if (!file_exists($this->private_key_path)) {
            $errors[] = "Chave privada não encontrada: {$this->private_key_path}";
        }
        
        return empty($errors) ? true : $errors;
    }

    /**
     * Listar boletos com filtros
     */
    public function listarBoletos($filtros = []) {
        try {
            $token = $this->getAccessToken();
            $url = $this->base_url . '/invoices';
            
            // Adicionar parâmetros de query se houver filtros
            if (!empty($filtros)) {
                $url .= '?' . http_build_query($filtros);
            }

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Authorization: Bearer ' . $token
                ],
                CURLOPT_SSLCERT => $this->certificate_path,
                CURLOPT_SSLKEY => $this->private_key_path,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_TIMEOUT => 30
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            
            curl_close($ch);
            
            if ($curl_error) {
                throw new Exception('Erro cURL: ' . $curl_error);
            }

            if ($http_code !== 200) {
                throw new Exception("Erro ao listar boletos: HTTP {$http_code} - {$response}");
            }

            $result = json_decode($response, true);
            
            return [
                'success' => true,
                'data' => $result
            ];
            
        } catch (Exception $e) {
            Logger::error('CoraAPI', 'Erro ao listar boletos', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Obter webhook URL para configuração
     */
    public function configurarWebhook($webhookUrl) {
        try {
            $token = $this->getAccessToken();
            $url = $this->base_url . '/webhooks';

            $payload = [
                'url' => $webhookUrl,
                'events' => [
                    'invoice.paid',
                    'invoice.overdue',
                    'invoice.cancelled'
                ]
            ];

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Authorization: Bearer ' . $token
                ],
                CURLOPT_SSLCERT => $this->certificate_path,
                CURLOPT_SSLKEY => $this->private_key_path,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_TIMEOUT => 30
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            
            curl_close($ch);
            
            if ($curl_error) {
                throw new Exception('Erro cURL: ' . $curl_error);
            }

            if ($http_code !== 201 && $http_code !== 200) {
                throw new Exception("Erro ao configurar webhook: HTTP {$http_code} - {$response}");
            }

            Logger::success('CoraAPI', 'Webhook configurado com sucesso', [
                'url' => $webhookUrl
            ]);
            
            return [
                'success' => true,
                'message' => 'Webhook configurado com sucesso'
            ];
            
        } catch (Exception $e) {
            Logger::error('CoraAPI', 'Erro ao configurar webhook', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
?>