<?php
#!/usr/bin/env php
<?php
/**
 * Script Cron para executar tarefas diárias
 * Sistema de Gestão de Boletos - Tray Sistemas
 * 
 * Para configurar no crontab, adicione:
 * 0 6 * * * /usr/local/bin/php /home/traysist/public_html/boletos/cron/daily_tasks.php >/dev/null 2>&1
 * 
 * Executa todos os dias às 06:00
 */

// Verificar se está sendo executado via CLI
if (php_sapi_name() !== 'cli') {
    die('Este script deve ser executado via linha de comando.');
}

// Definir timezone
date_default_timezone_set('America/Sao_Paulo');

// Incluir dependências
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/BoletoService.php';

// Configurar para não exibir warnings menores
error_reporting(E_ERROR | E_PARSE);

// Classe principal do Cron
class CronDailyTasks {
    private $boletoService;
    private $startTime;
    private $logFile;

    public function __construct() {
        $this->startTime = microtime(true);
        $this->boletoService = new BoletoService();
        $this->logFile = __DIR__ . '/../logs/cron_' . date('Y-m-d') . '.log';
        
        // Criar diretório de logs se não existir
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }

    /**
     * Executar todas as tarefas
     */
    public function executar() {
        $this->log("========================================");
        $this->log("INICIANDO TAREFAS DIÁRIAS - " . date('Y-m-d H:i:s'));
        $this->log("========================================");

        try {
            // 1. Gerar boletos recorrentes
            $this->log("1. Gerando boletos recorrentes...");
            $resultado1 = $this->boletoService->gerarBoletosRecorrentes();
            $this->log("   Resultado: " . ($resultado1['success'] ? "Sucesso" : "Erro"));
            if ($resultado1['success']) {
                $this->log("   Boletos gerados: " . ($resultado1['boletos_gerados'] ?? 0));
            } else {
                $this->log("   Erro: " . ($resultado1['error'] ?? 'Erro desconhecido'));
            }

            // 2. Processar notificações
            $this->log("2. Processando notificações automáticas...");
            $resultado2 = $this->boletoService->processarNotificacoes();
            $this->log("   Resultado: " . ($resultado2['success'] ? "Sucesso" : "Erro"));
            if ($resultado2['success']) {
                $this->log("   Notificações enviadas: " . ($resultado2['notificacoes_enviadas'] ?? 0));
            } else {
                $this->log("   Erro: " . ($resultado2['error'] ?? 'Erro desconhecido'));
            }

            // 3. Atualizar status dos boletos
            $this->log("3. Atualizando status dos boletos...");
            $resultado3 = $this->boletoService->atualizarStatusBoletos();
            $this->log("   Resultado: " . ($resultado3['success'] ? "Sucesso" : "Erro"));
            if ($resultado3['success']) {
                $this->log("   Boletos atualizados: " . ($resultado3['boletos_atualizados'] ?? 0));
            } else {
                $this->log("   Erro: " . ($resultado3['error'] ?? 'Erro desconhecido'));
            }

            // 4. Atualizar status de boletos vencidos
            $this->log("4. Atualizando boletos vencidos...");
            $resultado4 = $this->atualizarBoletosVencidos();
            $this->log("   Boletos marcados como vencidos: " . $resultado4);

            // 5. Limpeza de logs antigos
            $this->log("5. Limpando logs antigos...");
            $resultado5 = $this->limparLogsAntigos();
            $this->log("   Logs removidos: " . $resultado5);

            // 6. Estatísticas do dia
            $this->log("6. Gerando estatísticas...");
            $this->gerarEstatisticas();

        } catch (Exception $e) {
            $this->log("ERRO CRÍTICO: " . $e->getMessage());
            Logger::error('CronJob', 'Erro crítico no cron job', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }

        $tempoExecucao = round(microtime(true) - $this->startTime, 2);
        $this->log("========================================");
        $this->log("TAREFAS CONCLUÍDAS - Tempo: {$tempoExecucao}s");
        $this->log("========================================\n");
    }

    /**
     * Atualizar boletos vencidos
     */
    private function atualizarBoletosVencidos() {
        try {
            $db = new Database();
            
            $sql = "UPDATE boletos 
                    SET status = 'vencido' 
                    WHERE status = 'pendente' 
                    AND data_vencimento < CURDATE()";
            
            $count = $db->execute($sql);
            
            if ($count > 0) {
                Logger::info('CronJob', 'Boletos marcados como vencidos', ['count' => $count]);
            }
            
            return $count;
            
        } catch (Exception $e) {
            $this->log("   Erro ao atualizar boletos vencidos: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Limpar logs antigos (manter apenas 30 dias)
     */
    private function limparLogsAntigos() {
        $logsRemovidos = 0;
        $logDir = __DIR__ . '/../logs/';
        
        if (!is_dir($logDir)) {
            return 0;
        }

        try {
            $dataLimite = strtotime('-30 days');
            $arquivos = glob($logDir . '*.log');
            
            foreach ($arquivos as $arquivo) {
                $nomeArquivo = basename($arquivo);
                
                // Verificar se é um log de cron com data
                if (preg_match('/^cron_(\d{4}-\d{2}-\d{2})\.log$/', $nomeArquivo, $matches)) {
                    $dataArquivo = strtotime($matches[1]);
                    
                    if ($dataArquivo && $dataArquivo < $dataLimite) {
                        if (unlink($arquivo)) {
                            $logsRemovidos++;
                        }
                    }
                }
            }
            
        } catch (Exception $e) {
            $this->log("   Erro ao limpar logs: " . $e->getMessage());
        }
        
        return $logsRemovidos;
    }

    /**
     * Gerar estatísticas do sistema
     */
    private function gerarEstatisticas() {
        try {
            $stats = $this->boletoService->obterEstatisticas(30);
            
            $this->log("   === ESTATÍSTICAS DOS ÚLTIMOS 30 DIAS ===");
            $this->log("   Total de boletos: " . $stats['total_boletos']);
            $this->log("   Boletos pagos: " . $stats['boletos_pagos']);
            $this->log("   Boletos pendentes: " . $stats['boletos_pendentes']);
            $this->log("   Boletos vencidos: " . $stats['boletos_vencidos']);
            $this->log("   Valor total: R$ " . number_format($stats['valor_total'], 2, ',', '.'));
            $this->log("   Valor recebido: R$ " . number_format($stats['valor_recebido'], 2, ',', '.'));
            $this->log("   Valor pendente: R$ " . number_format($stats['valor_pendente'], 2, ',', '.'));
            $this->log("   Taxa de pagamento: " . $stats['taxa_pagamento'] . "%");
            
        } catch (Exception $e) {
            $this->log("   Erro ao gerar estatísticas: " . $e->getMessage());
        }
    }

    /**
     * Registrar log
     */
    private function log($mensagem) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$mensagem}" . PHP_EOL;
        
        // Escrever no arquivo
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
        
        // Também exibir no console
        echo $logMessage;
    }

    /**
     * Verificar se já foi executado hoje
     */
    public function jaExecutadoHoje() {
        $db = new Database();
        
        try {
            $resultado = $db->fetch(
                "SELECT COUNT(*) as count FROM logs 
                 WHERE modulo = 'CronJob' 
                 AND DATE(data_cadastro) = CURDATE() 
                 AND mensagem LIKE '%TAREFAS CONCLUÍDAS%'"
            );
            
            return $resultado['count'] > 0;
            
        } catch (Exception $e) {
            return false;
        }
    }
}

// Executar se chamado diretamente
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    echo "=== TRAY SISTEMAS - CRON JOB DIÁRIO ===\n";
    echo "Data/Hora: " . date('Y-m-d H:i:s') . "\n\n";
    
    $cron = new CronDailyTasks();
    
    // Verificar se já foi executado hoje (opcional - remover se quiser forçar execução)
    if ($cron->jaExecutadoHoje()) {
        echo "⚠️  Cron job já foi executado hoje.\n";
        echo "Para forçar nova execução, use: php " . __FILE__ . " --force\n\n";
        
        // Permitir forçar execução
        if (!isset($argv[1]) || $argv[1] !== '--force') {
            exit(1);
        } else {
            echo "🔄 Forçando nova execução...\n\n";
        }
    }
    
    $cron->executar();
    
    echo "\n✅ Cron job concluído com sucesso!\n";
    exit(0);
}