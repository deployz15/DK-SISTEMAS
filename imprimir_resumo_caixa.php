<?php
session_start();
if (!isset($_SESSION['usuario_logado'])) {
    header('Location: login.php');
    exit;
}

require_once 'conexao.php';

// Agora aceita id_caixa como parâmetro principal
if (!isset($_GET['id_caixa'])) {
    die("Parâmetro id_caixa ausente para geração do resumo.");
}

$id_caixa = intval($_GET['id_caixa']);

// Busca o caixa pelo id
$stmt = $pdo->prepare("SELECT * FROM caixa WHERE id = ?");
$stmt->execute([$id_caixa]);
$caixa = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$caixa) die("Caixa não encontrado.");

// Extrai os parâmetros necessários do caixa
$data_abertura = $caixa['data_abertura'];
$data_fechamento = $caixa['data_fechamento'] ?? date('Y-m-d H:i:s');
$cnpj_loja = $caixa['cnpj_loja'];

// Loja
$stmt = $pdo->prepare("SELECT nome_fantasia, endereco, cidade, telefone, email FROM lojas WHERE cnpj = ?");
$stmt->execute([$cnpj_loja]);
$loja = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$loja) die("Loja não encontrada.");

// Vendas (resumo)
$stmt = $pdo->prepare("SELECT 
    COUNT(*) AS total_vendas,
    SUM(valor_total_venda) AS valor_total,
    SUM(CASE WHEN status_venda = 'CANCELADA' THEN 1 ELSE 0 END) AS vendas_canceladas
    FROM vendas 
    WHERE cnpj_loja = ?
    AND data_hora_venda BETWEEN ? AND ?");
$stmt->execute([$cnpj_loja, $data_abertura, $data_fechamento]);
$resumo_vendas = $stmt->fetch(PDO::FETCH_ASSOC);

// Formas de pagamento
$stmt = $pdo->prepare("SELECT 
    pv.forma_pagamento,
    SUM(pv.valor_pago) AS total_forma
    FROM pagamentos_venda pv
    INNER JOIN vendas v ON pv.id_venda = v.id_venda
    WHERE v.cnpj_loja = ?
    AND v.data_hora_venda BETWEEN ? AND ?
    AND v.status_venda = 'CONCLUIDA'
    GROUP BY pv.forma_pagamento");
$stmt->execute([$cnpj_loja, $data_abertura, $data_fechamento]);
$formas_pagamento = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Últimas 10 vendas
$stmt = $pdo->prepare("SELECT 
    v.id_venda,
    v.data_hora_venda,
    v.usuario_vendedor,
    v.valor_total_venda,
    GROUP_CONCAT(CONCAT(pv.forma_pagamento, ': ', FORMAT(pv.valor_pago, 2, 'pt_BR')) SEPARATOR ' | ') AS pagamentos
    FROM vendas v
    LEFT JOIN pagamentos_venda pv ON v.id_venda = pv.id_venda
    WHERE v.cnpj_loja = ?
    AND v.data_hora_venda BETWEEN ? AND ?
    GROUP BY v.id_venda
    ORDER BY v.data_hora_venda DESC
    LIMIT 10");
$stmt->execute([$cnpj_loja, $data_abertura, $data_fechamento]);
$ultimas_vendas = $stmt->fetchAll(PDO::FETCH_ASSOC);

function formatMoney($value) {
    if(is_null($value) || $value === '') $value = 0;
    return 'R$ ' . number_format((float)$value, 2, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Resumo do Caixa - <?= htmlspecialchars($loja['nome_fantasia']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&family=Roboto&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #304ffe;
            --gray: #f4f6fa;
            --border: #e0e3ea;
            --success: #11cc7b;
            --danger: #e85050;
        }
        html,body { 
            height: 100%; 
            margin: 0;
            padding: 0;
            background: var(--gray);
        }
        body {
            font-family: 'Montserrat', 'Roboto', Arial, sans-serif;
            color: #23305c;
        }
        .container {
            max-width: 480px;
            margin: 12px auto 12px;
            background: #fff;
            border-radius: 13px;
            box-shadow: 0 2px 18px #cfd8dc33;
            padding: 18px 10px 16px 10px;
            page-break-inside: avoid;
        }
        .brand-bar {
            width: 80px; height: 4px;
            margin: 0 auto 10px auto;
            background: linear-gradient(90deg, var(--primary), #6c63ff, #42a5f5);
            border-radius: 8px;
        }
        .header {
            text-align: center;
            margin-bottom: 10px;
            border-bottom: 2px solid var(--primary);
            padding-bottom: 6px;
        }
        .header h1 {
            margin: 0;
            font-size: 1.25rem;
            color: var(--primary);
            font-weight: 700;
        }
        .header h2 {
            margin: 7px 0 0;
            font-size: 1.05rem;
            font-weight: 400;
            color: #2a3a5a;
        }
        .info-box, .resumo-content {
            background: #f9fbff;
            border-radius: 6px;
            border: 1px solid var(--border);
            padding: 10px 13px;
            margin-bottom: 12px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 4px;
            font-size: .99rem;
        }
        .info-label {
            font-weight: 600;
            color: #23305c;
        }
        .resumo-section {
            margin-bottom: 9px;
        }
        .resumo-title {
            background: var(--primary);
            color: white;
            padding: 6px 13px;
            margin: 0 0 0 0;
            font-size: .97rem;
            border-radius: 5px 5px 0 0;
            font-weight: 500;
            letter-spacing: .3px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: .97rem;
            margin-bottom: 2px;
        }
        table th {
            background: #e3e7fd;
            color: #23305c;
            font-weight: 700;
            text-align: left;
            padding: 4px 3px;
            border: 1px solid var(--border);
        }
        table td {
            padding: 4px 3px;
            border: 1px solid var(--border);
        }
        .total-row {
            font-weight: bold;
            background: #e3e7fd;
            color: var(--primary);
        }
        .footer {
            margin-top: 10px;
            text-align: center;
            font-size: .97rem;
            color: #7b869c;
            border-top: 1.5px solid var(--border);
            padding-top: 6px;
        }
        .footer p {
            margin: 2px 0;
        }
        .print-btn {
            margin-top: 5px;
            padding: 7px 16px;
            font-size: .97rem;
            border: none;
            border-radius: 4px;
            background: var(--primary);
            color: #fff;
            cursor: pointer;
            box-shadow: 0 2px 8px #e3e7fd44;
            transition: background 0.2s;
        }
        .print-btn:hover {
            background: #1a237e;
        }
        .tag-ok {
            color: var(--success);
            font-weight: bold;
        }
        .tag-cancel {
            color: var(--danger);
            font-weight: bold;
        }
        @media print {
            html, body { background: #fff !important; }
            .container {
                max-width: 100%!important;
                margin: 0!important;
                box-shadow: none!important;
                border-radius: 0!important;
                padding: 0 0 0 0!important;
            }
            .print-btn, .no-print { display: none !important; }
            .brand-bar {
                margin-bottom: 4px!important;
            }
            .footer {
                margin-top: 8px!important;
                padding-top: 2px!important;
            }
            .resumo-section, .info-box, .resumo-content {
                margin-bottom: 4px!important;
            }
            .header {
                margin-bottom: 4px!important;
                padding-bottom: 2px!important;
            }
        }
    </style>
    <script>
        window.onload = function() { window.print(); };
        function printAgain() { window.print(); }
    </script>
</head>
<body>
    <div class="container">
        <div class="brand-bar"></div>
        <div class="header">
            <h1><?= htmlspecialchars($loja['nome_fantasia']) ?></h1>
            <h2>Resumo do Caixa</h2>
        </div>

        <div class="info-box">
            <div class="info-row">
                <span class="info-label">Abertura:</span>
                <span><?= date('d/m/Y H:i', strtotime($caixa['data_abertura'])) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Fechamento:</span>
                <span><?= date('d/m/Y H:i', strtotime($data_fechamento)) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Usuário:</span>
                <span><?= htmlspecialchars($caixa['usuario']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Abertura R$:</span>
                <span><?= formatMoney($caixa['valor_abertura']) ?></span>
            </div>
        </div>

        <div class="resumo-section">
            <div class="resumo-title">Resumo de Vendas</div>
            <div class="resumo-content">
                <div class="info-row">
                    <span class="info-label">Total Vendas:</span>
                    <span><?= $resumo_vendas['total_vendas'] ?? 0 ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Canceladas:</span>
                    <span class="<?= ($resumo_vendas['vendas_canceladas'] ?? 0) > 0 ? 'tag-cancel' : 'tag-ok' ?>">
                        <?= $resumo_vendas['vendas_canceladas'] ?? 0 ?>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">Total Vendido:</span>
                    <span><?= formatMoney($resumo_vendas['valor_total'] ?? 0) ?></span>
                </div>
            </div>
        </div>

        <div class="resumo-section">
            <div class="resumo-title">Formas de Pagamento</div>
            <div class="resumo-content">
                <table>
                    <thead>
                        <tr>
                            <th>Forma</th>
                            <th>Valor</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $total_recebido = 0; foreach($formas_pagamento as $forma): $total_recebido += (float)$forma['total_forma']; ?>
                            <tr>
                                <td><?= htmlspecialchars($forma['forma_pagamento']) ?></td>
                                <td><?= formatMoney($forma['total_forma']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td>Total Recebido</td>
                            <td><?= formatMoney($total_recebido) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="resumo-section">
            <div class="resumo-title">Últimas Vendas</div>
            <div class="resumo-content">
                <table>
                    <thead>
                        <tr>
                            <th>Nº</th>
                            <th>Data/Hora</th>
                            <th>Vendedor</th>
                            <th>Valor</th>
                            <th>Pagamento</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($ultimas_vendas): foreach($ultimas_vendas as $venda): ?>
                            <tr>
                                <td><?= $venda['id_venda'] ?></td>
                                <td><?= date('d/m H:i', strtotime($venda['data_hora_venda'])) ?></td>
                                <td><?= htmlspecialchars($venda['usuario_vendedor']) ?></td>
                                <td><?= formatMoney($venda['valor_total_venda']) ?></td>
                                <td><?= htmlspecialchars($venda['pagamentos']) ?></td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="5" style="text-align:center;color:#888;">Nenhuma venda encontrada no período.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="resumo-section">
            <div class="resumo-title">Resumo Financeiro</div>
            <div class="resumo-content">
                <div class="info-row">
                    <span class="info-label">Abertura:</span>
                    <span><?= formatMoney($caixa['valor_abertura']) ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Recebido:</span>
                    <span><?= formatMoney($total_recebido) ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Fechamento Estimado:</span>
                    <span><?= formatMoney($caixa['valor_abertura'] + $total_recebido) ?></span>
                </div>
            </div>
        </div>

        <div class="footer">
            <p>
                <?= date('d/m/Y H:i') ?>
                <?php if (isset($_SESSION['nome_usuario_logado'])): ?>
                    - <?= htmlspecialchars($_SESSION['nome_usuario_logado']) ?>
                <?php endif; ?>
            </p>
            <p><?= htmlspecialchars($loja['nome_fantasia']) ?> - CNPJ: <?= $cnpj_loja ?></p>
            <p><?= htmlspecialchars($loja['endereco']) ?> - <?= htmlspecialchars($loja['cidade']) ?></p>
            <p>
                <?php if (!empty($loja['telefone'])): ?>
                    Tel: <?= htmlspecialchars($loja['telefone']) ?><?php if (!empty($loja['email'])): ?> | <?php endif; ?>
                <?php endif; ?>
                <?php if (!empty($loja['email'])): ?>
                    E-mail: <?= htmlspecialchars($loja['email']) ?>
                <?php endif; ?>
            </p>
            <button class="print-btn no-print" onclick="printAgain()">Imprimir Resumo</button>
        </div>
    </div>
</body>
</html>