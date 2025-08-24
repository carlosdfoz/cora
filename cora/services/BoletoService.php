<?php
/**
 * Serviço de Boletos
 * Sistema de Gestão de Boletos - Tray Sistemas
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../api/CoraAPI.php';
require_once __DIR__ . '/EmailService.php';

class BoletoService {
    private $db;
    private $coraAPI;
    private $emailService;

    public function __construct() {
        $this->db = new Database();
        $this->coraAPI = new CoraAPI();
        $this->emailService = new EmailService();
    }

    /**
     * Gera boletos recorrentes baseado nas assinaturas ativas
     */
    public function gerarBoletosRecorrentes() {
        try {
            $dataAtual = date('Y-m-d');
            $mesAtual = date('Y-m');
            
            // Buscar assinaturas ativas que precisam gerar boleto
            $sql = "SELECT a.*, c.nome as cliente_nome, c.email as cliente_email, 
                           c.telefone, c.cpf_cnpj, c.endereco, c.cep, c.cidade, c.estado,
                           p.nome as plano_nome, p.valor as plano_valor, p.periodicidade
                    FROM assinaturas a
                    INNER JOIN clientes c ON a.cliente_id = c.id
                    INNER JOIN planos p ON a.plano_id = p.id
                    WHERE a.status = 'ativa'
                    AND c.status = 'ativo'
                    AND (a.data_fim IS NULL OR a.data_fim >= ?)
                    AND NOT EXISTS (
                        SELECT 1 FROM boletos b 
                        WHERE b.assinatura_id = a.id 
                        AND DATE_FORMAT(b.data_vencimento, '%Y-%m') = ?
                        AND b.status != 'cancelado'
                    )";

            $assinaturas = $this->db->fetchAll($sql, [$dataAtual, $mesAtual]);
            $boletosGerados = 0;

            foreach ($assinaturas as $assinatura) {
                $dataVencimento = $this->calcularProximaDataVencimento($assinatura);
                
                if ($dataVencimento) {
                    $resultado = $this->criarBoleto([
                        'assinatura_id' => $assinatura['id'],
                        'cliente_id' => $assinatura['cliente_id'],
                        'plano_id' => $assinatura['plano_id'],
                        'valor' => $assinatura['valor_personalizado'] ?: $assinatura['plano_valor'],
                        'data_vencimento' => $dataVencimento,
                        'descricao' => "Mensalidade {$assinatura['plano_nome']} - " . date('m/Y', strtotime($dataVencimento)),
                        'tipo' => 'recorrente'
                    ]);

                    if ($resultado['success']) {
                        $boletosGerados++;
                        Logger::success('BoletoService', 'Boleto recorrente gerado', [
                            'assinatura_id' => $assinatura['id'],
                            'cliente' => $assinatura['cliente_nome']
                        ]);
                    }
                }
            }

            Logger::info('BoletoService', "Processo de geração concluído: {$boletosGerados} boletos gerados");
            return ['success' => true, 'boletos_gerados' => $boletosGerados];

        } catch (Exception $e) {
            Logger::error('BoletoService', 'Erro na geração de boletos recorrentes', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Cria um boleto (recorrente ou avulso)
     */
    public function criarBoleto($dados) {
        try {
            $this->db->beginTransaction();

            // Gerar nosso número único
            $nossoNumero = CoraAPI::gerarNossoNumero();
            
            // Buscar dados completos do cliente se não fornecidos
            if (!isset($dados['cliente_nome'])) {
                $cliente = $this->buscarCliente($dados['cliente_id']);
                if (!$cliente) {
                    throw new Exception('Cliente não encontrado');
                }
            } else {
                $cliente = $dados; // Dados já fornecidos
            }

            // Preparar dados para a API da Cora
            $dadosBoleto = [
                'nosso_numero' => $nossoNumero,
                'valor' => $dados['valor'],
                'data_vencimento' => $dados['data_vencimento'],
                'cliente_nome' => $cliente['cliente_nome'] ?? $cliente['nome'],
                'cliente_email' => $cliente['cliente_email'] ?? $cliente['email'],
                'cliente_telefone' => $cliente['telefone'] ?? '',
                'cliente_documento' => $cliente['cpf_cnpj'],
                'cliente_endereco' => $cliente['endereco'] ?? '',
                'cliente_cidade' => $cliente['cidade'] ?? '',
                'cliente_estado' => $cliente['estado'] ?? '',
                'cliente_cep' => $cliente['cep'] ?? '',
                'descricao' => $dados['descricao'] ?? 'Serviços de Monitoramento - Tray Sistemas',
                'multa' => $dados['multa'] ?? 2.00,
                'juros_dia' => $dados['juros_dia'] ?? 0.0333,
                'desconto' => $dados['desconto'] ?? 0.00,
                'data_limite_desconto' => $dados['data_limite_desconto'] ?? null
            ];

            // Criar boleto na API da Cora
            $resultadoCora = $this->coraAPI->criarBoleto($dadosBoleto);
            
            if (!$resultadoCora['success']) {
                throw new Exception('Erro na API Cora: ' . $resultadoCora['error']);
            }

            // Salvar boleto no banco de dados
            $sql = "INSERT INTO boletos (
                        assinatura_id, cliente_id, plano_id, codigo_boleto, nosso_numero,
                        valor, descricao, data_vencimento, data_emissao, status,
                        linha_digitavel, codigo_barras, qr_code, url_boleto, tipo,
                        multa, juros_dia, desconto, data_limite_desconto
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendente', ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $boletoId = $this->db->execute($sql, [
                $dados['assinatura_id'] ?? null,
                $dados['cliente_id'],
                $dados['plano_id'] ?? null,
                $resultadoCora['data']['id'] ?? $nossoNumero,
                $nossoNumero,
                $dados['valor'],
                $dadosBoleto['descricao'],
                $dados['data_vencimento'],
                date('Y-m-d'),
                $resultadoCora['linha_digitavel'],
                $resultadoCora['codigo_barras'],
                $resultadoCora['qr_code'],
                $resultadoCora['url_boleto'],
                $dados['tipo'] ?? 'avulso',
                $dadosBoleto['multa'],
                $dadosBoleto['juros_dia'],
                $dadosBoleto['desconto'],
                $dadosBoleto['data_limite_desconto']
            ]);

            if (!$boletoId) {
                $boletoId = $this->db->lastInsertId();
            }

            $this->db->commit();

            Logger::success('BoletoService', 'Boleto criado com sucesso', [
                'boleto_id' => $boletoId,
                'nosso_numero' => $nossoNumero,
                'cliente_id' => $dados['cliente_id']
            ]);

            return [
                'success' => true,
                'boleto_id' => $boletoId,
                'nosso_numero' => $nossoNumero,
                'data' => $resultadoCora['data']
            ];

        } catch (Exception $e) {
            $this->db->rollback();
            Logger::error('BoletoService', 'Erro ao criar boleto', [
                'error' => $e->getMessage(),
                'dados' => $dados
            ]);
            
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Processa notificações automáticas
     */
    public function processarNotificacoes() {
        try {
            $hoje = date('Y-m-d');
            $processados = 0;

            // 1. Lembretes antes do vencimento
            $diasLembrete = explode(',', Config::get('dias_lembrete', '7,3,1'));
            
            foreach ($diasLembrete as $dias) {
                $dataLembrete = date('Y-m-d', strtotime("+{$dias} days"));
                $lembretes = $this->buscarBoletosParaLembrete($dataLembrete, $dias);
                
                foreach ($lembretes as $boleto) {
                    $resultado = $this->emailService->enviarLembrete($boleto, $dias);
                    if ($resultado['success']) {
                        $this->emailService->registrarNotificacao($boleto['id'], 'lembrete', $boleto['cliente_email'], "Lembrete: Boleto vence em {$dias} " . ($dias == 1 ? 'dia' : 'dias'));
                        $processados++;
                    }
                }
            }

            // 2. Notificação de vencimento (hoje)
            $vencimentosHoje = $this->buscarBoletosVencimentoHoje();
            
            foreach ($vencimentosHoje as $boleto) {
                $resultado = $this->emailService->enviarVencimento($boleto);
                if ($resultado['success']) {
                    $this->emailService->registrarNotificacao($boleto['id'], 'vencimento', $boleto['cliente_email'], 'URGENTE: Boleto vence hoje');
                    $processados++;
                }
            }

            // 3. Cobranças por atraso
            $diasAtraso = explode(',', Config::get('dias_atraso', '5,10,15,30'));
            
            foreach ($diasAtraso as $dias) {
                $dataAtraso = date('Y-m-d', strtotime("-{$dias} days"));
                $atrasados = $this->buscarBoletosAtrasados($dataAtraso, $dias);
                
                foreach ($atrasados as $boleto) {
                    $resultado = $this->emailService->enviarCobranca($boleto, $dias);
                    if ($resultado['success']) {
                        $this->emailService->registrarNotificacao($boleto['id'], 'atraso', $boleto['cliente_email'], "COBRANÇA: Boleto em atraso há {$dias} " . ($dias == 1 ? 'dia' : 'dias'));
                        
                        // Incrementar tentativas de cobrança
                        $this->db->execute("UPDATE boletos SET tentativas_cobranca = tentativas_cobranca + 1 WHERE id = ?", [$boleto['id']]);
                        $processados++;
                    }
                }
            }

            Logger::info('BoletoService', "Notificações processadas: {$processados}");
            return ['success' => true, 'notificacoes_enviadas' => $processados];

        } catch (Exception $e) {
            Logger::error('BoletoService', 'Erro no processamento de notificações', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Atualiza status dos boletos consultando a API
     */
    public function atualizarStatusBoletos() {
        try {
            // Buscar boletos pendentes e vencidos para atualização
            $sql = "SELECT * FROM boletos 
                    WHERE status IN ('pendente', 'vencido') 
                    AND nosso_numero IS NOT NULL 
                    ORDER BY data_cadastro DESC 
                    LIMIT 100";
            
            $boletos = $this->db->fetchAll($sql);
            $atualizados = 0;

            foreach ($boletos as $boleto) {
                $resultado = $this->coraAPI->consultarBoleto($boleto['nosso_numero']);
                
                if ($resultado['success']) {
                    $novoStatus = $resultado['status'];
                    
                    if ($novoStatus !== $boleto['status']) {
                        // Atualizar status no banco
                        $this->db->execute("UPDATE boletos SET status = ? WHERE id = ?", [$novoStatus, $boleto['id']]);
                        
                        // Se foi pago, registrar data de pagamento e enviar confirmação
                        if ($novoStatus === 'pago' && $boleto['status'] !== 'pago') {
                            $this->db->execute("UPDATE boletos SET data_pagamento = NOW() WHERE id = ?", [$boleto['id']]);
                            
                            // Enviar email de confirmação
                            $dadosBoleto = array_merge($boleto, ['status' => 'pago']);
                            $this->emailService->enviarConfirmacaoPagamento($dadosBoleto);
                            $this->emailService->registrarNotificacao($boleto['id'], 'pagamento', $boleto['cliente_email'] ?? '', 'Pagamento Confirmado');
                        }
                        
                        $atualizados++;
                    }
                }
                
                // Pequena pausa para não sobrecarregar a API
                usleep(200000); // 0.2 segundos
            }

            Logger::info('BoletoService', "Status dos boletos atualizado: {$atualizados}");
            return ['success' => true, 'boletos_atualizados' => $atualizados];

        } catch (Exception $e) {
            Logger::error('BoletoService', 'Erro na atualização de status', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Calcula próxima data de vencimento baseada na periodicidade
     */
    private function calcularProximaDataVencimento($assinatura) {
        $diaVencimento = $assinatura['dia_vencimento'];
        $periodicidade = $assinatura['periodicidade'];
        $mesAtual = date('m');
        $anoAtual = date('Y');

        switch ($periodicidade) {
            case 'mensal':
                $proximaData = date('Y-m-d', mktime(0, 0, 0, $mesAtual, $diaVencimento, $anoAtual));
                // Se já passou do dia de vencimento no mês atual, vai para o próximo mês
                if ($proximaData < date('Y-m-d')) {
                    $proximaData = date('Y-m-d', mktime(0, 0, 0, $mesAtual + 1, $diaVencimento, $anoAtual));
                }
                break;
                
            case 'bimestral':
                $proximaData = date('Y-m-d', mktime(0, 0, 0, $mesAtual + 2, $diaVencimento, $anoAtual));
                break;
                
            case 'trimestral':
                $proximaData = date('Y-m-d', mktime(0, 0, 0, $mesAtual + 3, $diaVencimento, $anoAtual));
                break;
                
            case 'semestral':
                $proximaData = date('Y-m-d', mktime(0, 0, 0, $mesAtual + 6, $diaVencimento, $anoAtual));
                break;
                
            case 'anual':
                $proximaData = date('Y-m-d', mktime(0, 0, 0, $mesAtual, $diaVencimento, $anoAtual + 1));
                break;
                
            default:
                return null;
        }

        return $proximaData;
    }

    /**
     * Busca boletos para envio de lembrete
     */
    private function buscarBoletosParaLembrete($dataVencimento, $dias) {
        $sql = "SELECT b.*, c.nome as cliente_nome, c.email as cliente_email
                FROM boletos b
                INNER JOIN clientes c ON b.cliente_id = c.id
                WHERE b.data_vencimento = ?
                AND b.status = 'pendente'
                AND c.email IS NOT NULL
                AND c.email != ''
                AND NOT EXISTS (
                    SELECT 1 FROM notificacoes n 
                    WHERE n.boleto_id = b.id 
                    AND n.tipo = 'lembrete'
                    AND DATE(n.data_envio) = CURDATE()
                )";

        return $this->db->fetchAll($sql, [$dataVencimento]);
    }

    /**
     * Busca boletos que vencem hoje
     */
    private function buscarBoletosVencimentoHoje() {
        $sql = "SELECT b.*, c.nome as cliente_nome, c.email as cliente_email
                FROM boletos b
                INNER JOIN clientes c ON b.cliente_id = c.id
                WHERE b.data_vencimento = CURDATE()
                AND b.status = 'pendente'
                AND c.email IS NOT NULL
                AND c.email != ''
                AND NOT EXISTS (
                    SELECT 1 FROM notificacoes n 
                    WHERE n.boleto_id = b.id 
                    AND n.tipo = 'vencimento'
                    AND DATE(n.data_envio) = CURDATE()
                )";

        return $this->db->fetchAll($sql);
    }

    /**
     * Busca boletos atrasados para cobrança
     */
    private function buscarBoletosAtrasados($dataVencimento, $dias) {
        $sql = "SELECT b.*, c.nome as cliente_nome, c.email as cliente_email
                FROM boletos b
                INNER JOIN clientes c ON b.cliente_id = c.id
                WHERE b.data_vencimento = ?
                AND b.status IN ('pendente', 'vencido')
                AND c.email IS NOT NULL
                AND c.email != ''
                AND (b.tentativas_cobranca < 3 OR b.tentativas_cobranca IS NULL)
                AND NOT EXISTS (
                    SELECT 1 FROM notificacoes n 
                    WHERE n.boleto_id = b.id 
                    AND n.tipo = 'atraso'
                    AND DATE(n.data_envio) = CURDATE()
                )";

        return $this->db->fetchAll($sql, [$dataVencimento]);
    }

    /**
     * Busca dados completos do cliente
     */
    private function buscarCliente($clienteId) {
        return $this->db->fetch("SELECT * FROM clientes WHERE id = ?", [$clienteId]);
    }

    /**
     * Lista boletos com filtros
     */
    public function listarBoletos($filtros = []) {
        $where = [];
        $params = [];

        $sql = "SELECT b.*, c.nome as cliente_nome, c.email as cliente_email, 
                       p.nome as plano_nome
                FROM boletos b
                INNER JOIN clientes c ON b.cliente_id = c.id
                LEFT JOIN planos p ON b.plano_id = p.id";

        if (!empty($filtros['status'])) {
            $where[] = "b.status = ?";
            $params[] = $filtros['status'];
        }

        if (!empty($filtros['cliente_id'])) {
            $where[] = "b.cliente_id = ?";
            $params[] = $filtros['cliente_id'];
        }

        if (!empty($filtros['data_inicio'])) {
            $where[] = "b.data_vencimento >= ?";
            $params[] = $filtros['data_inicio'];
        }

        if (!empty($filtros['data_fim'])) {
            $where[] = "b.data_vencimento <= ?";
            $params[] = $filtros['data_fim'];
        }

        if (!empty($filtros['tipo'])) {
            $where[] = "b.tipo = ?";
            $params[] = $filtros['tipo'];
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $sql .= " ORDER BY b.data_vencimento DESC";

        if (!empty($filtros['limit'])) {
            $sql .= " LIMIT " . intval($filtros['limit']);
        }

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Busca boleto por ID
     */
    public function buscarBoleto($id) {
        $sql = "SELECT b.*, c.nome as cliente_nome, c.email as cliente_email, 
                       c.telefone, c.cpf_cnpj, c.endereco, c.cidade, c.estado, c.cep,
                       p.nome as plano_nome
                FROM boletos b
                INNER JOIN clientes c ON b.cliente_id = c.id
                LEFT JOIN planos p ON b.plano_id = p.id
                WHERE b.id = ?";

        return $this->db->fetch($sql, [$id]);
    }

    /**
     * Cancela um boleto
     */
    public function cancelarBoleto($id, $motivo = null) {
        try {
            $boleto = $this->buscarBoleto($id);
            
            if (!$boleto) {
                return ['success' => false, 'error' => 'Boleto não encontrado'];
            }

            if ($boleto['status'] === 'pago') {
                return ['success' => false, 'error' => 'Boleto já foi pago e não pode ser cancelado'];
            }

            // Cancelar na API da Cora se tiver nosso número
            if ($boleto['nosso_numero']) {
                $resultadoCora = $this->coraAPI->cancelarBoleto($boleto['nosso_numero']);
                
                if (!$resultadoCora['success']) {
                    Logger::warning('BoletoService', 'Erro ao cancelar na API Cora', [
                        'boleto_id' => $id,
                        'error' => $resultadoCora['error']
                    ]);
                }
            }

            // Atualizar status no banco
            $this->db->execute("UPDATE boletos SET status = 'cancelado' WHERE id = ?", [$id]);

            Logger::success('BoletoService', 'Boleto cancelado', [
                'boleto_id' => $id,
                'motivo' => $motivo
            ]);

            return ['success' => true, 'message' => 'Boleto cancelado com sucesso'];

        } catch (Exception $e) {
            Logger::error('BoletoService', 'Erro ao cancelar boleto', [
                'boleto_id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Relatório de estatísticas
     */
    public function obterEstatisticas($periodo = 30) {
        $dataInicio = date('Y-m-d', strtotime("-{$periodo} days"));
        
        $stats = [
            'total_boletos' => 0,
            'boletos_pagos' => 0,
            'boletos_pendentes' => 0,
            'boletos_vencidos' => 0,
            'valor_total' => 0,
            'valor_recebido' => 0,
            'valor_pendente' => 0,
            'taxa_pagamento' => 0
        ];

        // Estatísticas gerais
        $sql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'pago' THEN 1 ELSE 0 END) as pagos,
                    SUM(CASE WHEN status = 'pendente' THEN 1 ELSE 0 END) as pendentes,
                    SUM(CASE WHEN status = 'vencido' THEN 1 ELSE 0 END) as vencidos,
                    SUM(valor) as valor_total,
                    SUM(CASE WHEN status = 'pago' THEN valor ELSE 0 END) as valor_recebido,
                    SUM(CASE WHEN status IN ('pendente', 'vencido') THEN valor ELSE 0 END) as valor_pendente
                FROM boletos 
                WHERE data_emissao >= ?";

        $result = $this->db->fetch($sql, [$dataInicio]);

        if ($result) {
            $stats['total_boletos'] = $result['total'];
            $stats['boletos_pagos'] = $result['pagos'];
            $stats['boletos_pendentes'] = $result['pendentes'];
            $stats['boletos_vencidos'] = $result['vencidos'];
            $stats['valor_total'] = $result['valor_total'];
            $stats['valor_recebido'] = $result['valor_recebido'];
            $stats['valor_pendente'] = $result['valor_pendente'];
            
            if ($stats['total_boletos'] > 0) {
                $stats['taxa_pagamento'] = round(($stats['boletos_pagos'] / $stats['total_boletos']) * 100, 2);
            }
        }

        return $stats;
    }
}