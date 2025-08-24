<?php
/**
 * Script de diagnóstico completo para API Cora
 * Execute este arquivo para diagnosticar problemas de autenticação
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html>
<html>
<head>
    <title>Diagnóstico API Cora</title>
    <meta charset='UTF-8'>
    <style>
        body { font-family: monospace; background: #1a1a1a; color: #0f0; padding: 20px; }
        .section { margin: 20px 0; padding: 15px; border: 1px solid #0f0; }
        .error { color: #f00; }
        .warning { color: #ff0; }
        .success { color: #0f0; }
        .info { color: #0ff; }
        pre { background: #000; padding: 10px; overflow-x: auto; }
        h2 { color: #0ff; border-bottom: 2px solid #0ff; }
    </style>
</head>
<body>
<h1>🔍 Diagnóstico Completo - API Cora</h1>";

// Configurações
$client_id = 'int-6BzCd5SjaNQZIecvRzbHxR';
$cert_path = '/home/traysist/public_html/boletos/certificates/certificate.pem';
$key_path = '/home/traysist/public_html/boletos/certificates/private-key.key';
$base_url = 'https://matls-clients.api.cora.com.br';

// ==================================================
// 1. VERIFICAR CERTIFICADOS
// ==================================================
echo "<div class='section'>";
echo "<h2>1. Verificação de Certificados</h2>";

// Verificar existência
if (file_exists($cert_path)) {
    echo "<span class='success'>✅ Certificado encontrado</span><br>";
    
    // Verificar conteúdo
    $cert_content = file_get_contents($cert_path);
    if (strpos($cert_content, '-----BEGIN CERTIFICATE-----') !== false) {
        echo "<span class='success'>✅ Certificado tem formato PEM válido</span><br>";
        
        // Verificar validade do certificado
        $cert_info = openssl_x509_parse($cert_content);
        if ($cert_info) {
            echo "<span class='info'>📅 Válido de: " . date('Y-m-d H:i:s', $cert_info['validFrom_time_t']) . "</span><br>";
            echo "<span class='info'>📅 Válido até: " . date('Y-m-d H:i:s', $cert_info['validTo_time_t']) . "</span><br>";
            
            if (time() > $cert_info['validTo_time_t']) {
                echo "<span class='error'>❌ CERTIFICADO EXPIRADO!</span><br>";
            } elseif (time() < $cert_info['validFrom_time_t']) {
                echo "<span class='error'>❌ CERTIFICADO AINDA NÃO É VÁLIDO!</span><br>";
            } else {
                echo "<span class='success'>✅ Certificado dentro da validade</span><br>";
            }
            
            echo "<span class='info'>📝 Subject: " . $cert_info['subject']['CN'] . "</span><br>";
            echo "<span class='info'>📝 Issuer: " . $cert_info['issuer']['CN'] . "</span><br>";
        }
    } else {
        echo "<span class='error'>❌ Certificado não tem formato PEM válido</span><br>";
    }
} else {
    echo "<span class='error'>❌ Certificado não encontrado em: $cert_path</span><br>";
}

if (file_exists($key_path)) {
    echo "<span class='success'>✅ Chave privada encontrada</span><br>";
    
    $key_content = file_get_contents($key_path);
    if (strpos($key_content, '-----BEGIN') !== false) {
        echo "<span class='success'>✅ Chave privada tem formato PEM válido</span><br>";
    }
} else {
    echo "<span class='error'>❌ Chave privada não encontrada em: $key_path</span><br>";
}

// Verificar se certificado e chave correspondem
if (file_exists($cert_path) && file_exists($key_path)) {
    $cert_content = file_get_contents($cert_path);
    $key_content = file_get_contents($key_path);
    
    $cert_resource = openssl_x509_read($cert_content);
    $key_resource = openssl_pkey_get_private($key_content);
    
    if ($cert_resource && $key_resource) {
        if (openssl_x509_check_private_key($cert_resource, $key_resource)) {
            echo "<span class='success'>✅ Certificado e chave privada correspondem</span><br>";
        } else {
            echo "<span class='error'>❌ Certificado e chave privada NÃO correspondem!</span><br>";
        }
    }
}

echo "</div>";

// ==================================================
// 2. TESTE DE AUTENTICAÇÃO - MÉTODO 1 (OAuth2)
// ==================================================
echo "<div class='section'>";
echo "<h2>2. Teste de Autenticação OAuth2</h2>";

$ch = curl_init();

$post_data = [
    'grant_type' => 'client_credentials',
    'client_id' => $client_id
];

echo "<span class='info'>📤 Enviando para: $base_url/token</span><br>";
echo "<span class='info'>📋 Client ID: $client_id</span><br>";
echo "<pre>POST Data: " . print_r($post_data, true) . "</pre>";

curl_setopt_array($ch, [
    CURLOPT_URL => $base_url . '/token',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($post_data),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json'
    ],
    CURLOPT_SSLCERT => $cert_path,
    CURLOPT_SSLKEY => $key_path,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_VERBOSE => true,
    CURLOPT_STDERR => fopen('php://temp', 'w+'),
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
$curl_info = curl_getinfo($ch);

// Capturar verbose output
rewind($curl_info['stderr'] ?? fopen('php://temp', 'r'));
$verbose = stream_get_contents($curl_info['stderr'] ?? fopen('php://temp', 'r'));

echo "<span class='info'>📡 HTTP Code: $http_code</span><br>";

if ($curl_error) {
    echo "<span class='error'>❌ cURL Error: $curl_error</span><br>";
} else {
    if ($http_code == 200) {
        echo "<span class='success'>✅ Autenticação bem-sucedida!</span><br>";
        $result = json_decode($response, true);
        if (isset($result['access_token'])) {
            echo "<span class='success'>🔑 Token obtido: " . substr($result['access_token'], 0, 20) . "...</span><br>";
            echo "<span class='info'>⏱️ Expira em: " . ($result['expires_in'] ?? 'N/A') . " segundos</span><br>";
        }
    } else {
        echo "<span class='error'>❌ Falha na autenticação</span><br>";
    }
    
    echo "<h3>Resposta:</h3>";
    echo "<pre>";
    $json = json_decode($response, true);
    if ($json) {
        echo json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } else {
        echo htmlspecialchars($response);
    }
    echo "</pre>";
}

curl_close($ch);
echo "</div>";

// ==================================================
// 3. TESTE ALTERNATIVO - MUTUAL TLS
// ==================================================
echo "<div class='section'>";
echo "<h2>3. Teste com mTLS (Mutual TLS)</h2>";

// Teste alternativo sem OAuth, apenas com certificados
$ch = curl_init();

echo "<span class='info'>📤 Testando endpoint com mTLS puro...</span><br>";

curl_setopt_array($ch, [
    CURLOPT_URL => $base_url . '/healthcheck', // Endpoint de teste
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json'
    ],
    CURLOPT_SSLCERT => $cert_path,
    CURLOPT_SSLKEY => $key_path,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_TIMEOUT => 10,
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);

if ($curl_error) {
    echo "<span class='warning'>⚠️ Erro no healthcheck: $curl_error</span><br>";
} else {
    echo "<span class='info'>📡 HTTP Code: $http_code</span><br>";
    if ($http_code == 200 || $http_code == 404) {
        echo "<span class='success'>✅ Conexão mTLS estabelecida</span><br>";
    }
}

curl_close($ch);
echo "</div>";

// ==================================================
// 4. VERIFICAR CONFIGURAÇÃO DO SISTEMA
// ==================================================
echo "<div class='section'>";
echo "<h2>4. Configuração do Sistema</h2>";

// Verificar OpenSSL
$openssl_version = OPENSSL_VERSION_TEXT;
echo "<span class='info'>🔐 OpenSSL: $openssl_version</span><br>";

// Verificar cURL
$curl_version = curl_version();
echo "<span class='info'>🌐 cURL: " . $curl_version['version'] . "</span><br>";
echo "<span class='info'>📝 SSL: " . $curl_version['ssl_version'] . "</span><br>";

// Verificar suporte a protocolos
$protocols = $curl_version['protocols'];
if (in_array('https', $protocols)) {
    echo "<span class='success'>✅ HTTPS suportado</span><br>";
}

echo "</div>";

// ==================================================
// 5. POSSÍVEIS SOLUÇÕES
// ==================================================
echo "<div class='section'>";
echo "<h2>5. Diagnóstico e Soluções</h2>";

if ($http_code == 401) {
    echo "<span class='warning'>⚠️ Erro 401 - Possíveis causas:</span><br>";
    echo "<ul>";
    echo "<li>Client ID incorreto (verifique: <code>$client_id</code>)</li>";
    echo "<li>Certificados expirados ou inválidos</li>";
    echo "<li>Certificados não correspondem ao Client ID</li>";
    echo "<li>Ambiente incorreto (produção vs sandbox)</li>";
    echo "</ul>";
    
    echo "<span class='info'>💡 Recomendações:</span><br>";
    echo "<ol>";
    echo "<li>Confirme o Client ID com a Cora</li>";
    echo "<li>Verifique se os certificados são os corretos para este Client ID</li>";
    echo "<li>Confirme se está usando a URL correta (produção: matls-clients.api.cora.com.br)</li>";
    echo "<li>Se necessário, solicite novos certificados à Cora</li>";
    echo "</ol>";
}

echo "</div>";

// ==================================================
// 6. TESTE COM DIFERENTES CONFIGURAÇÕES
// ==================================================
echo "<div class='section'>";
echo "<h2>6. Teste com Configurações Alternativas</h2>";

// Testar sem verificação SSL (apenas para debug!)
echo "<h3>Teste sem verificação SSL (DEBUG APENAS):</h3>";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $base_url . '/token',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($post_data),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json'
    ],
    CURLOPT_SSLCERT => $cert_path,
    CURLOPT_SSLKEY => $key_path,
    CURLOPT_SSL_VERIFYPEER => false, // APENAS PARA DEBUG!
    CURLOPT_SSL_VERIFYHOST => 0,      // APENAS PARA DEBUG!
    CURLOPT_TIMEOUT => 30,
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

echo "<span class='info'>📡 HTTP Code (sem verificação SSL): $http_code</span><br>";

if ($http_code == 200) {
    echo "<span class='warning'>⚠️ Funciona sem verificação SSL - problema pode ser com CA certificates</span><br>";
} else {
    echo "<span class='info'>ℹ️ Mesmo resultado - problema não é com verificação SSL</span><br>";
}

curl_close($ch);

echo "</div>";

echo "<div class='section'>";
echo "<h2>📞 Próximos Passos</h2>";
echo "<ol>";
echo "<li>Se o certificado estiver expirado, solicite um novo à Cora</li>";
echo "<li>Confirme o Client ID correto com a documentação da Cora</li>";
echo "<li>Verifique se está usando o ambiente correto (produção vs sandbox)</li>";
echo "<li>Entre em contato com o suporte da Cora se o problema persistir</li>";
echo "</ol>";
echo "</div>";

echo "</body></html>";
?>