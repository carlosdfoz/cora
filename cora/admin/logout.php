<?php
session_start();

// Destruir todas as sessões
session_unset();
session_destroy();

// Limpar cookie da sessão se existir
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Redirecionar para login
header('Location: login.php?logout=success');
exit;
?>