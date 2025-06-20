<?php
// conexao.php
// Arquivo de conexão PDO para o sistema de lojas

// ======= CONFIGURAÇÕES DO BANCO DE DADOS =======
$host = 'localhost';         // Host do MySQL (geralmente localhost)
$dbname = 'dv_sistemas';   // Troque para o nome real do seu banco
$user = 'root';     // Troque para o usuário do MySQL
$pass = '';
// ======= NÃO ALTERE ABAIXO SEM SABER =======
$dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Exceções em caso de erro
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Retorna arrays associativos
    PDO::ATTR_EMULATE_PREPARES   => false,                  // Usa prepared statements reais
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    // Em produção, não exiba detalhes do erro!
    die('Erro ao conectar ao banco de dados: ' . $e->getMessage());
}
?>