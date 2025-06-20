<?php
session_start();
if (!isset($_SESSION['usuario_logado']) || !$_SESSION['usuario_logado']) {
    http_response_code(403);
    exit('Acesso negado');
}
require_once 'conexao.php';

$cnpj_loja = $_SESSION['cnpj_loja_logada'] ?? '';
$data_inicial = $_GET['data_inicial'] ?? '';
$data_final = $_GET['data_final'] ?? '';

if (!$data_inicial || !$data_final) {
    exit('<div style="color:red;text-align:center;">Período inválido.</div>');
}

// Buscar id_loja pelo CNPJ
$stmt = $pdo->prepare("SELECT id_loja FROM lojas WHERE cnpj = :cnpj");
$stmt->execute(['cnpj' => $cnpj_loja]);
$loja = $stmt->fetch(PDO::FETCH_ASSOC);
$id_loja = $loja['id_loja'] ?? null;
if (!$id_loja) {
    exit('<div style="color:red;text-align:center;">Loja não encontrada.</div>');
}

// Buscar vendas do período
$stmt = $pdo->prepare("
    SELECT v.id_venda, v.data_hora_venda, v.valor_total_venda, v.status_venda,
           COALESCE(c.nome_razao_social, 'Consumidor Final') AS cliente
    FROM vendas v
    LEFT JOIN clientes c ON v.id_cliente = c.id_cliente
    WHERE v.cnpj_loja = :cnpj_loja
      AND DATE(v.data_hora_venda) BETWEEN :data_inicial AND :data_final
    ORDER BY v.data_hora_venda DESC
");
$stmt->execute([
    'cnpj_loja' => $cnpj_loja,
    'data_inicial' => $data_inicial,
    'data_final' => $data_final
]);
$vendas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Totais
$total_vendas = 0;
$total_pedidos = count($vendas);
foreach ($vendas as $v) {
    $total_vendas += $v['valor_total_venda'];
}
?>
<div style="padding:20px;">
    <h2 style="margin-bottom:10px;">Relatório de Vendas</h2>
    <div style="margin-bottom:20px;">
        <strong>Período:</strong> <?= htmlspecialchars(date('d/m/Y', strtotime($data_inicial))) ?> até <?= htmlspecialchars(date('d/m/Y', strtotime($data_final))) ?><br>
        <strong>Total de Pedidos:</strong> <?= $total_pedidos ?><br>
        <strong>Total Vendido:</strong> R$ <?= number_format($total_vendas, 2, ',', '.') ?>
    </div>
    <table style="width:100%; border-collapse:collapse;">
        <thead>
            <tr style="background:#f0f0f0;">
                <th style="padding:8px; border:1px solid #eee;">ID</th>
                <th style="padding:8px; border:1px solid #eee;">Data/Hora</th>
                <th style="padding:8px; border:1px solid #eee;">Cliente</th>
                <th style="padding:8px; border:1px solid #eee;">Valor</th>
                <th style="padding:8px; border:1px solid #eee;">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if($total_pedidos > 0): ?>
                <?php foreach($vendas as $v): ?>
                    <tr>
                        <td style="padding:8px; border:1px solid #eee;">#<?= $v['id_venda'] ?></td>
                        <td style="padding:8px; border:1px solid #eee;"><?= date('d/m/Y H:i', strtotime($v['data_hora_venda'])) ?></td>
                        <td style="padding:8px; border:1px solid #eee;"><?= htmlspecialchars($v['cliente']) ?></td>
                        <td style="padding:8px; border:1px solid #eee;">R$ <?= number_format($v['valor_total_venda'], 2, ',', '.') ?></td>
                        <td style="padding:8px; border:1px solid #eee;"><?= htmlspecialchars($v['status_venda']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="5" style="text-align:center; color:#888;">Nenhuma venda encontrada no período.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>


