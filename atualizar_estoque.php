<?php
session_start();
require_once 'conexao.php';

$response = ['estoqueAtualizado' => false];
$cnpj_loja = $_SESSION['cnpj_loja_logada'] ?? '';

if ($cnpj_loja) {
    $stmt = $pdo->prepare("SELECT id_produto, estoque_atual FROM produtos WHERE id_loja = 
                          (SELECT id_loja FROM lojas WHERE cnpj = :cnpj)");
    $stmt->execute([':cnpj' => $cnpj_loja]);
    $response['produtos'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $response['estoqueAtualizado'] = true;
}

header('Content-Type: application/json');
echo json_encode($response);
?>
